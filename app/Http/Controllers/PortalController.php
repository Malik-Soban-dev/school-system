<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(private SchoolPortal $portal) {}

    public function meta(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'name', 'username', 'roles', 'tutorials']),
            'modules' => $this->portal->modules($request->user()),
            'options' => $this->portal->options($request->user()),
            'settings' => (object) DB::table('school_settings')->whereIn('key', ['school_name', 'currency', 'timezone'])->pluck('value', 'key')->all(),
            'canManage' => $this->portal->admin($request->user()),
            'today' => $this->portal->today(),
        ]);
    }

    public function index(Request $request, string $module): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json($this->portal->listing($module, $request->user(), (string) $request->input('search', ''), (int) $request->input('page', 1)));
    }

    public function save(Request $request, string $module, ?int $id = null): JsonResponse
    {
        $id = $this->portal->save($module, $request->user(), $request->all(), $id);

        return response()->json(['id' => $id, 'message' => 'Record saved.']);
    }

    public function attendanceBatch(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'teacher']), 403);
        $data = $request->validate(['records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'min:1', 'distinct'],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])]]);
        DB::transaction(function () use ($request, $data): void {
            $ids = array_column($data['records'], 'student_id');
            $students = DB::table('school_students')->whereIn('id', $ids)->where('status', 'active');
            if (! $this->portal->admin($request->user())) {
                $students->whereIn('class_id', DB::table('school_teacher_assignments')->where('user_id', $request->user()->id)->where('status', 'active')->select('class_id'));
            }
            abort_unless($students->count() === count($ids), 403);
            $date = $this->portal->today();
            $before = DB::table('school_attendance')->whereIn('student_id', $ids)->where('date', $date)->get()->keyBy('student_id');
            $now = now();
            $rows = array_map(fn (array $record): array => [...$record, 'date' => $date, 'created_at' => $now, 'updated_at' => $now], $data['records']);
            DB::table('school_attendance')->upsert($rows, ['student_id', 'date'], ['status', 'updated_at']);
            $saved = DB::table('school_attendance')->whereIn('student_id', $ids)->where('date', $date)->get();
            DB::table('school_audit')->insert($saved->map(fn ($row): array => ['user_id' => $request->user()->id,
                'module' => 'attendance', 'record_id' => $row->id, 'action' => 'class_attendance_saved',
                'changes' => json_encode(['before' => $before->get($row->student_id), 'after' => $row]), 'created_at' => $now])->all());
        });

        return response()->json(['message' => 'Attendance saved for '.count($data['records']).' students.']);
    }

    public function attendanceRoster(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'teacher']), 403);
        $data = $request->validate(['class_id' => ['required', 'integer', 'exists:school_classes,id']]);
        if (! $this->portal->admin($request->user())) {
            abort_unless(DB::table('school_teacher_assignments')->where('user_id', $request->user()->id)->where('class_id', $data['class_id'])->where('status', 'active')->exists(), 403);
        }
        $rows = DB::table('school_students')->where('class_id', $data['class_id'])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return response()->json(['rows' => $rows]);
    }

    public function tutorial(Request $request): JsonResponse
    {
        $data = $request->validate(['module' => ['required', Rule::in(['overview', 'people', 'settings', ...array_keys(config('school-modules'))])]]);
        $completed = array_unique([...($request->user()->tutorials ?? []), $data['module']]);
        $request->user()->forceFill(['tutorials' => array_values($completed)])->save();

        return response()->json(['message' => 'Guide preference saved.']);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:150'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all'],
        ]);
        DB::transaction(function () use ($data, $request): void {
            foreach ($data as $key => $value) {
                DB::table('school_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
            }
            DB::table('school_audit')->insert(['user_id' => $request->user()->id, 'module' => 'settings', 'record_id' => 0, 'action' => 'updated', 'changes' => json_encode($data), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School settings saved.']);
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);

        return response()->json(['users' => User::select('id', 'name', 'username', 'email', 'roles', 'is_active')->orderBy('name')->paginate(30)]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        abort_if($user->id === $request->user()->id, 403, 'You cannot change your own access.');
        abort_if(in_array('owner', $user->roles ?? [], true), 403, 'Owner access must be managed privately.');
        abort_if(in_array('admin', $user->roles ?? [], true) && ! $request->user()->hasRole('owner'), 403);
        $allowed = ['teacher', 'parent', 'student', 'accountant'];
        if ($request->user()->hasRole('owner')) {
            $allowed[] = 'admin';
        }
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in($allowed), 'distinct'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($user, $data, $request): void {
            $before = $user->only(['roles', 'is_active']);
            $user->forceFill($data)->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('school_audit')->insert(['user_id' => $request->user()->id, 'module' => 'users', 'record_id' => $user->id, 'action' => 'access_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Access updated and previous sessions revoked.']);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);

        return response()->json(['rows' => DB::table('school_audit')->leftJoin('users', 'users.id', '=', 'school_audit.user_id')
            ->select('school_audit.id', 'school_audit.module', 'school_audit.record_id', 'school_audit.action', 'school_audit.created_at', 'users.name')
            ->orderByDesc('school_audit.id')->paginate(30)]);
    }

    public function report(Request $request, string $module, int $id): View
    {
        abort_unless(in_array($module, ['payments', 'invoices', 'payroll', 'grades']), 404);
        $row = $this->portal->query($module, $request->user())->where('id', $id)->first();
        abort_unless($row, 404);
        $definition = $this->portal->definition($module);
        $extra = [];
        if ($module === 'invoices') {
            $extra['Paid'] = (int) DB::table('school_payments')->where('invoice_id', $id)->sum('amount');
            $extra['Balance'] = $row->amount - $extra['Paid'];
        }
        if ($module === 'payroll') {
            $extra['Net pay'] = $row->basic + $row->allowances - $row->deductions;
        }

        return view('reports.record', ['row' => (array) $row, 'definition' => $definition,
            'options' => $this->portal->options($request->user()), 'extra' => $extra,
            'school' => DB::table('school_settings')->where('key', 'school_name')->value('value') ?: 'School System',
            'currency' => DB::table('school_settings')->where('key', 'currency')->value('value') ?: '']);
    }
}

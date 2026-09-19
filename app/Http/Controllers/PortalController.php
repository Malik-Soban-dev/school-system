<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolPortal;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(private SchoolPortal $portal) {}

    public function meta(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        $settingsQuery = DB::table('school_settings')->where('school_id', $tenant->id())->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family']);
        if (DB::table('schools')->count() === 1) {
            $settingsQuery->orWhere(function ($query): void {
                $query->whereNull('school_id')->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family']);
            });
        }
        $settings = $settingsQuery->pluck('value', 'key')->all();

        return response()->json([
            'user' => [...$request->user()->only(['id', 'name', 'username', 'roles', 'tutorials']), 'interface_preferences' => $request->user()->interfacePreferences()],
            'modules' => $this->portal->modules($request->user()),
            'options' => $this->portal->options($request->user()),
            'settings' => (object) $settings,
            'overview' => $this->portal->overview($request->user()),
            'canManage' => $this->portal->admin($request->user()),
            'today' => today($settings['timezone'] ?? config('app.timezone'))->toDateString(),
            'contexts' => $this->availableContexts($request->user()),
            'current_context' => ['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId()],
        ]);
    }

    public function contexts(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);

        return response()->json(['contexts' => $this->availableContexts($request->user()), 'current' => ['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId()]]);
    }

    public function switchContext(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer']]);
        $context = DB::table('school_user_branches as access')->join('school_user as membership', function ($join): void {
            $join->on('membership.school_id', '=', 'access.school_id')->on('membership.user_id', '=', 'access.user_id');
        })->join('schools', 'schools.id', '=', 'access.school_id')->join('school_branches as branch', 'branch.id', '=', 'access.branch_id')->where('access.user_id', $request->user()->id)->where('access.school_id', $data['school_id'])->where('access.branch_id', $data['branch_id'])->where('access.status', 'active')->where('membership.status', 'active')->where('schools.status', 'active')->where('branch.status', 'active')->first(['access.school_id', 'access.branch_id']);
        abort_unless($context, 403, 'You do not have access to that school branch.');
        $request->session()->put(['school_id' => (int) $context->school_id, 'branch_id' => (int) $context->branch_id]);
        app(TenantContext::class)->set((int) $context->school_id, (int) $context->branch_id);

        return response()->json(['message' => 'Workspace branch switched.', 'current' => ['school_id' => (int) $context->school_id, 'branch_id' => (int) $context->branch_id]]);
    }

    public function index(Request $request, string $module): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'month' => ['nullable', 'date_format:Y-m']]);

        return response()->json($this->portal->listing($module, $request->user(), (string) $request->input('search', ''), (int) $request->input('page', 1), $request->input('month')));
    }

    public function save(Request $request, string $module, ?int $id = null): JsonResponse
    {
        $id = $this->portal->save($module, $request->user(), $request->all(), $id);

        return response()->json(['id' => $id, 'message' => 'Record saved.']);
    }

    public function attendanceBatch(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'teacher']), 403);
        $tenant = app(TenantContext::class);
        $data = $request->validate(['records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'min:1', 'distinct'],
            'records.*.status' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])]]);
        DB::transaction(function () use ($request, $data, $tenant): void {
            $ids = array_column($data['records'], 'student_id');
            $students = $tenant->table('school_students')->whereIn('id', $ids)->where('status', 'active');
            if (! $this->portal->admin($request->user())) {
                $students->whereIn('class_id', $tenant->table('school_teacher_assignments')->where('user_id', $request->user()->id)->where('status', 'active')->select('class_id'));
            }
            abort_unless($students->count() === count($ids), 403);
            $date = $this->portal->today();
            $before = $tenant->table('school_attendance')->whereIn('student_id', $ids)->where('date', $date)->get()->keyBy('student_id');
            $now = now();
            $rows = array_map(fn (array $record): array => [...$record, 'school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'date' => $date, 'created_at' => $now, 'updated_at' => $now], $data['records']);
            $tenant->table('school_attendance')->upsert($rows, ['school_id', 'student_id', 'date'], ['status', 'updated_at']);
            $saved = $tenant->table('school_attendance')->whereIn('student_id', $ids)->where('date', $date)->get();
            DB::table('school_audit')->insert($saved->map(fn ($row): array => ['school_id' => $tenant->id(), 'user_id' => $request->user()->id,
                'module' => 'attendance', 'record_id' => $row->id, 'action' => 'class_attendance_saved',
                'changes' => json_encode(['before' => $before->get($row->student_id), 'after' => $row]), 'created_at' => $now])->all());
            $events = $saved->map(fn ($row): array => ['module' => 'attendance', 'record_id' => $row->id,
                'event_key' => 'attendance:'.$row->id.':'.hash('sha256', $row->date.':'.$row->status), 'created_at' => $now])->all();
            DB::table('school_notification_events')->insertOrIgnore(collect($events)->map(fn (array $event): array => [...$event, 'school_id' => $tenant->id()])->all());
        });

        return response()->json(['message' => 'Attendance saved for '.count($data['records']).' students.']);
    }

    public function attendanceRoster(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'teacher']), 403);
        $tenant = app(TenantContext::class);
        $data = $request->validate(['class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->where(function ($query) use ($tenant): void {
            $query->where('school_id', $tenant->id())->where(function ($branchQuery) use ($tenant): void {
                $branchQuery->where('branch_id', $tenant->branchId());
                if (app()->environment('testing')) {
                    $branchQuery->orWhereNull('branch_id');
                }
            });
        })]]);
        if (! $this->portal->admin($request->user())) {
            abort_unless($tenant->table('school_teacher_assignments')->where('user_id', $request->user()->id)->where('class_id', $data['class_id'])->where('status', 'active')->exists(), 403);
        }
        $rows = $tenant->table('school_students')->where('class_id', $data['class_id'])->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return response()->json(['rows' => $rows]);
    }

    public function tutorial(Request $request): JsonResponse
    {
        $data = $request->validate(['module' => ['required', Rule::in(['overview', 'people', 'invitations', 'notifications', 'settings', ...array_keys(config('school-modules'))])]]);
        $completed = array_unique([...($request->user()->tutorials ?? []), $data['module']]);
        $request->user()->forceFill(['tutorials' => array_values($completed)])->save();

        return response()->json(['message' => 'Guide preference saved.']);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless(app(TenantContext::class)->hasRole($request->user(), 'owner'), 403);
        $data = $request->validate([
            'school_name' => ['required', 'string', 'max:150'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone:all'],
            'color_primary' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_secondary' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_family' => ['sometimes', 'required', Rule::in(['Instrument Sans', 'Inter', 'Poppins', 'Nunito', 'DM Sans', 'Manrope', 'Lato', 'Merriweather', 'Noto Nastaliq Urdu', 'system-ui'])],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ]);
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $data['logo_data'] = 'data:'.$file->getMimeType().';base64,'.base64_encode(file_get_contents($file->getRealPath()));
        }
        unset($data['logo']);
        DB::transaction(function () use ($data, $request): void {
            foreach ($data as $key => $value) {
                DB::table('school_settings')->updateOrInsert(['school_id' => app(TenantContext::class)->id(), 'key' => $key], ['value' => $value]);
            }
            DB::table('school_audit')->insert(['school_id' => app(TenantContext::class)->id(), 'user_id' => $request->user()->id, 'module' => 'settings', 'record_id' => 0, 'action' => 'updated', 'changes' => json_encode($data), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School settings saved.']);
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        $tenant = app(TenantContext::class);

        return response()->json(['users' => User::query()->join('school_user_branches', 'school_user_branches.user_id', '=', 'users.id')->where('school_user_branches.school_id', $tenant->id())->where('school_user_branches.branch_id', $tenant->branchId())->where('school_user_branches.status', 'active')->select('users.id', 'users.name', 'users.username', 'users.email', 'school_user_branches.roles', 'users.is_active')->orderBy('users.name')->paginate(30)]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        $tenant = app(TenantContext::class);
        $sameBranch = DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->where('status', 'active')->exists();
        if (! $sameBranch && DB::table('schools')->count() === 1) {
            DB::table('school_user')->insertOrIgnore(['school_id' => $tenant->id(), 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_user_branches')->insertOrIgnore(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $user->id, 'roles' => json_encode($user->roles ?? []), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $sameBranch = true;
        }
        abort_unless($sameBranch, 404);
        abort_if($user->id === $request->user()->id, 403, 'You cannot change your own access.');
        $currentRoles = json_decode((string) DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->value('roles'), true) ?: [];
        abort_if(in_array('owner', $currentRoles, true), 403, 'Owner access must be managed privately.');
        abort_if(in_array('admin', $currentRoles, true) && ! $tenant->hasRole($request->user(), 'owner'), 403);
        $allowed = ['teacher', 'parent', 'student', 'accountant'];
        if ($tenant->hasRole($request->user(), 'owner')) {
            $allowed[] = 'admin';
        }
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in($allowed), 'distinct'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($user, $data, $request): void {
            $tenant = app(TenantContext::class);
            $before = ['roles' => json_decode((string) DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->value('roles'), true) ?: [], 'is_active' => $user->is_active];
            $user->forceFill(['is_active' => $data['is_active']])->save();
            DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->update(['roles' => json_encode($data['roles']), 'status' => $data['is_active'] ? 'active' : 'suspended', 'updated_at' => now()]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'user_id' => $request->user()->id, 'module' => 'users', 'record_id' => $user->id, 'action' => 'access_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Access updated and previous sessions revoked.']);
    }

    public function audit(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);

        return response()->json(['rows' => app(TenantContext::class)->table('school_audit')->leftJoin('users', 'users.id', '=', 'school_audit.user_id')
            ->select('school_audit.id', 'school_audit.module', 'school_audit.record_id', 'school_audit.action', 'school_audit.created_at', 'users.name')
            ->orderByDesc('school_audit.id')->paginate(30)]);
    }

    public function report(Request $request, string $module, int $id): View
    {
        abort_unless(in_array($module, ['payments', 'invoices', 'payroll', 'payroll_payments', 'grades']), 404);
        $tenant = app(TenantContext::class);
        $row = $this->portal->query($module, $request->user())->where('id', $id)->first();
        abort_unless($row, 404);
        $definition = $this->portal->definition($module);
        $extra = [];
        if ($module === 'invoices') {
            $extra['Paid'] = (int) $tenant->table('school_payments')->where('invoice_id', $id)->sum('amount');
            $extra['Balance'] = $row->amount - $extra['Paid'];
        }
        if ($module === 'payroll') {
            $extra['Net pay'] = $row->basic + $row->allowances - $row->deductions;
            $extra['Paid'] = (int) $tenant->table('school_payroll_payments')->where('payroll_id', $id)->sum('amount');
            $extra['Balance'] = $extra['Net pay'] - $extra['Paid'];
        }

        return view('reports.record', ['row' => (array) $row, 'definition' => $definition,
            'options' => $this->portal->options($request->user()), 'extra' => $extra,
            'school' => $tenant->table('school_settings')->where('key', 'school_name')->value('value') ?: 'School System',
            'currency' => $tenant->table('school_settings')->where('key', 'currency')->value('value') ?: '']);
    }

    private function availableContexts(User $user): Collection
    {
        return DB::table('school_user_branches as access')->join('school_user as membership', function ($join): void {
            $join->on('membership.school_id', '=', 'access.school_id')->on('membership.user_id', '=', 'access.user_id');
        })->join('schools', 'schools.id', '=', 'access.school_id')->join('school_branches as branch', 'branch.id', '=', 'access.branch_id')->where('access.user_id', $user->id)->where('access.status', 'active')->where('membership.status', 'active')->where('schools.status', 'active')->where('branch.status', 'active')->orderBy('schools.name')->orderBy('branch.name')->get(['access.school_id', 'schools.name as school_name', 'access.branch_id', 'branch.name as branch_name', 'access.roles'])->map(function (object $context): object {
            $context->roles = json_decode((string) $context->roles, true) ?: [];

            return $context;
        });
    }
}

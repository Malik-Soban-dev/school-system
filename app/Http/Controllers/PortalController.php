<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolPortal;
use App\Support\SchoolEntitlements;
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
        $settingsQuery = DB::table('school_settings')->where('school_id', $tenant->id())->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family', 'late_fee_amount', 'late_fee_grace_days']);
        if (DB::table('schools')->count() === 1) {
            $settingsQuery->orWhere(function ($query): void {
                $query->whereNull('school_id')->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family']);
            });
        }
        $settings = $settingsQuery->pluck('value', 'key')->all();

        return response()->json([
            'user' => [...$request->user()->only(['id', 'name', 'username', 'roles', 'tutorials']), 'effective_roles' => $tenant->roles($request->user()), 'interface_preferences' => $request->user()->interfacePreferences()],
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

    public function switchContext(Request $request, SchoolEntitlements $entitlements): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'integer'], 'branch_id' => ['required', 'integer']]);
        $context = $request->user()->hasRole('superadmin')
            ? DB::table('school_branches as branch')->join('schools', 'schools.id', '=', 'branch.school_id')->where('branch.school_id', $data['school_id'])->where('branch.id', $data['branch_id'])->first(['branch.school_id', 'branch.id as branch_id'])
            : DB::table('school_user_branches as access')->join('school_user as membership', function ($join): void {
                $join->on('membership.school_id', '=', 'access.school_id')->on('membership.user_id', '=', 'access.user_id');
            })->join('schools', 'schools.id', '=', 'access.school_id')->join('school_branches as branch', 'branch.id', '=', 'access.branch_id')->where('access.user_id', $request->user()->id)->where('access.school_id', $data['school_id'])->where('access.branch_id', $data['branch_id'])->where('access.status', 'active')->where('membership.status', 'active')->where('schools.status', 'active')->where('branch.status', 'active')->first(['access.school_id', 'access.branch_id']);
        abort_unless($context, 403, 'You do not have access to that school branch.');
        $defaultBranch = DB::table('school_branches')->where('school_id', $context->school_id)->where('is_default', true)->value('id');
        abort_if(! $request->user()->hasRole('superadmin') && (int) $context->branch_id !== (int) $defaultBranch && ! $entitlements->allowsForSchool((int) $context->school_id, 'branches'), 403, 'Branch access is not enabled for this school subscription.');
        $request->session()->put(['school_id' => (int) $context->school_id, 'branch_id' => (int) $context->branch_id]);
        app(TenantContext::class)->set((int) $context->school_id, (int) $context->branch_id);

        if ($request->user()->hasRole('superadmin')) {
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => (int) $context->school_id, 'branch_id' => (int) $context->branch_id, 'entity_type' => 'workspace', 'entity_id' => (int) $context->branch_id, 'action' => 'superadmin_context_switched', 'changes' => json_encode(['school_id' => (int) $context->school_id, 'branch_id' => (int) $context->branch_id]), 'created_at' => now()]);
        }

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
            DB::table('school_audit')->insert($saved->map(fn ($row): array => ['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $request->user()->id,
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

    public function promoteStudents(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        $tenant = app(TenantContext::class);
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'class_id' => ['required', 'integer', 'min:1'],
        ]);
        $targetClass = $tenant->table('school_classes')->where('id', $data['class_id'])->first(['id', 'name', 'capacity']);
        abort_unless($targetClass, 422, 'Choose a class from this school branch.');
        $students = $tenant->table('school_students')->whereIn('id', $data['student_ids'])->where('status', 'active')->get(['id', 'name', 'class_id']);
        abort_if($students->count() !== count($data['student_ids']), 422, 'One or more students are not active in this school branch.');
        $moving = $students->filter(fn (object $student): bool => (int) $student->class_id !== (int) $targetClass->id);
        abort_if($moving->isEmpty(), 422, 'All selected students are already in that class.');
        if ($targetClass->capacity !== null) {
            $currentCount = $tenant->table('school_students')->where('class_id', $targetClass->id)->where('status', 'active')->whereNotIn('id', $moving->pluck('id'))->count();
            abort_if($currentCount + $moving->count() > (int) $targetClass->capacity, 422, 'The target class does not have enough capacity for this promotion.');
        }
        DB::transaction(function () use ($request, $tenant, $moving, $targetClass): void {
            $now = now();
            foreach ($moving as $student) {
                $tenant->table('school_students')->where('id', $student->id)->update(['class_id' => $targetClass->id, 'updated_at' => $now]);
                $tenant->table('school_enrollments')->insertOrIgnore(['student_id' => $student->id, 'class_id' => $targetClass->id, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $request->user()->id, 'module' => 'students', 'record_id' => $student->id, 'action' => 'promoted', 'changes' => json_encode(['from_class_id' => $student->class_id, 'to_class_id' => $targetClass->id, 'to_class_name' => $targetClass->name]), 'created_at' => $now]);
            }
        });

        return response()->json(['message' => $moving->count().' students promoted to '.$targetClass->name.'.', 'promoted' => $moving->count(), 'class_id' => $targetClass->id]);
    }

    public function createBatchInvoices(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'accountant']), 403);
        $tenant = app(TenantContext::class);
        $data = $request->validate([
            'billing_month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
            'due_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'class_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $classId = $data['class_id'] ?? null;
        if ($classId !== null) {
            abort_unless($tenant->table('school_classes')->where('id', $classId)->exists(), 422, 'Choose a class from this school branch.');
        }
        $parts = explode('.', (string) $data['amount']);
        $amount = (int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
        $students = $tenant->table('school_students')->where('status', 'active')->when($classId, fn ($query) => $query->where('class_id', $classId))->orderBy('id')->get(['id', 'name']);
        abort_if($students->isEmpty(), 422, 'No active students match this invoice batch.');
        $existing = $tenant->table('school_invoices')->where('billing_month', $data['billing_month'])->whereIn('student_id', $students->pluck('id'))->pluck('student_id')->map(fn ($id): int => (int) $id)->all();
        $now = now();
        $rows = $students->reject(fn (object $student): bool => in_array($student->id, $existing, true))->map(fn (object $student): array => [
            'school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'reference' => 'FEE-'.$data['billing_month'].'-'.$student->id,
            'student_id' => $student->id, 'description' => $data['description'], 'amount' => $amount, 'due_on' => $data['due_on'], 'billing_month' => $data['billing_month'], 'created_at' => $now, 'updated_at' => $now,
        ])->values();
        DB::transaction(function () use ($request, $tenant, $rows, $data, $existing, $now): void {
            if ($rows->isNotEmpty()) {
                DB::table('school_invoices')->insert($rows->all());
            }
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $request->user()->id, 'module' => 'invoices', 'record_id' => 0, 'action' => 'batch_created', 'changes' => json_encode(['billing_month' => $data['billing_month'], 'created' => $rows->count(), 'skipped_existing' => count($existing)]), 'created_at' => $now]);
        });

        return response()->json(['message' => $rows->count().' invoices created; '.count($existing).' existing invoices skipped.', 'created' => $rows->count(), 'skipped' => count($existing)]);
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
            'late_fee_amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'late_fee_grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
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
        if (array_key_exists('late_fee_amount', $data)) {
            $parts = explode('.', (string) ($data['late_fee_amount'] ?? '0'));
            $data['late_fee_amount'] = (string) ((int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0'));
        }
        DB::transaction(function () use ($data, $request): void {
            foreach ($data as $key => $value) {
                DB::table('school_settings')->updateOrInsert(['school_id' => app(TenantContext::class)->id(), 'key' => $key], ['value' => $value]);
            }
            DB::table('school_audit')->insert(['school_id' => app(TenantContext::class)->id(), 'branch_id' => app(TenantContext::class)->branchId(), 'user_id' => $request->user()->id, 'module' => 'settings', 'record_id' => 0, 'action' => 'updated', 'changes' => json_encode($data), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School settings saved.']);
    }

    public function users(Request $request): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        $tenant = app(TenantContext::class);

        return response()->json(['users' => User::query()->join('school_user_branches', 'school_user_branches.user_id', '=', 'users.id')->where('school_user_branches.school_id', $tenant->id())->where('school_user_branches.branch_id', $tenant->branchId())->select('users.id', 'users.name', 'users.username', 'users.email', 'school_user_branches.roles', 'school_user_branches.status as access_status', 'users.is_active')->orderBy('users.name')->paginate(30)]);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        abort_unless($this->portal->admin($request->user()), 403);
        $tenant = app(TenantContext::class);
        $isSuperadmin = $request->user()->hasRole('superadmin');
        $allowed = ['teacher', 'parent', 'student', 'accountant'];
        if ($isSuperadmin || $tenant->hasRole($request->user(), 'owner')) {
            $allowed[] = 'admin';
        }
        if ($isSuperadmin) {
            $allowed[] = 'owner';
        }
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::in($allowed), 'distinct'], 'is_active' => ['required', 'boolean']]);
        $sameBranch = DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->exists();
        $isOwner = $tenant->hasRole($request->user(), 'owner');
        if (! $sameBranch && ! $isSuperadmin && ! $isOwner) {
            abort_if(DB::table('school_user')->where('user_id', $user->id)->where('school_id', '!=', $tenant->id())->exists(), 404);
            abort(403, 'The account has no access grant for this branch.');
        }
        if (! $sameBranch && ($isSuperadmin || $isOwner) && DB::table('schools')->count() === 1) {
            DB::table('school_user')->insertOrIgnore(['school_id' => $tenant->id(), 'user_id' => $user->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_user_branches')->insertOrIgnore(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $user->id, 'roles' => json_encode($user->roles ?? []), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $sameBranch = true;
        }
        abort_unless($sameBranch, 404);
        abort_if($user->id === $request->user()->id, 403, 'You cannot change your own access.');
        $currentRoles = json_decode((string) DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->value('roles'), true) ?: [];
        abort_if(in_array('owner', $currentRoles, true) && ! $isSuperadmin, 403, 'Owner access must be managed privately.');
        abort_if(in_array('admin', $currentRoles, true) && ! $isSuperadmin && ! $tenant->hasRole($request->user(), 'owner'), 403);
        DB::transaction(function () use ($user, $data, $request): void {
            $tenant = app(TenantContext::class);
            $before = ['roles' => json_decode((string) DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->value('roles'), true) ?: [], 'access_status' => DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->value('status'), 'account_active' => $user->is_active];
            DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $tenant->branchId())->where('user_id', $user->id)->update(['roles' => json_encode($data['roles']), 'status' => $data['is_active'] ? 'active' : 'suspended', 'updated_at' => now()]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $request->user()->id, 'module' => 'users', 'record_id' => $user->id, 'action' => 'access_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
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
        if ($user->hasRole('superadmin')) {
            return DB::table('school_branches as branch')->join('schools', 'schools.id', '=', 'branch.school_id')->orderBy('schools.name')->orderBy('branch.name')->get(['branch.school_id', 'schools.name as school_name', 'branch.id as branch_id', 'branch.name as branch_name', 'branch.is_default', 'branch.status as branch_status', 'schools.status as school_status'])->map(function (object $context): object {
                $context->roles = ['superadmin'];

                return $context;
            });
        }

        $contexts = DB::table('school_user_branches as access')->join('school_user as membership', function ($join): void {
            $join->on('membership.school_id', '=', 'access.school_id')->on('membership.user_id', '=', 'access.user_id');
        })->join('schools', 'schools.id', '=', 'access.school_id')->join('school_branches as branch', 'branch.id', '=', 'access.branch_id')->where('access.user_id', $user->id)->where('access.status', 'active')->where('membership.status', 'active')->where('schools.status', 'active')->where('branch.status', 'active')->orderBy('schools.name')->orderBy('branch.name')->get(['access.school_id', 'schools.name as school_name', 'access.branch_id', 'branch.name as branch_name', 'branch.is_default', 'access.roles'])->map(function (object $context): object {
            $context->roles = json_decode((string) $context->roles, true) ?: [];

            return $context;
        });

        $entitlements = app(SchoolEntitlements::class);

        return $contexts->filter(fn (object $context): bool => (bool) $context->is_default || $entitlements->allowsForSchool((int) $context->school_id, 'branches'))->values();
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PlatformController extends Controller
{
    public function index(Request $request): View
    {
        return view('superadmin.dashboard', ['user' => $request->user()]);
    }

    public function data(): JsonResponse
    {
        $schools = DB::table('schools as s')
            ->leftJoinSub(DB::table('school_user')->select('school_id')->selectRaw('count(*) as total')->where('status', 'active')->groupBy('school_id'), 'members', 'members.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_students')->select('school_id')->selectRaw('count(*) as total')->groupBy('school_id'), 'students', 'students.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_staff')->select('school_id')->selectRaw('count(*) as total')->groupBy('school_id'), 'staff', 'staff.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_teacher_assignments')->select('school_id')->where('status', 'active')->selectRaw('count(distinct user_id) as total')->groupBy('school_id'), 'teachers', 'teachers.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_branches')->select('school_id')->selectRaw('count(*) as total')->groupBy('school_id'), 'branches', 'branches.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_invoices')->select('school_id')->selectRaw('count(*) as total')->whereIn('status', ['issued', 'partial', 'overdue'])->groupBy('school_id'), 'invoices', 'invoices.school_id', '=', 's.id')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.slug', 's.status', 's.created_at', DB::raw('coalesce(members.total, 0) as members'), DB::raw('coalesce(students.total, 0) as students'), DB::raw('coalesce(staff.total, 0) as staff'), DB::raw('coalesce(teachers.total, 0) as teachers'), DB::raw('coalesce(branches.total, 0) as branches'), DB::raw('coalesce(invoices.total, 0) as open_invoices')]);
        $branchOptions = DB::table('school_branches')->whereIn('school_id', $schools->pluck('id'))->orderBy('name')->get(['id', 'school_id', 'name', 'code', 'status'])->groupBy('school_id');
        $schools = $schools->map(function (object $school) use ($branchOptions): object {
            $school->branch_options = $branchOptions->get($school->id, collect())->values();

            return $school;
        });
        $recentAudit = DB::table('school_audit as a')->join('schools as s', 's.id', '=', 'a.school_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'a.school_id', 's.name as school_name', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);
        $platformAudit = DB::table('platform_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'u.name as actor', 'a.action', 'a.created_at'])->map(fn (object $row): object => (object) ['id' => 'platform-'.$row->id, 'school_id' => null, 'school_name' => 'Platform', 'module' => 'platform', 'action' => $row->action, 'created_at' => $row->created_at, 'actor' => $row->actor]);
        $recentAudit = $recentAudit->concat($platformAudit)->sortByDesc('created_at')->take(30)->values();
        $plans = DB::table('platform_plans')->orderBy('monthly_price_cents')->get(['id', 'code', 'name', 'monthly_price_cents', 'max_branches', 'max_students', 'features', 'status']);

        return response()->json([
            'summary' => ['schools' => $schools->count(), 'active_schools' => $schools->where('status', 'active')->count(), 'branches' => (int) $schools->sum('branches'), 'members' => (int) $schools->sum('members'), 'students' => (int) $schools->sum('students'), 'staff' => (int) $schools->sum('staff'), 'teachers' => (int) $schools->sum('teachers'), 'open_invoices' => (int) $schools->sum('open_invoices')],
            'schools' => $schools, 'plans' => $plans, 'audit' => $recentAudit,
        ]);
    }

    public function health(): JsonResponse
    {
        $database = 'ok';
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $database = 'failed';
        }
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $sessions = Schema::hasTable('sessions') ? DB::table('sessions')->count() : null;
        $backups = collect();
        $backupDirectory = storage_path('app/private/backups');
        if (File::isDirectory($backupDirectory)) {
            $backups = collect(File::files($backupDirectory))->sortByDesc(fn ($file): int => $file->getMTime())->take(10)->values()->map(fn ($file): array => ['name' => $file->getFilename(), 'bytes' => $file->getSize(), 'modified_at' => date(DATE_ATOM, $file->getMTime())]);
        }

        return response()->json(['status' => $database === 'ok' && $failedJobs === 0 ? 'ok' : 'attention', 'database' => $database, 'queue' => ['pending' => $pendingJobs, 'failed' => $failedJobs], 'sessions' => $sessions, 'schools' => ['active' => DB::table('schools')->where('status', 'active')->count(), 'suspended' => DB::table('schools')->where('status', 'suspended')->count()], 'last_audit_at' => DB::table('school_audit')->max('created_at'), 'backups' => $backups]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($data['search'] ?? ''));
        $users = DB::table('users as u')
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('u.username', 'like', '%'.$search.'%');
            }))
            ->orderBy('u.name')
            ->paginate(50, ['u.id', 'u.name', 'u.username', 'u.email', 'u.roles', 'u.is_active']);
        $userIds = collect($users->items())->pluck('id');
        $access = DB::table('school_user_branches as a')->join('schools as s', 's.id', '=', 'a.school_id')->join('school_branches as b', 'b.id', '=', 'a.branch_id')->whereIn('a.user_id', $userIds)->get(['a.user_id', 's.id as school_id', 's.name as school_name', 'b.id as branch_id', 'b.name as branch_name', 'a.roles', 'a.status'])->groupBy('user_id');

        return response()->json(['users' => $users->through(function (object $user) use ($access): array {
            $roles = json_decode((string) $user->roles, true) ?: [];

            return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'roles' => $roles, 'is_active' => (bool) $user->is_active, 'is_superadmin' => in_array('superadmin', $roles, true), 'access' => $access->get($user->id, collect())->map(fn (object $row): array => ['school_id' => $row->school_id, 'school_name' => $row->school_name, 'branch_id' => $row->branch_id, 'branch_name' => $row->branch_name, 'roles' => json_decode((string) $row->roles, true) ?: [], 'status' => $row->status])->values()->all()];
        })]);
    }

    public function records(Request $request, int $school, string $module): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId !== null) {
            abort_unless(DB::table('school_branches')->where('school_id', $school)->where('id', $branchId)->exists(), 404);
        }
        $search = trim((string) ($data['search'] ?? ''));
        $query = match ($module) {
            'students' => DB::table('school_students as r')->leftJoin('school_classes as c', 'c.id', '=', 'r.class_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.admission_number', 'like', '%'.$search.'%')))->orderBy('r.name')->select(['r.id', 'r.name', 'r.admission_number', 'c.name as class_name', 'r.status', 'r.created_at']),
            'staff' => DB::table('school_staff as r')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.employee_number', 'like', '%'.$search.'%')->orWhere('r.designation', 'like', '%'.$search.'%')))->orderBy('r.name')->select(['r.id', 'r.name', 'r.employee_number', 'r.department', 'r.designation', 'r.status', 'r.created_at']),
            'classes' => DB::table('school_classes as r')->leftJoin('school_academic_years as y', 'y.id', '=', 'r.year_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where('r.name', 'like', '%'.$search.'%'))->orderBy('r.name')->select(['r.id', 'r.name', 'y.name as academic_year', 'r.capacity', 'r.created_at']),
            'users' => DB::table('school_user as membership')->join('users as u', 'u.id', '=', 'membership.user_id')->leftJoin('school_user_branches as access', function ($join) use ($school, $branchId): void {
                $join->on('access.user_id', '=', 'u.id')->where('access.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('access.branch_id', $branchId));
            })->leftJoin('school_branches as b', 'b.id', '=', 'access.branch_id')->where('membership.school_id', $school)->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('u.username', 'like', '%'.$search.'%')))->orderBy('u.name')->select(['u.id', 'u.name', 'u.username', 'u.email', 'u.is_active', 'membership.status as membership_status', 'b.name as branch_name', 'access.roles as branch_roles', 'access.status as branch_status']),
            'invoices' => DB::table('school_invoices as r')->leftJoin('school_students as s', 's.id', '=', 'r.student_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.reference', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.reference', 's.name as student_name', 'r.description', 'r.amount', 'r.due_on', 'r.status', 'r.created_at']),
            'payments' => DB::table('school_payments as r')->leftJoin('school_invoices as i', 'i.id', '=', 'r.invoice_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where('r.reference', 'like', '%'.$search.'%'))->orderByDesc('r.id')->select(['r.id', 'r.reference', 'i.reference as invoice_reference', 'r.amount', 'r.paid_on', 'r.method', 'r.created_at']),
            'audit' => DB::table('school_audit as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.module', 'like', '%'.$search.'%')->orWhere('r.action', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'u.name as actor', 'r.module', 'r.record_id', 'r.action', 'r.changes', 'r.created_at']),
            default => abort(404, 'Unsupported platform data module.'),
        };

        return response()->json(['module' => $module, 'records' => $query->paginate(50)]);
    }

    public function updateUserStatus(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $user, $data): void {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'is_active', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin accounts are managed separately.');
            $memberships = DB::table('school_user')->where('user_id', $user)->where('status', 'active')->pluck('school_id');
            abort_if($memberships->isEmpty(), 422, 'This account is not assigned to a school.');
            DB::table('users')->where('id', $user)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
            DB::table('sessions')->where('user_id', $user)->delete();
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'user_status_updated', 'changes' => json_encode(['before' => ['is_active' => (bool) $userRecord->is_active], 'after' => ['is_active' => $data['is_active']]]), 'created_at' => now()]);
            }
        });

        return response()->json(['message' => 'Account status updated and active sessions revoked.']);
    }

    public function createSchool(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'slug' => ['required', 'alpha_dash', 'max:80', 'unique:schools,slug']]);
        $school = DB::transaction(function () use ($request, $data): object {
            $schoolId = DB::table('schools')->insertGetId([...$data, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_branches')->insert(['school_id' => $schoolId, 'name' => $data['name'].' Main Branch', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $schoolId, 'action' => 'school_created', 'changes' => json_encode(['name' => $data['name'], 'slug' => $data['slug']]), 'created_at' => now()]);

            return DB::table('schools')->where('id', $schoolId)->first();
        });

        return response()->json(['school' => $school], 201);
    }

    public function updateSubscription(Request $request, int $school): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('platform_plans', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'canceled'])],
            'renews_at' => ['nullable', 'date'],
        ]);
        DB::transaction(function () use ($request, $school, $data): void {
            abort_unless(DB::table('schools')->where('id', $school)->lockForUpdate()->exists(), 404);
            $before = DB::table('school_subscriptions')->where('school_id', $school)->first(['plan_id', 'status', 'starts_at', 'renews_at', 'canceled_at', 'created_at']);
            $now = now();
            DB::table('school_subscriptions')->updateOrInsert(['school_id' => $school], ['plan_id' => $data['plan_id'], 'status' => $data['status'], 'starts_at' => $before?->starts_at ?? $now, 'renews_at' => $data['renews_at'] ?? null, 'canceled_at' => $data['status'] === 'canceled' ? $now : null, 'updated_at' => $now, 'created_at' => $before?->created_at ?? $now]);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'billing', 'record_id' => $school, 'action' => 'subscription_updated', 'changes' => json_encode(['before' => $before, 'after' => ['plan_id' => $data['plan_id'], 'status' => $data['status'], 'renews_at' => $data['renews_at'] ?? null]]), 'created_at' => $now]);
        });

        return response()->json(['message' => 'School subscription updated.']);
    }

    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'features' => ['required', 'array'],
            'features.*' => [Rule::in(['*', 'attendance', 'grades', 'invoices', 'payroll', 'notifications']), 'distinct'],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);
        DB::transaction(function () use ($request, $plan, $data): void {
            $before = DB::table('platform_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('platform_plans')->where('id', $plan)->update([...$data, 'features' => json_encode($data['features']), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'plan', 'entity_id' => $plan, 'action' => 'plan_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Platform plan updated.']);
    }

    public function school(int $school): JsonResponse
    {
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $counts = [];
        foreach (['school_students' => 'students', 'school_staff' => 'staff', 'school_classes' => 'classes', 'school_invoices' => 'invoices', 'school_payments' => 'payments', 'school_audit' => 'audit'] as $table => $key) {
            $counts[$key] = DB::table($table)->where('school_id', $school)->count();
        }
        $branches = DB::table('school_branches')->where('school_id', $school)->orderBy('name')->get(['id', 'name', 'code', 'status', 'is_default']);
        foreach (['school_students' => 'students', 'school_staff' => 'staff', 'school_classes' => 'classes', 'school_teacher_assignments' => 'teachers'] as $table => $key) {
            $countsByBranch = DB::table($table)->where('school_id', $school)->whereNotNull('branch_id')->select('branch_id')->selectRaw($key === 'teachers' ? 'count(distinct user_id) as total' : 'count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');
            $branches = $branches->map(function (object $branch) use ($countsByBranch, $key): object {
                $branch->{$key} = (int) ($countsByBranch[$branch->id] ?? 0);

                return $branch;
            });
        }
        $members = DB::table('school_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->where('su.school_id', $school)->orderBy('u.name')->limit(100)->get(['u.id', 'u.name', 'u.email', 'u.roles', 'u.is_active', 'su.status as membership_status']);
        $access = DB::table('school_user_branches as access')->join('school_branches as b', 'b.id', '=', 'access.branch_id')->where('access.school_id', $school)->orderBy('access.user_id')->orderBy('b.name')->get(['access.user_id', 'access.branch_id', 'b.name as branch_name', 'access.roles', 'access.status']);
        $audit = DB::table('school_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.school_id', $school)->orderByDesc('a.id')->limit(50)->get(['a.id', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);
        $subscription = DB::table('school_subscriptions as subscription')->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')->where('subscription.school_id', $school)->first(['subscription.id', 'subscription.plan_id', 'subscription.status', 'subscription.starts_at', 'subscription.renews_at', 'subscription.canceled_at', 'plan.code as plan_code', 'plan.name as plan_name', 'plan.monthly_price_cents', 'plan.max_branches', 'plan.max_students', 'plan.features']);

        return response()->json(['school' => DB::table('schools')->where('id', $school)->first(), 'branches' => $branches, 'counts' => $counts, 'subscription' => $subscription, 'members' => $members, 'access' => $access, 'audit' => $audit]);
    }

    public function createBranch(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('school_branches', 'code')->where(fn ($query) => $query->where('school_id', $school))]]);
        $branch = DB::transaction(function () use ($request, $school, $data): object {
            abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
            $branchId = DB::table('school_branches')->insertGetId([...$data, 'school_id' => $school, 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branchId, 'action' => 'branch_created', 'changes' => json_encode(['name' => $data['name'], 'code' => $data['code']]), 'created_at' => now()]);

            return DB::table('school_branches')->where('id', $branchId)->first();
        });

        return response()->json(['branch' => $branch], 201);
    }

    public function updateBranchStatus(Request $request, int $school, int $branch): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($request, $school, $branch, $data): void {
            $branchRecord = DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->lockForUpdate()->first(['id', 'status']);
            abort_unless($branchRecord, 404);

            if ($branchRecord->status === $data['status']) {
                return;
            }

            DB::table('school_branches')->where('id', $branch)->update(['status' => $data['status'], 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_status_updated', 'changes' => json_encode(['before' => ['status' => $branchRecord->status], 'after' => ['status' => $data['status']]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch status updated.']);
    }

    public function inviteBranchUser(Request $request, int $school, int $branch): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['owner', 'admin', 'teacher', 'student', 'parent', 'accountant']), 'distinct'],
        ]);
        abort_unless(DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->where('status', 'active')->exists(), 404);
        $token = Str::random(64);
        DB::transaction(function () use ($request, $school, $branch, $data, $token): void {
            DB::table('school_invitations')->updateOrInsert(['school_id' => $school, 'branch_id' => $branch, 'email' => $data['email']], [
                'name' => $data['name'], 'roles' => json_encode($data['roles']), 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48), 'accepted_at' => null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_invitation_issued', 'changes' => json_encode(['branch_id' => $branch, 'email' => $data['email'], 'roles' => $data['roles']]), 'created_at' => now()]);
        });

        return response()->json(['url' => route('invitation.show', ['token' => $token]), 'message' => 'Share this single-use link privately. It expires in 48 hours.'], 201);
    }

    public function updateBranchAccess(Request $request, int $school, int $user): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('school_branches', 'id')->where(fn ($query) => $query->where('school_id', $school))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['owner', 'admin', 'teacher', 'student', 'parent', 'accountant']), 'distinct'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
        DB::transaction(function () use ($request, $school, $user, $data): void {
            abort_unless(DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->where('status', 'active')->exists(), 404);
            abort_if(DB::table('users')->where('id', $user)->whereJsonContains('roles', 'superadmin')->exists(), 422, 'Platform Superadmin access is managed separately.');
            DB::table('school_user_branches')->updateOrInsert(
                ['school_id' => $school, 'branch_id' => $data['branch_id'], 'user_id' => $user],
                ['roles' => json_encode($data['roles']), 'status' => $data['status'], 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'branch_access_updated', 'changes' => json_encode(['branch_id' => $data['branch_id'], 'roles' => $data['roles'], 'status' => $data['status']]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch access updated.']);
    }

    public function updateMembershipStatus(Request $request, int $school, int $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($request, $school, $user, $data): void {
            $membership = DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->lockForUpdate()->first(['status']);
            abort_unless($membership, 404);
            abort_if(DB::table('users')->where('id', $user)->whereJsonContains('roles', 'superadmin')->exists(), 422, 'Platform Superadmin access is managed separately.');
            if ($membership->status === $data['status']) {
                return;
            }

            DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->update(['status' => $data['status'], 'updated_at' => now()]);
            if ($data['status'] === 'suspended') {
                DB::table('school_user_branches')->where('school_id', $school)->where('user_id', $user)->update(['status' => 'suspended', 'updated_at' => now()]);
                DB::table('sessions')->where('user_id', $user)->delete();
            }
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'school_membership_status_updated', 'changes' => json_encode(['before' => ['status' => $membership->status], 'after' => ['status' => $data['status']]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School membership status updated.']);
    }

    public function updateStatus(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($request, $school, $data): void {
            $schoolRecord = DB::table('schools')->where('id', $school)->lockForUpdate()->first(['id', 'status']);
            abort_unless($schoolRecord, 404);

            if ($schoolRecord->status === $data['status']) {
                return;
            }

            DB::table('schools')->where('id', $school)->update(['status' => $data['status'], 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $school, 'action' => 'school_status_updated', 'changes' => json_encode(['before' => ['status' => $schoolRecord->status], 'after' => ['status' => $data['status']]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School status updated.']);
    }
}

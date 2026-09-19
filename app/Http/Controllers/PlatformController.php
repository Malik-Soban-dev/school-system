<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
            ->leftJoinSub(DB::table('school_branches')->select('school_id')->selectRaw('count(*) as total')->groupBy('school_id'), 'branches', 'branches.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_invoices')->select('school_id')->selectRaw('count(*) as total')->whereIn('status', ['issued', 'partial', 'overdue'])->groupBy('school_id'), 'invoices', 'invoices.school_id', '=', 's.id')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.slug', 's.status', 's.created_at', DB::raw('coalesce(members.total, 0) as members'), DB::raw('coalesce(students.total, 0) as students'), DB::raw('coalesce(staff.total, 0) as staff'), DB::raw('coalesce(branches.total, 0) as branches'), DB::raw('coalesce(invoices.total, 0) as open_invoices')]);
        $recentAudit = DB::table('school_audit as a')->join('schools as s', 's.id', '=', 'a.school_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'a.school_id', 's.name as school_name', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);

        return response()->json([
            'summary' => ['schools' => $schools->count(), 'active_schools' => $schools->where('status', 'active')->count(), 'branches' => (int) $schools->sum('branches'), 'members' => (int) $schools->sum('members'), 'students' => (int) $schools->sum('students'), 'staff' => (int) $schools->sum('staff'), 'open_invoices' => (int) $schools->sum('open_invoices')],
            'schools' => $schools, 'audit' => $recentAudit,
        ]);
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

    public function school(int $school): JsonResponse
    {
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $counts = [];
        foreach (['school_students' => 'students', 'school_staff' => 'staff', 'school_classes' => 'classes', 'school_invoices' => 'invoices', 'school_payments' => 'payments', 'school_audit' => 'audit'] as $table => $key) {
            $counts[$key] = DB::table($table)->where('school_id', $school)->count();
        }
        $branches = DB::table('school_branches')->where('school_id', $school)->orderBy('name')->get(['id', 'name', 'code', 'status', 'is_default']);
        $members = DB::table('school_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->where('su.school_id', $school)->where('su.status', 'active')->orderBy('u.name')->limit(100)->get(['u.id', 'u.name', 'u.email', 'u.roles', 'u.is_active']);
        $access = DB::table('school_user_branches as access')->join('school_branches as b', 'b.id', '=', 'access.branch_id')->where('access.school_id', $school)->orderBy('access.user_id')->orderBy('b.name')->get(['access.user_id', 'access.branch_id', 'b.name as branch_name', 'access.roles', 'access.status']);
        $audit = DB::table('school_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.school_id', $school)->orderByDesc('a.id')->limit(50)->get(['a.id', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);

        return response()->json(['school' => DB::table('schools')->where('id', $school)->first(), 'branches' => $branches, 'counts' => $counts, 'members' => $members, 'access' => $access, 'audit' => $audit]);
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

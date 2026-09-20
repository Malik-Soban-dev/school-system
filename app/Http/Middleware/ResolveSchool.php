<?php

namespace App\Http\Middleware;

use App\Support\SchoolEntitlements;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveSchool
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->hasRole('superadmin')) {
            $selectedSchool = (int) $request->session()->get('school_id', 0);
            $selectedBranch = (int) $request->session()->get('branch_id', 0);
            if ($selectedSchool > 0 && $selectedBranch > 0 && DB::table('school_branches')->where('id', $selectedBranch)->where('school_id', $selectedSchool)->exists()) {
                $this->tenant->set($selectedSchool, $selectedBranch);
            }

            return $next($request);
        }
        if (app()->environment('testing')) {
            foreach (DB::table('schools')->pluck('name', 'id') as $schoolId => $schoolName) {
                DB::table('school_branches')->insertOrIgnore(['school_id' => $schoolId, 'name' => $schoolName.' Main Branch', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $selectedSchool = (int) $request->session()->get('school_id', 0);
        $selectedBranch = (int) $request->session()->get('branch_id', 0);
        $membership = DB::table('school_user_branches as access')
            ->join('school_user', function ($join): void {
                $join->on('school_user.school_id', '=', 'access.school_id')->on('school_user.user_id', '=', 'access.user_id');
            })
            ->join('schools', 'schools.id', '=', 'access.school_id')
            ->join('school_branches', 'school_branches.id', '=', 'access.branch_id')
            ->where('access.user_id', $request->user()->id)
            ->where('access.status', 'active')
            ->where('school_user.status', 'active')
            ->where('schools.status', 'active')
            ->where('school_branches.status', 'active')
            ->when($selectedSchool > 0, fn ($query) => $query->where('access.school_id', $selectedSchool))
            ->when($selectedBranch > 0, fn ($query) => $query->where('access.branch_id', $selectedBranch))
            ->orderBy('access.school_id')
            ->orderBy('access.branch_id')
            ->first(['access.school_id', 'access.branch_id']);
        if (! $membership) {
            $legacyMembership = DB::table('school_user')
                ->join('schools', 'schools.id', '=', 'school_user.school_id')
                ->join('school_branches', function ($join): void {
                    $join->on('school_branches.school_id', '=', 'school_user.school_id')->where('school_branches.is_default', true);
                })
                ->where('school_user.user_id', $request->user()->id)
                ->where('school_user.status', 'active')
                ->where('schools.status', 'active')
                ->where('school_branches.status', 'active')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('school_user_branches as existing_access')->whereColumn('existing_access.school_id', 'school_user.school_id')->whereColumn('existing_access.user_id', 'school_user.user_id'))
                ->when($selectedSchool > 0, fn ($query) => $query->where('school_user.school_id', $selectedSchool))
                ->orderBy('school_user.school_id')
                ->first(['school_user.school_id', 'school_branches.id as branch_id']);

            if ($legacyMembership) {
                DB::table('school_user_branches')->insertOrIgnore([
                    'school_id' => $legacyMembership->school_id,
                    'branch_id' => $legacyMembership->branch_id,
                    'user_id' => $request->user()->id,
                    'roles' => json_encode($request->user()->roles ?? []),
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $membership = $legacyMembership;
            }
        }
        $membership = $this->restrictUnentitledBranch($membership);
        if (! $membership && ! DB::table('school_user')->where('user_id', $request->user()->id)->exists() && DB::table('schools')->where('status', 'active')->count() === 1) {
            $schoolId = (int) DB::table('schools')->where('status', 'active')->value('id');
            $branchId = (int) DB::table('school_branches')->where('school_id', $schoolId)->where('is_default', true)->value('id');
            DB::table('school_user')->insertOrIgnore(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_user_branches')->insertOrIgnore(['school_id' => $schoolId, 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'roles' => json_encode($request->user()->roles ?? []), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $membership = (object) ['school_id' => $schoolId, 'branch_id' => $branchId];
        }
        abort_unless($membership, 403, 'Your account is not linked to an active school.');
        $this->tenant->set((int) $membership->school_id, (int) $membership->branch_id);

        return $next($request);
    }

    private function restrictUnentitledBranch(?object $membership): ?object
    {
        if (! $membership) {
            return null;
        }
        $defaultBranch = DB::table('school_branches')->where('school_id', $membership->school_id)->where('is_default', true)->value('id');
        if ((int) $membership->branch_id === (int) $defaultBranch || app(SchoolEntitlements::class)->allowsForSchool((int) $membership->school_id, 'branches')) {
            return $membership;
        }

        return DB::table('school_user_branches as access')->join('school_user as membership', function ($join): void {
            $join->on('membership.school_id', '=', 'access.school_id')->on('membership.user_id', '=', 'access.user_id');
        })->where('access.user_id', request()->user()->id)->where('access.school_id', $membership->school_id)->where('access.branch_id', $defaultBranch)->where('access.status', 'active')->where('membership.status', 'active')->first(['access.school_id', 'access.branch_id']);
    }
}

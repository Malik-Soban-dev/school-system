<?php

namespace App\Http\Middleware;

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
            return $next($request);
        }
        if ($this->tenant->has()) {
            return $next($request);
        }
        $selectedSchool = (int) $request->session()->get('school_id', 0);
        $membership = DB::table('school_user')->join('schools', 'schools.id', '=', 'school_user.school_id')->where('school_user.user_id', $request->user()->id)->where('school_user.status', 'active')->where('schools.status', 'active')->when($selectedSchool > 0, fn ($query) => $query->where('school_user.school_id', $selectedSchool))->orderBy('school_user.school_id')->first(['school_user.school_id']);
        if (! $membership && DB::table('schools')->where('status', 'active')->count() === 1) {
            $schoolId = (int) DB::table('schools')->where('status', 'active')->value('id');
            DB::table('school_user')->insertOrIgnore(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $membership = (object) ['school_id' => $schoolId];
        }
        abort_unless($membership, 403, 'Your account is not linked to an active school.');
        $this->tenant->set((int) $membership->school_id);

        return $next($request);
    }
}

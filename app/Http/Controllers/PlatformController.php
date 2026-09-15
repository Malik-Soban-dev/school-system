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
            ->leftJoinSub(DB::table('school_invoices')->select('school_id')->selectRaw('count(*) as total')->whereIn('status', ['issued', 'partial', 'overdue'])->groupBy('school_id'), 'invoices', 'invoices.school_id', '=', 's.id')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.slug', 's.status', 's.created_at', DB::raw('coalesce(members.total, 0) as members'), DB::raw('coalesce(students.total, 0) as students'), DB::raw('coalesce(staff.total, 0) as staff'), DB::raw('coalesce(invoices.total, 0) as open_invoices')]);
        $recentAudit = DB::table('school_audit as a')->join('schools as s', 's.id', '=', 'a.school_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'a.school_id', 's.name as school_name', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);

        return response()->json([
            'summary' => ['schools' => $schools->count(), 'active_schools' => $schools->where('status', 'active')->count(), 'members' => (int) $schools->sum('members'), 'students' => (int) $schools->sum('students'), 'staff' => (int) $schools->sum('staff'), 'open_invoices' => (int) $schools->sum('open_invoices')],
            'schools' => $schools, 'audit' => $recentAudit,
        ]);
    }

    public function school(int $school): JsonResponse
    {
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $counts = [];
        foreach (['school_students' => 'students', 'school_staff' => 'staff', 'school_classes' => 'classes', 'school_invoices' => 'invoices', 'school_payments' => 'payments', 'school_audit' => 'audit'] as $table => $key) {
            $counts[$key] = DB::table($table)->where('school_id', $school)->count();
        }
        $members = DB::table('school_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->where('su.school_id', $school)->where('su.status', 'active')->orderBy('u.name')->limit(100)->get(['u.id', 'u.name', 'u.email', 'u.roles', 'u.is_active']);
        $audit = DB::table('school_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.school_id', $school)->orderByDesc('a.id')->limit(50)->get(['a.id', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);

        return response()->json(['school' => DB::table('schools')->where('id', $school)->first(), 'counts' => $counts, 'members' => $members, 'audit' => $audit]);
    }

    public function updateStatus(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($request, $school, $data): void {
            $updated = DB::table('schools')->where('id', $school)->update(['status' => $data['status'], 'updated_at' => now()]);
            abort_unless($updated === 1, 404);
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $school, 'action' => 'school_status_updated', 'changes' => json_encode(['status' => $data['status']]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School status updated.']);
    }
}

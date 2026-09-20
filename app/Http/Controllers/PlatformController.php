<?php

namespace App\Http\Controllers;

use App\Jobs\BuildPlatformSchoolExport;
use App\Jobs\BuildPlatformUserExport;
use App\Support\SchoolEntitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
            ->leftJoinSub(DB::table('school_branches')->select('school_id')->where('status', 'active')->selectRaw('count(*) as total')->groupBy('school_id'), 'active_branches', 'active_branches.school_id', '=', 's.id')
            ->leftJoinSub(DB::table('school_invoices')->select('school_id')->selectRaw('count(*) as total')->whereIn('status', ['issued', 'partial', 'overdue'])->groupBy('school_id'), 'invoices', 'invoices.school_id', '=', 's.id')
            ->leftJoin('school_subscriptions as subscription', 'subscription.school_id', '=', 's.id')
            ->leftJoin('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')
            ->orderBy('s.name')
            ->get(['s.id', 's.name', 's.slug', 's.status', 's.created_at', DB::raw('coalesce(members.total, 0) as members'), DB::raw('coalesce(students.total, 0) as students'), DB::raw('coalesce(staff.total, 0) as staff'), DB::raw('coalesce(teachers.total, 0) as teachers'), DB::raw('coalesce(branches.total, 0) as branches'), DB::raw('coalesce(active_branches.total, 0) as active_branches'), DB::raw('coalesce(invoices.total, 0) as open_invoices'), 'plan.code as plan_code', 'plan.name as plan_name', 'plan.max_branches', 'plan.max_students', 'subscription.status as subscription_status']);
        $branchOptions = DB::table('school_branches')->whereIn('school_id', $schools->pluck('id'))->orderBy('name')->get(['id', 'school_id', 'name', 'code', 'status'])->groupBy('school_id');
        $schools = $schools->map(function (object $school) use ($branchOptions): object {
            $school->branch_options = $branchOptions->get($school->id, collect())->values();

            return $school;
        });
        $recentAudit = DB::table('school_audit as a')->join('schools as s', 's.id', '=', 'a.school_id')->leftJoin('school_branches as b', 'b.id', '=', 'a.branch_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'a.school_id', 's.name as school_name', 'a.branch_id', 'b.name as branch_name', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);
        $platformAudit = DB::table('platform_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('schools as s', 's.id', '=', 'a.school_id')->leftJoin('school_branches as b', 'b.id', '=', 'a.branch_id')->orderByDesc('a.id')->limit(30)->get(['a.id', 'a.school_id', 's.name as school_name', 'a.branch_id', 'b.name as branch_name', 'u.name as actor', 'a.action', 'a.created_at'])->map(fn (object $row): object => (object) ['id' => 'platform-'.$row->id, 'school_id' => $row->school_id, 'school_name' => $row->school_name ?? 'Platform', 'branch_id' => $row->branch_id, 'branch_name' => $row->branch_name, 'module' => 'platform', 'action' => $row->action, 'created_at' => $row->created_at, 'actor' => $row->actor]);
        $recentAudit = $recentAudit->concat($platformAudit)->sortByDesc('created_at')->take(30)->values();
        $plans = DB::table('platform_plans')->orderBy('monthly_price_cents')->get(['id', 'code', 'name', 'monthly_price_cents', 'max_branches', 'max_students', 'features', 'status']);
        $subscriptionCounts = DB::table('school_subscriptions')->select('status')->selectRaw('count(*) as total')->groupBy('status')->pluck('total', 'status');
        $mrrCents = (int) DB::table('school_subscriptions as subscription')->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')->where('subscription.status', 'active')->sum('plan.monthly_price_cents');
        $planDistribution = DB::table('school_subscriptions as subscription')->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')->select('plan.code', 'plan.name')->selectRaw('count(*) as total')->groupBy('plan.id', 'plan.code', 'plan.name')->orderBy('plan.name')->get();
        $platformAccounts = DB::table('school_user')->distinct()->count('user_id');
        $platformClasses = DB::table('school_classes')->count();
        $platformSubjects = DB::table('school_subjects')->count();
        $platformGuardians = DB::table('school_guardian_links')->where('status', 'active')->distinct()->count('user_id');
        $platformEnrollments = DB::table('school_enrollments')->count();
        $platformAttendance = DB::table('school_attendance')->count();
        $platformExams = DB::table('school_exams')->count();
        $platformGrades = DB::table('school_grades')->count();
        $platformInvoices = DB::table('school_invoices')->count();
        $platformPayments = DB::table('school_payments')->count();
        $platformExpenses = DB::table('school_expenses')->count();
        $platformLeaveRequests = DB::table('school_leave_requests')->count();
        $platformPayroll = DB::table('school_payroll')->count();
        $platformPayrollPayments = DB::table('school_payroll_payments')->count();
        $platformNotices = DB::table('school_notices')->count();
        $platformNotifications = DB::table('school_notifications')->count();
        $platformNotificationDeliveries = DB::table('school_notification_deliveries')->count();
        $platformCensus = [];
        foreach ([
            'school_settings' => 'settings',
            'school_academic_years' => 'academic_years',
            'school_teacher_assignments' => 'teacher_assignments',
            'school_guardian_links' => 'guardian_links',
            'school_timetables' => 'timetables',
            'school_exam_subjects' => 'exam_subjects',
            'school_grade_bands' => 'grade_bands',
            'school_invitations' => 'invitations',
            'school_notification_events' => 'notification_events',
            'school_notification_preferences' => 'notification_preferences',
            'school_user_branches' => 'branch_access',
            'school_audit' => 'audit',
        ] as $table => $key) {
            $platformCensus[$key] = DB::table($table)->count();
        }
        $entitlementAlerts = $schools->flatMap(function (object $school): array {
            $alerts = [];
            if (! $school->subscription_status) {
                $alerts[] = ['school_id' => $school->id, 'school_name' => $school->name, 'type' => 'subscription_missing', 'message' => 'No subscription is assigned.'];
            } elseif (in_array($school->subscription_status, ['past_due', 'canceled'], true)) {
                $alerts[] = ['school_id' => $school->id, 'school_name' => $school->name, 'type' => 'subscription_'.$school->subscription_status, 'message' => 'Subscription is '.$school->subscription_status.'.'];
            }
            if ($school->max_branches !== null && (int) $school->active_branches >= (int) $school->max_branches) {
                $alerts[] = ['school_id' => $school->id, 'school_name' => $school->name, 'type' => 'branch_limit', 'message' => 'Active branch usage is at the plan limit.', 'usage' => (int) $school->active_branches, 'limit' => (int) $school->max_branches];
            }
            if ($school->max_students !== null && (int) $school->students >= (int) $school->max_students) {
                $alerts[] = ['school_id' => $school->id, 'school_name' => $school->name, 'type' => 'student_limit', 'message' => 'Student usage is at the plan limit.', 'usage' => (int) $school->students, 'limit' => (int) $school->max_students];
            }

            return $alerts;
        })->values();

        return response()->json([
            'summary' => array_merge(['schools' => $schools->count(), 'active_schools' => $schools->where('status', 'active')->count(), 'suspended_schools' => $schools->where('status', 'suspended')->count(), 'branches' => (int) $schools->sum('branches'), 'active_branches' => (int) $schools->sum('active_branches'), 'suspended_branches' => (int) $schools->sum('branches') - (int) $schools->sum('active_branches'), 'members' => (int) $schools->sum('members'), 'accounts' => $platformAccounts, 'students' => (int) $schools->sum('students'), 'staff' => (int) $schools->sum('staff'), 'teachers' => (int) $schools->sum('teachers'), 'classes' => $platformClasses, 'subjects' => $platformSubjects, 'guardians' => $platformGuardians, 'enrollments' => $platformEnrollments, 'attendance' => $platformAttendance, 'exams' => $platformExams, 'grades' => $platformGrades, 'invoices' => $platformInvoices, 'open_invoices' => (int) $schools->sum('open_invoices'), 'payments' => $platformPayments, 'expenses' => $platformExpenses, 'leave_requests' => $platformLeaveRequests, 'payroll' => $platformPayroll, 'payroll_payments' => $platformPayrollPayments, 'notices' => $platformNotices, 'notifications' => $platformNotifications, 'notification_deliveries' => $platformNotificationDeliveries, 'entitlement_alerts' => $entitlementAlerts->count()], $platformCensus),
            'billing' => ['mrr_cents' => $mrrCents, 'subscriptions' => ['active' => (int) ($subscriptionCounts['active'] ?? 0), 'trialing' => (int) ($subscriptionCounts['trialing'] ?? 0), 'past_due' => (int) ($subscriptionCounts['past_due'] ?? 0), 'canceled' => (int) ($subscriptionCounts['canceled'] ?? 0)], 'plan_distribution' => $planDistribution],
            'schools' => $schools, 'plans' => $plans, 'audit' => $recentAudit, 'entitlement_alerts' => $entitlementAlerts,
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')], 'status' => ['nullable', Rule::in(['active', 'suspended'])], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = trim((string) ($data['search'] ?? ''));
        $query = DB::table('school_branches as b')
            ->join('schools as s', 's.id', '=', 'b.school_id')
            ->leftJoinSub(DB::table('school_students')->select('branch_id')->selectRaw('count(*) as total')->groupBy('branch_id'), 'students', 'students.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_staff')->select('branch_id')->selectRaw('count(*) as total')->groupBy('branch_id'), 'staff', 'staff.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_teacher_assignments')->select('branch_id')->where('status', 'active')->selectRaw('count(distinct user_id) as total')->groupBy('branch_id'), 'teachers', 'teachers.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_user_branches')->select('branch_id')->where('status', 'active')->selectRaw('count(distinct user_id) as total')->groupBy('branch_id'), 'members', 'members.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_guardian_links')->select('branch_id')->where('status', 'active')->selectRaw('count(distinct user_id) as total')->groupBy('branch_id'), 'guardians', 'guardians.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_classes')->select('branch_id')->selectRaw('count(*) as total')->groupBy('branch_id'), 'classes', 'classes.branch_id', '=', 'b.id')
            ->leftJoinSub(DB::table('school_invoices')->select('branch_id')->whereIn('status', ['issued', 'partial', 'overdue'])->selectRaw('count(*) as total')->groupBy('branch_id'), 'open_invoices', 'open_invoices.branch_id', '=', 'b.id')
            ->when(isset($data['school_id']), fn ($query) => $query->where('b.school_id', $data['school_id']))
            ->when(isset($data['status']), fn ($query) => $query->where('b.status', $data['status']))
            ->when($search !== '', fn ($query) => $query->where(fn ($searchQuery) => $searchQuery->where('b.name', 'like', '%'.$search.'%')->orWhere('b.code', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))
            ->orderBy('s.name')->orderBy('b.name');
        $branchMetrics = [
            'school_academic_years' => 'academic_years',
            'school_subjects' => 'subjects',
            'school_attendance' => 'attendance',
            'school_staff_attendance' => 'staff_attendance',
            'school_timetables' => 'timetables',
            'school_exams' => 'exams',
            'school_exam_subjects' => 'exam_subjects',
            'school_grade_bands' => 'grade_bands',
            'school_grades' => 'grades',
            'school_enrollments' => 'enrollments',
            'school_payments' => 'payments',
            'school_expenses' => 'expenses',
            'school_leave_requests' => 'leave_requests',
            'school_payroll' => 'payroll',
            'school_payroll_payments' => 'payroll_payments',
            'school_notices' => 'notices',
            'school_invitations' => 'invitations',
            'school_notifications' => 'notifications',
            'school_notification_events' => 'notification_events',
            'school_notification_deliveries' => 'notification_deliveries',
            'school_notification_preferences' => 'notification_preferences',
        ];
        foreach ($branchMetrics as $table => $key) {
            $query->leftJoinSub(DB::table($table)->select('branch_id')->selectRaw('count(*) as total')->groupBy('branch_id'), $key, $key.'.branch_id', '=', 'b.id');
        }
        $columns = ['b.id', 'b.school_id', 's.name as school_name', 'b.name', 'b.code', 'b.status', 'b.is_default', 'b.created_at'];
        foreach (array_merge(['members', 'students', 'staff', 'teachers', 'guardians', 'classes', 'open_invoices'], array_values($branchMetrics)) as $key) {
            $columns[] = DB::raw('coalesce('.$key.'.total, 0) as '.$key);
        }
        $branches = $query->paginate((int) ($data['per_page'] ?? 50), $columns);

        return response()->json(['branches' => $branches]);
    }

    public function billingInvoices(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')], 'status' => ['nullable', Rule::in(['issued', 'paid', 'void', 'overdue'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $invoices = DB::table('platform_billing_invoices as invoice')->join('schools as school', 'school.id', '=', 'invoice.school_id')->when(isset($data['school_id']), fn ($query) => $query->where('invoice.school_id', $data['school_id']))->when(isset($data['status']), fn ($query) => $query->where('invoice.status', $data['status']))->orderByDesc('invoice.id')->paginate((int) ($data['per_page'] ?? 50), ['invoice.id', 'invoice.school_id', 'school.name as school_name', 'invoice.invoice_number', 'invoice.amount_cents', 'invoice.currency', 'invoice.period_start', 'invoice.period_end', 'invoice.due_on', 'invoice.status', 'invoice.paid_at', 'invoice.payment_reference', 'invoice.notes', 'invoice.created_at']);

        return response()->json(['invoices' => $invoices]);
    }

    public function createBillingInvoice(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['amount_cents' => ['required', 'integer', 'min:1', 'max:1000000000'], 'currency' => ['nullable', 'string', 'size:3'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'due_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $invoice = DB::transaction(function () use ($request, $school, $data): object {
            abort_unless(DB::table('schools')->where('id', $school)->lockForUpdate()->exists(), 404);
            $subscription = DB::table('school_subscriptions')->where('school_id', $school)->whereIn('status', ['trialing', 'active'])->first(['id']);
            $invoiceId = DB::table('platform_billing_invoices')->insertGetId(['school_id' => $school, 'subscription_id' => $subscription?->id, 'created_by' => $request->user()->id, 'invoice_number' => 'PLAT-'.str_pad((string) $school, 6, '0', STR_PAD_LEFT).'-'.now()->format('Ym').'-'.Str::upper(Str::random(6)), 'amount_cents' => $data['amount_cents'], 'currency' => strtoupper($data['currency'] ?? 'USD'), 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'due_on' => $data['due_on'], 'status' => 'issued', 'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'platform_invoice', 'entity_id' => $invoiceId, 'action' => 'invoice_created', 'changes' => json_encode(['school_id' => $school, 'amount_cents' => $data['amount_cents'], 'currency' => strtoupper($data['currency'] ?? 'USD'), 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'due_on' => $data['due_on']]), 'created_at' => now()]);

            return DB::table('platform_billing_invoices')->where('id', $invoiceId)->first();
        });

        return response()->json(['invoice' => $invoice], 201);
    }

    public function updateBillingInvoiceStatus(Request $request, int $invoice): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['issued', 'paid', 'void', 'overdue'])], 'payment_reference' => ['nullable', 'string', 'max:255']]);
        DB::transaction(function () use ($request, $invoice, $data): void {
            $before = DB::table('platform_billing_invoices')->where('id', $invoice)->lockForUpdate()->first(['school_id', 'status', 'payment_reference', 'paid_at']);
            abort_unless($before, 404);
            abort_if($data['status'] === 'paid' && blank($data['payment_reference'] ?? $before->payment_reference), 422, 'A payment reference is required before marking an invoice paid.');
            $paidAt = $data['status'] === 'paid' ? ($before->paid_at ?? now()) : null;
            DB::table('platform_billing_invoices')->where('id', $invoice)->update(['status' => $data['status'], 'payment_reference' => $data['payment_reference'] ?? $before->payment_reference, 'paid_at' => $paidAt, 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $before->school_id, 'entity_type' => 'platform_invoice', 'entity_id' => $invoice, 'action' => 'invoice_status_updated', 'changes' => json_encode(['school_id' => $before->school_id, 'before' => $before, 'after' => ['status' => $data['status'], 'payment_reference' => $data['payment_reference'] ?? $before->payment_reference, 'paid_at' => $paidAt]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Platform invoice status updated.']);
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
        $storage = ['available' => false, 'bytes' => null, 'files' => null];
        $privateStorage = storage_path('app/private');
        if (File::isDirectory($privateStorage)) {
            try {
                $files = File::allFiles($privateStorage);
                $storage = ['available' => true, 'bytes' => collect($files)->sum(fn ($file): int => $file->getSize()), 'files' => count($files)];
            } catch (Throwable) {
                // Keep storage visibility explicit when the deployment blocks directory inspection.
            }
        }
        $scheduledRuns = Schema::hasTable('platform_scheduler_runs')
            ? DB::table('platform_scheduler_runs')->orderByDesc('started_at')->limit(20)->get(['command', 'status', 'exit_code', 'error_type', 'started_at', 'finished_at'])
            : collect();
        $applicationErrors = Schema::hasTable('platform_errors')
            ? ['last_24h' => DB::table('platform_errors')->where('occurred_at', '>=', now()->subDay())->count(), 'recent' => DB::table('platform_errors')->orderByDesc('occurred_at')->limit(20)->get(['error_type', 'status', 'method', 'route', 'fingerprint', 'occurred_at'])]
            : ['last_24h' => null, 'recent' => collect()];
        $notificationHealth = ['available' => false, 'last_24h' => null, 'failed_24h' => null, 'by_status' => collect(), 'recent_failures' => collect()];
        if (Schema::hasTable('school_notification_deliveries')) {
            $lastDay = now()->subDay();
            $notificationHealth = [
                'available' => true,
                'last_24h' => DB::table('school_notification_deliveries')->where('updated_at', '>=', $lastDay)->count(),
                'failed_24h' => DB::table('school_notification_deliveries')->whereIn('status', ['failed', 'unknown'])->where('updated_at', '>=', $lastDay)->count(),
                'by_status' => DB::table('school_notification_deliveries')->select('status')->selectRaw('count(*) as total')->groupBy('status')->orderBy('status')->get(),
                'recent_failures' => DB::table('school_notification_deliveries as d')->join('schools as s', 's.id', '=', 'd.school_id')->leftJoin('school_branches as b', 'b.id', '=', 'd.branch_id')->whereIn('d.status', ['failed', 'unknown'])->orderByDesc('d.updated_at')->limit(20)->get(['s.name as school_name', 'b.name as branch_name', 'd.channel', 'd.status', 'd.attempts', 'd.error_code', 'd.updated_at']),
            ];
        }
        $recentNotificationFailures = $notificationHealth['available'] ? $notificationHealth['failed_24h'] : null;
        $backups = collect();
        $backupDirectory = storage_path('app/private/backups');
        if (File::isDirectory($backupDirectory)) {
            $backups = collect(File::files($backupDirectory))->sortByDesc(fn ($file): int => $file->getMTime())->take(10)->values()->map(fn ($file): array => ['name' => $file->getFilename(), 'bytes' => $file->getSize(), 'modified_at' => date(DATE_ATOM, $file->getMTime()), 'download_url' => route('superadmin.backup.download', ['name' => $file->getFilename()])]);
        }

        return response()->json(['status' => $database === 'ok' && $failedJobs === 0 && $recentNotificationFailures === 0 ? 'ok' : 'attention', 'database' => $database, 'queue' => ['pending' => $pendingJobs, 'failed' => $failedJobs], 'sessions' => $sessions, 'storage' => $storage, 'scheduled_runs' => $scheduledRuns, 'application_errors' => $applicationErrors, 'notification_deliveries' => $notificationHealth, 'schools' => ['active' => DB::table('schools')->where('status', 'active')->count(), 'suspended' => DB::table('schools')->where('status', 'suspended')->count()], 'last_audit_at' => DB::table('school_audit')->max('created_at'), 'backups' => $backups]);
    }

    public function createBackup(Request $request): JsonResponse
    {
        $directory = storage_path('app/private/backups');
        $filename = 'school-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.enc';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $exitCode = Artisan::call('school:backup', ['--path' => $path]);
        abort_if($exitCode !== 0 || ! File::isFile($path), 422, 'The encrypted backup could not be created.');
        $backup = ['name' => $filename, 'bytes' => File::size($path), 'modified_at' => date(DATE_ATOM, File::lastModified($path))];
        DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_created', 'changes' => json_encode(['name' => $filename, 'bytes' => $backup['bytes']]), 'created_at' => now()]);

        return response()->json(['backup' => $backup, 'message' => 'Encrypted backup created.'], 201);
    }

    public function verifyBackup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+\.enc$/']]);
        abort_if(basename($data['name']) !== $data['name'], 422, 'Invalid backup name.');
        $path = storage_path('app/private/backups'.DIRECTORY_SEPARATOR.$data['name']);
        abort_unless(File::isFile($path), 404, 'Backup not found.');
        $exitCode = Artisan::call('school:verify-backup', ['path' => $path]);
        abort_if($exitCode !== 0, 422, 'Backup integrity verification failed.');
        DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_verified', 'changes' => json_encode(['name' => $data['name']]), 'created_at' => now()]);

        return response()->json(['message' => 'Backup passed integrity verification.', 'name' => $data['name']]);
    }

    public function downloadBackup(Request $request, string $name): BinaryFileResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.enc$/', $name) === 1 && basename($name) === $name, 404);
        $path = storage_path('app/private/backups'.DIRECTORY_SEPARATOR.$name);
        abort_unless(File::isFile($path), 404, 'Backup not found.');
        DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'backup', 'entity_id' => 0, 'action' => 'backup_downloaded', 'changes' => json_encode(['name' => $name, 'bytes' => File::size($path)]), 'created_at' => now()]);

        return response()->download($path, $name, ['Content-Type' => 'application/octet-stream']);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $jobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50), ['id', 'uuid', 'connection', 'queue', 'failed_at']) : collect();

        return response()->json(['failed_jobs' => $jobs]);
    }

    public function createUserExport(Request $request): JsonResponse
    {
        $export = DB::transaction(function () use ($request): object {
            $id = DB::table('platform_exports')->insertGetId(['requested_by' => $request->user()->id, 'type' => 'users', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'export', 'entity_id' => $id, 'action' => 'user_export_requested', 'changes' => json_encode(['type' => 'users']), 'created_at' => now()]);

            return DB::table('platform_exports')->where('id', $id)->first();
        });
        BuildPlatformUserExport::dispatch($export->id);

        return response()->json(['export' => $export, 'message' => 'User export queued. It will be available for download when processing completes.'], 202);
    }

    public function createSchoolExport(Request $request, int $school): JsonResponse
    {
        $export = DB::transaction(function () use ($request, $school): object {
            abort_unless(DB::table('schools')->where('id', $school)->lockForUpdate()->exists(), 404);
            $id = DB::table('platform_exports')->insertGetId(['requested_by' => $request->user()->id, 'school_id' => $school, 'type' => 'school', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'export', 'entity_id' => $id, 'action' => 'school_export_requested', 'changes' => json_encode(['school_id' => $school, 'type' => 'school']), 'created_at' => now()]);

            return DB::table('platform_exports')->where('id', $id)->first();
        });
        BuildPlatformSchoolExport::dispatch($export->id, $school);

        return response()->json(['export' => $export, 'message' => 'School data export queued. Secrets and authentication credentials are excluded.'], 202);
    }

    public function exports(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(['users', 'school'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $exports = DB::table('platform_exports as export')->leftJoin('schools', 'schools.id', '=', 'export.school_id')->when(isset($data['type']), fn ($query) => $query->where('export.type', $data['type']))->orderByDesc('export.id')->paginate((int) ($data['per_page'] ?? 20), ['export.*', 'schools.name as school_name']);

        return response()->json(['exports' => $exports->through(function (object $export): array {
            return [...(array) $export, 'download_url' => $export->status === 'completed' && $export->expires_at !== null && $export->expires_at > now() ? route('superadmin.export.download', ['export' => $export->id]) : null];
        })]);
    }

    public function downloadExport(Request $request, int $export): BinaryFileResponse
    {
        $record = DB::table('platform_exports')->where('id', $export)->whereIn('type', ['users', 'school'])->where('status', 'completed')->where('expires_at', '>', now())->first(['id', 'type', 'school_id', 'file_path']);
        $expectedName = $record?->type === 'school' ? 'school-'.$record->school_id.'-'.$export.'.ndjson' : 'users-'.$export.'.csv';
        abort_unless($record && $record->file_path && basename($record->file_path) === $expectedName, 404);
        $path = storage_path('app/private/'.$record->file_path);
        abort_unless(File::isFile($path), 404);
        $isSchool = $record->type === 'school';
        DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $record->school_id, 'entity_type' => 'export', 'entity_id' => $export, 'action' => $isSchool ? 'school_export_downloaded' : 'user_export_downloaded', 'changes' => json_encode(['type' => $record->type, 'school_id' => $record->school_id]), 'created_at' => now()]);

        return response()->download($path, $isSchool ? 'school-system-school-'.$record->school_id.'-'.$export.'.ndjson' : 'school-system-users-'.$export.'.csv', ['Content-Type' => $isSchool ? 'application/x-ndjson' : 'text/csv']);
    }

    public function forgetFailedJob(Request $request, int $job): JsonResponse
    {
        abort_unless(Schema::hasTable('failed_jobs'), 404);
        DB::transaction(function () use ($request, $job): void {
            $failedJob = DB::table('failed_jobs')->where('id', $job)->lockForUpdate()->first(['id', 'uuid', 'connection', 'queue', 'failed_at']);
            abort_unless($failedJob, 404);
            DB::table('failed_jobs')->where('id', $job)->delete();
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'failed_job', 'entity_id' => $job, 'action' => 'failed_job_forgotten', 'changes' => json_encode(['uuid' => $failedJob->uuid, 'connection' => $failedJob->connection, 'queue' => $failedJob->queue, 'failed_at' => $failedJob->failed_at]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Failed job record removed.']);
    }

    public function retryFailedJob(Request $request, int $job): JsonResponse
    {
        abort_unless(Schema::hasTable('failed_jobs'), 404);
        $failedJob = DB::table('failed_jobs')->where('id', $job)->first(['id', 'uuid', 'connection', 'queue', 'failed_at']);
        abort_unless($failedJob, 404);
        $exitCode = Artisan::call('queue:retry', ['id' => [$failedJob->uuid], '--no-interaction' => true, '--silent' => true]);
        abort_if($exitCode !== 0, 422, 'The failed job could not be requeued. It may already have been handled.');
        DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'failed_job', 'entity_id' => $job, 'action' => 'failed_job_retried', 'changes' => json_encode(['uuid' => $failedJob->uuid, 'connection' => $failedJob->connection, 'queue' => $failedJob->queue, 'failed_at' => $failedJob->failed_at]), 'created_at' => now()]);

        return response()->json(['message' => 'Failed job requeued for processing.']);
    }

    public function audit(Request $request): JsonResponse
    {
        $data = $request->validate(['school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')], 'branch_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        if (isset($data['branch_id'])) {
            abort_unless(DB::table('school_branches')->where('id', $data['branch_id'])->when(isset($data['school_id']), fn ($query) => $query->where('school_id', $data['school_id']))->exists(), 404);
        }
        $search = trim((string) ($data['search'] ?? ''));
        $schoolAudit = DB::table('school_audit as a')->join('schools as s', 's.id', '=', 'a.school_id')->leftJoin('school_branches as b', 'b.id', '=', 'a.branch_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->when(isset($data['school_id']), fn ($query) => $query->where('a.school_id', $data['school_id']))->when(isset($data['branch_id']), fn ($query) => $query->where('a.branch_id', $data['branch_id']))->when($search !== '', fn ($query) => $query->where(fn ($searchQuery) => $searchQuery->where('a.module', 'like', '%'.$search.'%')->orWhere('a.action', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')->orWhere('b.name', 'like', '%'.$search.'%')))->select(['a.id', 'a.school_id', 'a.branch_id', 's.name as school_name', 'b.name as branch_name', 'a.module', 'a.action', 'a.changes', 'a.created_at', 'u.name as actor'])->selectRaw("'school' as source");
        $platformAudit = DB::table('platform_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('schools as ps', 'ps.id', '=', 'a.school_id')->leftJoin('school_branches as pb', 'pb.id', '=', 'a.branch_id')
            ->when(isset($data['school_id']), fn ($query) => $query->where(function ($scope) use ($data): void {
                $scope->where('a.school_id', $data['school_id'])
                    ->orWhereJsonContains('a.changes->school_id', $data['school_id'])
                    ->orWhereJsonContains('a.changes->school_ids', $data['school_id'])
                    ->orWhere(fn ($entity) => $entity->whereIn('a.entity_type', ['school', 'subscription', 'school_feature'])->where('a.entity_id', $data['school_id']));
            }))
            ->when(isset($data['branch_id']), fn ($query) => $query->where(function ($scope) use ($data): void {
                $scope->where('a.branch_id', $data['branch_id'])
                    ->orWhereJsonContains('a.changes->branch_id', $data['branch_id'])
                    ->orWhereJsonContains('a.changes->branch_ids', $data['branch_id'])
                    ->orWhere(fn ($entity) => $entity->whereIn('a.entity_type', ['branch', 'workspace'])->where('a.entity_id', $data['branch_id']));
            }))
            ->when($search !== '', fn ($query) => $query->where(fn ($searchQuery) => $searchQuery->where('a.entity_type', 'like', '%'.$search.'%')->orWhere('a.action', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')->orWhere('ps.name', 'like', '%'.$search.'%')->orWhere('pb.name', 'like', '%'.$search.'%')))->select(['a.id', 'a.school_id', 'a.branch_id', DB::raw("coalesce(ps.name, 'Platform') as school_name"), 'pb.name as branch_name', DB::raw("'platform' as module"), 'a.action', 'a.changes', 'a.created_at', 'u.name as actor'])->selectRaw("'platform' as source");
        $events = DB::query()->fromSub($schoolAudit->unionAll($platformAudit), 'events')->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 50));

        return response()->json(['audit' => $events]);
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')], 'branch_id' => ['nullable', 'integer'], 'school_status' => ['nullable', Rule::in(['active', 'suspended'])], 'branch_status' => ['nullable', Rule::in(['active', 'suspended'])], 'status' => ['nullable', Rule::in(['active', 'suspended'])], 'membership_status' => ['nullable', Rule::in(['active', 'suspended'])], 'role' => ['nullable', Rule::in(['superadmin', 'owner', 'admin', 'teacher', 'student', 'parent', 'accountant'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        if (isset($data['branch_id'])) {
            abort_unless(DB::table('school_branches')->where('id', $data['branch_id'])->when(isset($data['school_id']), fn ($query) => $query->where('school_id', $data['school_id']))->exists(), 404);
        }
        $search = trim((string) ($data['search'] ?? ''));
        $users = DB::table('users as u')
            ->when($search !== '', fn ($query) => $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('u.username', 'like', '%'.$search.'%');
            }))
            ->when(isset($data['status']), fn ($query) => $query->where('u.is_active', $data['status'] === 'active'))
            ->when(isset($data['school_id']), fn ($query) => $query->whereExists(fn ($membership) => $membership->selectRaw('1')->from('school_user as membership')->whereColumn('membership.user_id', 'u.id')->where('membership.school_id', $data['school_id'])))
            ->when(isset($data['branch_id']), fn ($query) => $query->whereExists(fn ($access) => $access->selectRaw('1')->from('school_user_branches as access')->whereColumn('access.user_id', 'u.id')->where('access.branch_id', $data['branch_id'])))
            ->when(isset($data['school_status']), fn ($query) => $query->whereExists(function ($access) use ($data): void {
                $access->selectRaw('1')->from('school_user_branches as school_access')->join('schools as school', 'school.id', '=', 'school_access.school_id')->whereColumn('school_access.user_id', 'u.id')->where('school.status', $data['school_status'])->when(isset($data['school_id']), fn ($nested) => $nested->where('school_access.school_id', $data['school_id']))->when(isset($data['branch_id']), fn ($nested) => $nested->whereIn('school_access.school_id', DB::table('school_branches')->where('id', $data['branch_id'])->select('school_id')));
            }))
            ->when(isset($data['branch_status']), fn ($query) => $query->whereExists(function ($access) use ($data): void {
                $access->selectRaw('1')->from('school_user_branches as branch_access')->join('school_branches as branch', 'branch.id', '=', 'branch_access.branch_id')->whereColumn('branch_access.user_id', 'u.id')->where('branch.status', $data['branch_status'])->when(isset($data['school_id']), fn ($nested) => $nested->where('branch_access.school_id', $data['school_id']))->when(isset($data['branch_id']), fn ($nested) => $nested->where('branch_access.branch_id', $data['branch_id']));
            }))
            ->when(isset($data['membership_status']), fn ($query) => $query->whereExists(fn ($membership) => $membership->selectRaw('1')->from('school_user as membership')->whereColumn('membership.user_id', 'u.id')->where('membership.status', $data['membership_status'])->when(isset($data['school_id']), fn ($nested) => $nested->where('membership.school_id', $data['school_id']))->when(isset($data['branch_id']), fn ($nested) => $nested->whereIn('membership.school_id', DB::table('school_branches')->where('id', $data['branch_id'])->select('school_id')))))
            ->when(isset($data['role']), fn ($query) => $query->where(function ($roleQuery) use ($data): void {
                if ($data['role'] === 'superadmin' || (! isset($data['school_id']) && ! isset($data['branch_id']))) {
                    $roleQuery->whereJsonContains('u.roles', $data['role']);
                }
                $roleQuery->orWhereExists(function ($access) use ($data): void {
                    $access->selectRaw('1')->from('school_user_branches as role_access')->whereColumn('role_access.user_id', 'u.id')->whereJsonContains('role_access.roles', $data['role'])->when(! isset($data['school_id']) && ! isset($data['branch_id']), fn ($nested) => $nested->where('role_access.status', 'active'))->when(isset($data['school_id']), fn ($nested) => $nested->where('role_access.school_id', $data['school_id']))->when(isset($data['branch_id']), fn ($nested) => $nested->where('role_access.branch_id', $data['branch_id']));
                });
            }))
            ->orderBy('u.name')
            ->paginate((int) ($data['per_page'] ?? 50), ['u.id', 'u.name', 'u.username', 'u.email', 'u.roles', 'u.is_active']);
        $userIds = collect($users->items())->pluck('id');
        $access = DB::table('school_user_branches as a')->join('schools as s', 's.id', '=', 'a.school_id')->join('school_branches as b', 'b.id', '=', 'a.branch_id')->leftJoin('school_user as membership', function ($join): void {
            $join->on('membership.user_id', '=', 'a.user_id')->on('membership.school_id', '=', 'a.school_id');
        })->whereIn('a.user_id', $userIds)->get(['a.user_id', 's.id as school_id', 's.name as school_name', 's.status as school_status', 'b.id as branch_id', 'b.name as branch_name', 'b.status as branch_status', 'a.roles', 'a.status', 'membership.status as membership_status'])->groupBy('user_id');

        return response()->json(['users' => $users->through(function (object $user) use ($access): array {
            $roles = json_decode((string) $user->roles, true) ?: [];

            return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'roles' => $roles, 'is_active' => (bool) $user->is_active, 'is_superadmin' => in_array('superadmin', $roles, true), 'access' => $access->get($user->id, collect())->map(fn (object $row): array => ['school_id' => $row->school_id, 'school_name' => $row->school_name, 'school_status' => $row->school_status, 'branch_id' => $row->branch_id, 'branch_name' => $row->branch_name, 'branch_status' => $row->branch_status, 'roles' => json_decode((string) $row->roles, true) ?: [], 'status' => $row->status, 'membership_status' => $row->membership_status])->values()->all()];
        })]);
    }

    public function grantUserBranchAccess(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
            'branch_id' => ['required', 'integer', Rule::exists('school_branches', 'id')->where(fn ($query) => $query->where('school_id', $request->integer('school_id')))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['owner', 'admin', 'teacher', 'student', 'parent', 'accountant']), 'distinct'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        DB::transaction(function () use ($request, $user, $data): void {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin access is managed separately.');
            $school = DB::table('schools')->where('id', $data['school_id'])->lockForUpdate()->first(['status']);
            abort_unless($school, 404);
            abort_if($data['status'] === 'active' && $school->status !== 'active', 422, 'Activate the school before granting active access.');
            $branch = DB::table('school_branches')->where('school_id', $data['school_id'])->where('id', $data['branch_id'])->lockForUpdate()->first(['status']);
            abort_unless($branch, 404);
            abort_if($data['status'] === 'active' && $branch->status !== 'active', 422, 'Activate the branch before granting active access.');

            $membership = DB::table('school_user')->where('school_id', $data['school_id'])->where('user_id', $user)->lockForUpdate()->first(['status']);
            if (! $membership) {
                DB::table('school_user')->insert(['school_id' => $data['school_id'], 'user_id' => $user, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                abort_unless($membership->status === 'active', 422, 'Activate the school membership before granting branch access.');
            }

            $access = DB::table('school_user_branches')->where('school_id', $data['school_id'])->where('branch_id', $data['branch_id'])->where('user_id', $user)->lockForUpdate()->first(['roles', 'status']);
            if ($access) {
                DB::table('school_user_branches')->where('school_id', $data['school_id'])->where('branch_id', $data['branch_id'])->where('user_id', $user)->update(['roles' => json_encode($data['roles']), 'status' => $data['status'], 'updated_at' => now()]);
            } else {
                DB::table('school_user_branches')->insert(['school_id' => $data['school_id'], 'branch_id' => $data['branch_id'], 'user_id' => $user, 'roles' => json_encode($data['roles']), 'status' => $data['status'], 'created_at' => now(), 'updated_at' => now()]);
            }

            $revokedSessions = $data['status'] === 'suspended' && Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $user)->delete() : 0;
            $changes = ['school_id' => $data['school_id'], 'branch_id' => $data['branch_id'], 'before' => $access ? ['roles' => json_decode((string) $access->roles, true) ?: [], 'status' => $access->status] : null, 'after' => ['roles' => $data['roles'], 'status' => $data['status']], 'revoked_sessions' => $revokedSessions];
            DB::table('school_audit')->insert(['school_id' => $data['school_id'], 'branch_id' => $data['branch_id'], 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'branch_access_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $data['school_id'], 'branch_id' => $data['branch_id'], 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'user_branch_access_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'User branch access updated.']);
    }

    public function records(Request $request, int $school, string $module): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId !== null) {
            abort_unless(DB::table('school_branches')->where('school_id', $school)->where('id', $branchId)->exists(), 404);
        }
        $search = trim((string) ($data['search'] ?? ''));
        $scoped = fn (string $table) => DB::table($table)->where('school_id', $school)->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));
        $query = match ($module) {
            'academic_years' => $scoped('school_academic_years as r')->when($search !== '', fn ($q) => $q->where('r.name', 'like', '%'.$search.'%'))->orderByDesc('r.starts_on')->select(['r.id', 'r.name', 'r.starts_on', 'r.ends_on', 'r.created_at']),
            'students' => DB::table('school_students as r')->leftJoin('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.admission_number', 'like', '%'.$search.'%')))->orderBy('r.name')->select(['r.id', 'r.name', 'r.admission_number', 'c.name as class_name', 'r.status', 'r.created_at']),
            'student_welfare' => DB::table('school_student_welfare as r')->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('r.record_type', 'like', '%'.$search.'%')->orWhere('r.details', 'like', '%'.$search.'%')))->orderByDesc('r.record_date')->select(['r.id', 's.name as student', 'r.record_type', 'r.record_date', 'r.details', 'r.follow_up', 'r.created_at']),
            'assignments' => DB::table('school_assignments as r')->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->join('school_subjects as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('users as u', 'u.id', '=', 'r.teacher_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.title', 'like', '%'.$search.'%')->orWhere('c.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.due_on')->select(['r.id', 'r.title', 'c.name as class_name', 's.name as subject_name', 'u.name as teacher', 'r.due_on', 'r.status', 'r.created_at']),
            'submissions' => DB::table('school_submissions as r')->join('school_assignments as a', function ($join) use ($branchId): void {
                $join->on('a.id', '=', 'r.assignment_id')->when($branchId !== null, fn ($q) => $q->where('a.branch_id', $branchId));
            })->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('a.title', 'like', '%'.$search.'%')->orWhere('r.status', 'like', '%'.$search.'%')))->orderByDesc('r.updated_at')->select(['r.id', 'a.title as assignment', 's.name as student', 'r.status', 'r.grade', 'r.submitted_at', 'r.feedback', 'r.updated_at']),
            'materials' => DB::table('school_materials as r')->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->join('school_subjects as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('users as u', 'u.id', '=', 'r.teacher_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.title', 'like', '%'.$search.'%')->orWhere('c.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.title', 'c.name as class_name', 's.name as subject_name', 'u.name as teacher', 'r.resource_url', 'r.status', 'r.created_at']),
            'staff_attendance' => DB::table('school_staff_attendance as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('u.name', 'like', '%'.$search.'%')->orWhere('r.status', 'like', '%'.$search.'%')))->orderByDesc('r.date')->select(['r.id', 'u.name as staff_member', 'r.date', 'r.status', 'r.check_in', 'r.note', 'r.created_at']),
            'events' => $scoped('school_events as r')->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.title', 'like', '%'.$search.'%')->orWhere('r.location', 'like', '%'.$search.'%')))->orderByDesc('r.event_date')->select(['r.id', 'r.title', 'r.event_date', 'r.starts_at', 'r.ends_at', 'r.location', 'r.audience', 'r.status', 'r.created_at']),
            'event_rsvps' => DB::table('school_event_rsvps as r')->join('school_events as e', function ($join) use ($branchId): void { $join->on('e.id', '=', 'r.event_id')->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId)); })->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('e.title', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.updated_at')->select(['r.id', 'e.title as event', 'u.name as user', 'r.response', 'r.updated_at']),
            'staff' => DB::table('school_staff as r')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.employee_number', 'like', '%'.$search.'%')->orWhere('r.designation', 'like', '%'.$search.'%')))->orderBy('r.name')->select(['r.id', 'r.name', 'r.employee_number', 'r.department', 'r.designation', 'r.status', 'r.created_at']),
            'classes' => DB::table('school_classes as r')->leftJoin('school_academic_years as y', function ($join) use ($branchId): void {
                $join->on('y.id', '=', 'r.year_id')->when($branchId !== null, fn ($q) => $q->where('y.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where('r.name', 'like', '%'.$search.'%'))->orderBy('r.name')->select(['r.id', 'r.name', 'y.name as academic_year', 'r.capacity', 'r.created_at']),
            'subjects' => $scoped('school_subjects as r')->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.code', 'like', '%'.$search.'%')))->orderBy('r.name')->select(['r.id', 'r.name', 'r.code', 'r.created_at']),
            'teacher_assignments' => DB::table('school_teacher_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->join('school_subjects as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('u.name', 'like', '%'.$search.'%')->orWhere('c.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderBy('u.name')->select(['r.id', 'u.name as teacher', 'c.name as class_name', 's.name as subject_name', 'r.status', 'r.created_at']),
            'guardian_links' => DB::table('school_guardian_links as r')->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderBy('s.name')->select(['r.id', 's.name as student', 'u.name as guardian', 'r.relationship', 'r.status', 'r.created_at']),
            'attendance' => DB::table('school_attendance as r')->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('r.status', 'like', '%'.$search.'%')))->orderByDesc('r.date')->select(['r.id', 's.name as student', 'r.date', 'r.status', 'r.note', 'r.created_at']),
            'subject_attendance' => DB::table('school_subject_attendance as r')->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('school_subjects as u', function ($join) use ($branchId): void {
                $join->on('u.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('u.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')->orWhere('r.status', 'like', '%'.$search.'%')))->orderByDesc('r.date')->select(['r.id', 's.name as student', 'u.name as subject', 'r.date', 'r.status', 'r.note', 'r.created_at']),
            'timetables' => DB::table('school_timetables as r')->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->join('school_subjects as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('users as u', 'u.id', '=', 'r.teacher_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('c.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderBy('r.weekday')->select(['r.id', 'r.weekday', 'r.starts_at', 'r.ends_at', 'r.room', 'c.name as class_name', 's.name as subject_name', 'u.name as teacher']),
            'exams' => DB::table('school_exams as r')->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.name', 'like', '%'.$search.'%')->orWhere('c.name', 'like', '%'.$search.'%')))->orderByDesc('r.date')->select(['r.id', 'r.name', 'c.name as class_name', 'r.date', 'r.status', 'r.schedule_status', 'r.created_at']),
            'grade_bands' => $scoped('school_grade_bands as r')->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.name', 'like', '%'.$search.'%')->orWhere('r.minimum', 'like', '%'.$search.'%')))->orderByDesc('r.minimum')->select(['r.id', 'r.name', 'r.minimum', 'r.gpa', 'r.created_at']),
            'exam_subjects' => DB::table('school_exam_subjects as r')->join('school_exams as e', function ($join) use ($branchId): void {
                $join->on('e.id', '=', 'r.exam_id')->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId));
            })->join('school_subjects as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('e.name', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'e.name as exam', 's.name as subject', 'r.maximum', 'r.weight', 'r.created_at']),
            'grades' => DB::table('school_grades as r')->join('school_exams as e', function ($join) use ($branchId): void {
                $join->on('e.id', '=', 'r.exam_id')->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId));
            })->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('school_subjects as u', function ($join) use ($branchId): void {
                $join->on('u.id', '=', 'r.subject_id')->when($branchId !== null, fn ($q) => $q->where('u.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('e.name', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'e.name as exam', 's.name as student', 'u.name as subject', 'r.marks', 'r.maximum', 'r.remarks', 'r.created_at']),
            'users' => DB::table('school_user as membership')->join('users as u', 'u.id', '=', 'membership.user_id')->when($branchId !== null, fn ($q) => $q->whereExists(fn ($access) => $access->selectRaw('1')->from('school_user_branches as branch_access')->whereColumn('branch_access.user_id', 'membership.user_id')->where('branch_access.school_id', $school)->where('branch_access.branch_id', $branchId)))->leftJoin('school_user_branches as access', function ($join) use ($school, $branchId): void {
                $join->on('access.user_id', '=', 'u.id')->where('access.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('access.branch_id', $branchId));
            })->leftJoin('school_branches as b', 'b.id', '=', 'access.branch_id')->where('membership.school_id', $school)->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('u.username', 'like', '%'.$search.'%')))->orderBy('u.name')->select(['u.id', 'u.name', 'u.username', 'u.email', 'u.is_active', 'membership.status as membership_status', 'b.name as branch_name', 'access.roles as branch_roles', 'access.status as branch_status']),
            'branch_access' => DB::table('school_user_branches as r')->join('users as u', 'u.id', '=', 'r.user_id')->join('schools as school', 'school.id', '=', 'r.school_id')->join('school_branches as b', 'b.id', '=', 'r.branch_id')->leftJoin('school_user as membership', function ($join): void {
                $join->on('membership.user_id', '=', 'r.user_id')->on('membership.school_id', '=', 'r.school_id');
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')->orWhere('b.name', 'like', '%'.$search.'%')->orWhere('b.code', 'like', '%'.$search.'%')))->orderBy('u.name')->orderBy('b.name')->select(['r.id', 'u.name', 'u.email', 'u.username', 'school.status as school_status', 'b.name as branch_name', 'b.code as branch_code', 'b.status as branch_status', 'r.roles', 'r.status', 'membership.status as membership_status', 'r.created_at', 'r.updated_at']),
            'invoices' => DB::table('school_invoices as r')->leftJoin('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.reference', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.reference', 's.name as student_name', 'r.description', 'r.amount', 'r.due_on', 'r.status', 'r.created_at']),
            'payments' => DB::table('school_payments as r')->leftJoin('school_invoices as i', function ($join) use ($branchId): void {
                $join->on('i.id', '=', 'r.invoice_id')->when($branchId !== null, fn ($q) => $q->where('i.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where('r.reference', 'like', '%'.$search.'%'))->orderByDesc('r.id')->select(['r.id', 'r.reference', 'i.reference as invoice_reference', 'r.amount', 'r.paid_on', 'r.method', 'r.created_at']),
            'expenses' => $scoped('school_expenses as r')->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.reference', 'like', '%'.$search.'%')->orWhere('r.description', 'like', '%'.$search.'%')->orWhere('r.category', 'like', '%'.$search.'%')))->orderByDesc('r.paid_on')->select(['r.id', 'r.reference', 'r.description', 'r.category', 'r.amount', 'r.paid_on', 'r.created_at']),
            'leave_requests' => DB::table('school_leave_requests as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('u.name', 'like', '%'.$search.'%')->orWhere('r.reason', 'like', '%'.$search.'%')))->orderByDesc('r.starts_on')->select(['r.id', 'u.name as staff_member', 'r.starts_on', 'r.ends_on', 'r.reason', 'r.status', 'r.created_at']),
            'payroll' => DB::table('school_payroll as r')->join('school_staff as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.staff_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('r.month', 'like', '%'.$search.'%')))->orderByDesc('r.month')->select(['r.id', 's.name as staff_member', 'r.month', 'r.basic', 'r.allowances', 'r.deductions', 'r.created_at']),
            'payroll_payments' => DB::table('school_payroll_payments as r')->join('school_payroll as p', function ($join) use ($branchId): void {
                $join->on('p.id', '=', 'r.payroll_id')->when($branchId !== null, fn ($q) => $q->where('p.branch_id', $branchId));
            })->join('school_staff as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'p.staff_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.reference', 'like', '%'.$search.'%')->orWhere('s.name', 'like', '%'.$search.'%')))->orderByDesc('r.paid_on')->select(['r.id', 'r.reference', 's.name as staff_member', 'r.amount', 'r.paid_on', 'r.method', 'r.created_at']),
            'notices' => $scoped('school_notices as r')->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.title', 'like', '%'.$search.'%')->orWhere('r.body', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.title', 'r.audience', 'r.status', 'r.created_at']),
            'enrollments' => DB::table('school_enrollments as r')->join('school_students as s', function ($join) use ($branchId): void {
                $join->on('s.id', '=', 'r.student_id')->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId));
            })->join('school_classes as c', function ($join) use ($branchId): void {
                $join->on('c.id', '=', 'r.class_id')->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId));
            })->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('s.name', 'like', '%'.$search.'%')->orWhere('c.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 's.name as student', 'c.name as class_name', 'r.created_at']),
            'invitations' => DB::table('school_invitations as r')->leftJoin('school_branches as b', 'b.id', '=', 'r.branch_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.name', 'like', '%'.$search.'%')->orWhere('r.email', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.name', 'r.email', 'r.roles', 'b.name as branch_name', 'r.expires_at', 'r.accepted_at', 'r.created_at']),
            'notifications' => DB::table('school_notifications as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.title', 'like', '%'.$search.'%')->orWhere('r.module', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'u.name as recipient', 'r.module', 'r.title', 'r.read_at', 'r.created_at']),
            'notification_deliveries' => DB::table('school_notification_deliveries as r')->join('school_notifications as n', function ($join) use ($branchId): void {
                $join->on('n.id', '=', 'r.notification_id')->when($branchId !== null, fn ($q) => $q->where('n.branch_id', $branchId));
            })->join('users as u', 'u.id', '=', 'n.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.channel', 'like', '%'.$search.'%')->orWhere('r.status', 'like', '%'.$search.'%')->orWhere('r.error_code', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'u.name as recipient', 'n.title', 'r.channel', 'r.status', 'r.attempts', 'r.error_code', 'r.provider_id', 'r.available_at', 'r.updated_at']),
            'notification_preferences' => DB::table('school_notification_preferences as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('u.name', 'like', '%'.$search.'%')->orWhere('u.email', 'like', '%'.$search.'%')))->orderBy('u.name')->select(['r.school_id', 'r.user_id', 'u.name as user', 'u.email', 'r.whatsapp_consented_at', 'r.email_consented_at', 'r.created_at', 'r.updated_at'])->selectRaw('case when r.whatsapp_phone is null then 0 else 1 end as whatsapp_configured'),
            'notification_events' => $scoped('school_notification_events as r')->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.module', 'like', '%'.$search.'%')->orWhere('r.event_key', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.module', 'r.record_id', 'r.event_key', 'r.created_at']),
            'settings' => DB::table('school_settings as r')->where('r.school_id', $school)->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('r.key', 'like', '%'.$search.'%')->orWhere('r.value', 'like', '%'.$search.'%')))->orderBy('r.key')->select(['r.school_id', 'r.key'])->selectRaw("case when r.key in ('school_name', 'currency', 'timezone', 'color_primary', 'color_secondary', 'font_family') then r.value when r.key = 'logo_data' then '[redacted asset]' else '[redacted setting]' end as value"),
            'audit' => DB::table('school_audit as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('school_branches as b', 'b.id', '=', 'r.branch_id')->where('r.school_id', $school)->when($branchId !== null, fn ($q) => $q->where('r.branch_id', $branchId))->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('r.module', 'like', '%'.$search.'%')->orWhere('r.action', 'like', '%'.$search.'%')->orWhere('u.name', 'like', '%'.$search.'%')))->orderByDesc('r.id')->select(['r.id', 'r.branch_id', 'b.name as branch_name', 'u.name as actor', 'r.module', 'r.record_id', 'r.action', 'r.changes', 'r.created_at']),
            default => abort(404, 'Unsupported platform data module.'),
        };

        return response()->json(['module' => $module, 'records' => $query->paginate((int) ($data['per_page'] ?? 50))]);
    }

    public function updateUserProfile(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'username' => ['nullable', 'regex:/^[a-z0-9._-]{3,80}$/', Rule::unique('users', 'username')->ignore($user)],
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            $before = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'name', 'email', 'username', 'roles']);
            abort_unless($before, 404);
            abort_if(in_array('superadmin', json_decode((string) $before->roles, true) ?: [], true), 422, 'Platform Superadmin accounts are managed separately.');
            $email = $data['email'] ?? null;
            $username = $data['username'] ?? null;
            DB::table('users')->where('id', $user)->update(['name' => $data['name'], 'email' => $email, 'username' => $username, 'email_verified_at' => $email !== $before->email ? null : DB::raw('email_verified_at'), 'updated_at' => now()]);
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user)->delete();
            }
            $memberships = DB::table('school_user')->where('user_id', $user)->pluck('school_id');
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'user_profile_updated', 'changes' => json_encode(['before' => ['name' => $before->name, 'email' => $before->email, 'username' => $before->username], 'after' => ['name' => $data['name'], 'email' => $email, 'username' => $username]]), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'user_profile_updated', 'changes' => json_encode(['school_ids' => $memberships->values()->all(), 'before' => ['name' => $before->name, 'email' => $before->email, 'username' => $before->username], 'after' => ['name' => $data['name'], 'email' => $email, 'username' => $username]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'User profile updated and previous sessions revoked.']);
    }

    public function issuePasswordReset(Request $request, int $user): JsonResponse
    {
        $token = Str::random(64);
        DB::transaction(function () use ($request, $user, $token): void {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'email', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin accounts are managed separately.');
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $userRecord->email], ['token' => hash('sha256', $token), 'created_at' => now()]);
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user)->delete();
            }
            $memberships = DB::table('school_user')->where('user_id', $user)->pluck('school_id');
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'password_reset_issued', 'changes' => json_encode(['expires_at' => now()->addMinutes(60)->toIso8601String()]), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'password_reset_issued', 'changes' => json_encode(['school_ids' => $memberships->values()->all(), 'expires_at' => now()->addMinutes(60)->toIso8601String()]), 'created_at' => now()]);
        });

        return response()->json(['url' => route('password.reset', ['token' => $token]), 'message' => 'One-time password reset link created. Share it privately; it expires in 60 minutes and creating another link revokes this one.']);
    }

    public function updateUserStatus(Request $request, int $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $revokedSessions = DB::transaction(function () use ($request, $user, $data): int {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'is_active', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin accounts are managed separately.');
            $memberships = DB::table('school_user')->where('user_id', $user)->where('status', 'active')->pluck('school_id');
            DB::table('users')->where('id', $user)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);
            $revokedSessions = Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $user)->delete() : 0;
            $changes = ['before' => ['is_active' => (bool) $userRecord->is_active], 'after' => ['is_active' => $data['is_active']], 'revoked_sessions' => $revokedSessions];
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'user_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'user_status_updated', 'changes' => json_encode([...$changes, 'school_ids' => $memberships->values()->all()]), 'created_at' => now()]);

            return $revokedSessions;
        });

        return response()->json(['message' => 'Account status updated and active sessions revoked.', 'revoked_sessions' => $revokedSessions]);
    }

    public function revokeUserSessions(Request $request, int $user): JsonResponse
    {
        $revokedSessions = DB::transaction(function () use ($request, $user): int {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin sessions are managed separately.');
            $revokedSessions = Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $user)->delete() : 0;
            $memberships = DB::table('school_user')->where('user_id', $user)->pluck('school_id');
            foreach ($memberships as $schoolId) {
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'user_sessions_revoked', 'changes' => json_encode(['revoked_sessions' => $revokedSessions]), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'user_sessions_revoked', 'changes' => json_encode(['school_ids' => $memberships->values()->all(), 'revoked_sessions' => $revokedSessions]), 'created_at' => now()]);

            return $revokedSessions;
        });

        return response()->json(['message' => 'Active sessions revoked.', 'revoked_sessions' => $revokedSessions]);
    }

    public function createSchool(Request $request): JsonResponse
    {
        if ($request->filled('owner_email')) {
            $request->merge(['owner_email' => strtolower(trim((string) $request->input('owner_email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'alpha_dash', 'max:80', 'unique:schools,slug'],
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')->where(fn ($query) => $query->where('status', 'active'))],
            'owner_name' => ['nullable', 'required_with:owner_email', 'string', 'max:100'],
            'owner_email' => ['nullable', 'required_with:owner_name', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_role' => ['nullable', Rule::in(['owner', 'admin'])],
        ]);
        $onboarding = DB::transaction(function () use ($request, $data): array {
            $schoolId = DB::table('schools')->insertGetId(['name' => $data['name'], 'slug' => $data['slug'], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $branchId = DB::table('school_branches')->insertGetId(['school_id' => $schoolId, 'name' => $data['name'].' Main Branch', 'code' => 'main', 'status' => 'active', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
            $plan = DB::table('platform_plans')->where('id', $data['plan_id'] ?? null)->where('status', 'active')->first(['id', 'code']);
            $plan ??= DB::table('platform_plans')->where('code', 'starter')->where('status', 'active')->first(['id', 'code']);
            abort_unless($plan, 422, 'No active Starter plan is available for onboarding.');
            DB::table('school_subscriptions')->insert(['school_id' => $schoolId, 'plan_id' => $plan->id, 'status' => 'trialing', 'starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $schoolId, 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $schoolId, 'action' => 'school_created', 'changes' => json_encode(['name' => $data['name'], 'slug' => $data['slug'], 'plan' => $plan->code]), 'created_at' => now()]);
            $invitation = null;
            if (! empty($data['owner_email'])) {
                $token = Str::random(64);
                $roles = [$data['owner_role'] ?? 'owner'];
                $email = strtolower(trim($data['owner_email']));
                $invitationId = DB::table('school_invitations')->insertGetId(['school_id' => $schoolId, 'branch_id' => $branchId, 'name' => $data['owner_name'], 'email' => $email, 'roles' => json_encode($roles), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48), 'accepted_at' => null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                $auditChanges = ['invitation_id' => $invitationId, 'branch_id' => $branchId, 'email' => $email, 'roles' => $roles];
                DB::table('school_audit')->insert(['school_id' => $schoolId, 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $invitationId, 'action' => 'initial_admin_invitation_issued', 'changes' => json_encode($auditChanges), 'created_at' => now()]);
                DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $schoolId, 'branch_id' => $branchId, 'entity_type' => 'school', 'entity_id' => $schoolId, 'action' => 'initial_admin_invitation_issued', 'changes' => json_encode($auditChanges), 'created_at' => now()]);
                $invitation = ['id' => $invitationId, 'token' => $token, 'branch_id' => $branchId, 'email' => $email, 'roles' => $roles];
            }

            return ['school' => DB::table('schools')->where('id', $schoolId)->first(), 'invitation' => $invitation];
        });

        $invitation = $onboarding['invitation'];
        if ($invitation) {
            $invitation['url'] = route('invitation.show', ['token' => $invitation['token']]);
            unset($invitation['token']);
        }

        return response()->json(['school' => $onboarding['school'], 'invitation' => $invitation], 201);
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
            $after = ['plan_id' => $data['plan_id'], 'status' => $data['status'], 'renews_at' => $data['renews_at'] ?? null, 'canceled_at' => $data['status'] === 'canceled' ? $now : null];
            DB::table('school_subscriptions')->updateOrInsert(['school_id' => $school], ['plan_id' => $after['plan_id'], 'status' => $after['status'], 'starts_at' => $before?->starts_at ?? $now, 'renews_at' => $after['renews_at'], 'canceled_at' => $after['canceled_at'], 'updated_at' => $now, 'created_at' => $before?->created_at ?? $now]);
            $changes = ['before' => $before, 'after' => $after];
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'billing', 'record_id' => $school, 'action' => 'subscription_updated', 'changes' => json_encode($changes), 'created_at' => $now]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'subscription', 'entity_id' => $school, 'action' => 'subscription_updated', 'changes' => json_encode(['school_id' => $school, ...$changes]), 'created_at' => $now]);
        });

        return response()->json(['message' => 'School subscription updated.']);
    }

    public function createPlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:60', 'unique:platform_plans,code'],
            'name' => ['required', 'string', 'max:100'],
            'monthly_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'features' => ['required', 'array'],
            'features.*' => [Rule::in(['*', ...SchoolEntitlements::FEATURES]), 'distinct'],
        ]);
        $plan = DB::transaction(function () use ($request, $data): object {
            $planId = DB::table('platform_plans')->insertGetId([...$data, 'features' => json_encode($data['features']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'plan', 'entity_id' => $planId, 'action' => 'plan_created', 'changes' => json_encode($data), 'created_at' => now()]);

            return DB::table('platform_plans')->where('id', $planId)->first();
        });

        return response()->json(['plan' => $plan], 201);
    }

    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'features' => ['required', 'array'],
            'features.*' => [Rule::in(['*', ...SchoolEntitlements::FEATURES]), 'distinct'],
            'status' => ['required', Rule::in(['active', 'archived'])],
        ]);
        DB::transaction(function () use ($request, $plan, $data): void {
            $before = DB::table('platform_plans')->where('id', $plan)->lockForUpdate()->first();
            abort_unless($before, 404);
            if ($data['status'] === 'archived' && $before->status !== 'archived') {
                abort_if(DB::table('platform_plans')->where('status', 'active')->count() <= 1, 422, 'At least one active platform plan must remain available.');
                abort_if(DB::table('school_subscriptions')->where('plan_id', $plan)->whereIn('status', ['trialing', 'active'])->exists(), 422, 'This plan is assigned to an active or trialing school. Move those subscriptions before archiving it.');
            }
            DB::table('platform_plans')->where('id', $plan)->update([...$data, 'features' => json_encode($data['features']), 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'entity_type' => 'plan', 'entity_id' => $plan, 'action' => 'plan_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Platform plan updated.']);
    }

    public function updateSchool(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('schools', 'slug')->ignore($school)]]);
        DB::transaction(function () use ($request, $school, $data): void {
            $before = DB::table('schools')->where('id', $school)->lockForUpdate()->first(['id', 'name', 'slug', 'status']);
            abort_unless($before, 404);
            DB::table('schools')->where('id', $school)->update(['name' => $data['name'], 'slug' => $data['slug'], 'updated_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'school', 'entity_id' => $school, 'action' => 'school_updated', 'changes' => json_encode(['before' => $before, 'after' => $data]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School profile updated.']);
    }

    public function school(int $school): JsonResponse
    {
        abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
        $counts = [
            'members' => DB::table('school_user')->where('school_id', $school)->where('status', 'active')->count(),
            'students' => DB::table('school_students')->where('school_id', $school)->count(),
            'staff' => DB::table('school_staff')->where('school_id', $school)->count(),
            'teachers' => DB::table('school_teacher_assignments')->where('school_id', $school)->where('status', 'active')->distinct('user_id')->count('user_id'),
            'guardians' => DB::table('school_guardian_links')->where('school_id', $school)->where('status', 'active')->distinct('user_id')->count('user_id'),
            'classes' => DB::table('school_classes')->where('school_id', $school)->count(),
            'enrollments' => DB::table('school_enrollments')->where('school_id', $school)->count(),
            'invoices' => DB::table('school_invoices')->where('school_id', $school)->count(),
            'payments' => DB::table('school_payments')->where('school_id', $school)->count(),
            'payroll_payments' => DB::table('school_payroll_payments')->where('school_id', $school)->count(),
            'notifications' => DB::table('school_notifications')->where('school_id', $school)->count(),
            'audit' => DB::table('school_audit')->where('school_id', $school)->count(),
        ];
        foreach ([
            'school_academic_years' => 'academic_years',
            'school_subjects' => 'subjects',
            'school_guardian_links' => 'guardian_links',
            'school_teacher_assignments' => 'teacher_assignments',
            'school_attendance' => 'attendance',
            'school_staff_attendance' => 'staff_attendance',
            'school_timetables' => 'timetables',
            'school_exams' => 'exams',
            'school_exam_subjects' => 'exam_subjects',
            'school_grade_bands' => 'grade_bands',
            'school_grades' => 'grades',
            'school_expenses' => 'expenses',
            'school_leave_requests' => 'leave_requests',
            'school_payroll' => 'payroll',
            'school_notices' => 'notices',
            'school_invitations' => 'invitations',
            'school_notification_events' => 'notification_events',
            'school_notification_deliveries' => 'notification_deliveries',
            'school_notification_preferences' => 'notification_preferences',
            'school_settings' => 'settings',
        ] as $table => $key) {
            $counts[$key] = DB::table($table)->where('school_id', $school)->count();
        }
        $counts['open_invoices'] = DB::table('school_invoices')->where('school_id', $school)->whereIn('status', ['issued', 'partial', 'overdue'])->count();
        $branches = DB::table('school_branches')->where('school_id', $school)->orderBy('name')->get(['id', 'name', 'code', 'status', 'is_default']);
        foreach (['school_students' => 'students', 'school_staff' => 'staff', 'school_classes' => 'classes', 'school_teacher_assignments' => 'teachers', 'school_user_branches' => 'members', 'school_invoices' => 'open_invoices'] as $table => $key) {
            $countsByBranch = DB::table($table)->where('school_id', $school)->whereNotNull('branch_id')->when(in_array($key, ['members', 'teachers'], true), fn ($query) => $query->where('status', 'active'))->when($key === 'open_invoices', fn ($query) => $query->whereIn('status', ['issued', 'partial', 'overdue']))->select('branch_id')->selectRaw(in_array($key, ['teachers', 'members'], true) ? 'count(distinct user_id) as total' : 'count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');
            $branches = $branches->map(function (object $branch) use ($countsByBranch, $key): object {
                $branch->{$key} = (int) ($countsByBranch[$branch->id] ?? 0);

                return $branch;
            });
        }
        foreach ([
            'school_academic_years' => 'academic_years',
            'school_subjects' => 'subjects',
            'school_attendance' => 'attendance',
            'school_staff_attendance' => 'staff_attendance',
            'school_timetables' => 'timetables',
            'school_exams' => 'exams',
            'school_exam_subjects' => 'exam_subjects',
            'school_grade_bands' => 'grade_bands',
            'school_grades' => 'grades',
            'school_enrollments' => 'enrollments',
            'school_payments' => 'payments',
            'school_expenses' => 'expenses',
            'school_leave_requests' => 'leave_requests',
            'school_payroll' => 'payroll',
            'school_payroll_payments' => 'payroll_payments',
            'school_notices' => 'notices',
            'school_invitations' => 'invitations',
            'school_notifications' => 'notifications',
            'school_notification_events' => 'notification_events',
            'school_notification_deliveries' => 'notification_deliveries',
            'school_notification_preferences' => 'notification_preferences',
        ] as $table => $key) {
            $countsByBranch = DB::table($table)->where('school_id', $school)->whereNotNull('branch_id')->select('branch_id')->selectRaw('count(*) as total')->groupBy('branch_id')->pluck('total', 'branch_id');
            $branches = $branches->map(function (object $branch) use ($countsByBranch, $key): object {
                $branch->{$key} = (int) ($countsByBranch[$branch->id] ?? 0);

                return $branch;
            });
        }
        $members = DB::table('school_user as su')->join('users as u', 'u.id', '=', 'su.user_id')->where('su.school_id', $school)->orderBy('u.name')->get(['u.id', 'u.name', 'u.email', 'u.roles', 'u.is_active', 'su.status as membership_status']);
        $availableUsers = DB::table('users as u')->where('u.is_active', true)->whereJsonDoesntContain('u.roles', 'superadmin')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('school_user as existing')->whereColumn('existing.user_id', 'u.id')->where('existing.school_id', $school))->orderBy('u.name')->get(['u.id', 'u.name', 'u.email']);
        $access = DB::table('school_user_branches as access')->join('school_branches as b', 'b.id', '=', 'access.branch_id')->where('access.school_id', $school)->orderBy('access.user_id')->orderBy('b.name')->get(['access.user_id', 'access.branch_id', 'b.name as branch_name', 'access.roles', 'access.status']);
        $invitations = DB::table('school_invitations as invitation')->leftJoin('school_branches as branch', 'branch.id', '=', 'invitation.branch_id')->where('invitation.school_id', $school)->whereNull('invitation.accepted_at')->orderByDesc('invitation.id')->get(['invitation.id', 'invitation.name', 'invitation.email', 'invitation.roles', 'invitation.expires_at', 'branch.id as branch_id', 'branch.name as branch_name']);
        $audit = DB::table('school_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('school_branches as b', 'b.id', '=', 'a.branch_id')->where('a.school_id', $school)->orderByDesc('a.id')->limit(50)->get(['a.id', 'a.branch_id', 'b.name as branch_name', 'a.module', 'a.action', 'a.created_at', 'u.name as actor']);
        $subscription = DB::table('school_subscriptions as subscription')->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')->where('subscription.school_id', $school)->first(['subscription.id', 'subscription.plan_id', 'subscription.status', 'subscription.starts_at', 'subscription.renews_at', 'subscription.canceled_at', 'plan.code as plan_code', 'plan.name as plan_name', 'plan.monthly_price_cents', 'plan.max_branches', 'plan.max_students', 'plan.features']);
        $featureOverrides = DB::table('school_feature_overrides')->where('school_id', $school)->pluck('enabled', 'feature');
        $planFeatures = json_decode((string) ($subscription?->features ?? '[]'), true) ?: [];
        $featureAccess = collect(SchoolEntitlements::FEATURES)->mapWithKeys(fn (string $feature): array => [$feature => $subscription?->status !== 'canceled' && (array_key_exists($feature, $featureOverrides->all()) ? (bool) $featureOverrides[$feature] : in_array('*', $planFeatures, true) || in_array($feature, $planFeatures, true))])->all();
        $platformInvoices = DB::table('platform_billing_invoices')->where('school_id', $school)->orderByDesc('id')->limit(50)->get(['id', 'invoice_number', 'amount_cents', 'currency', 'period_start', 'period_end', 'due_on', 'status', 'paid_at', 'payment_reference', 'notes', 'created_at']);
        $billingTotals = DB::table('platform_billing_invoices')->where('school_id', $school)->selectRaw("coalesce(sum(case when status = 'paid' then amount_cents else 0 end), 0) as paid_cents, coalesce(sum(case when status in ('issued', 'overdue') then amount_cents else 0 end), 0) as outstanding_cents")->first();
        $billing = ['paid_cents' => (int) ($billingTotals->paid_cents ?? 0), 'outstanding_cents' => (int) ($billingTotals->outstanding_cents ?? 0), 'invoices' => $platformInvoices];

        return response()->json(['school' => DB::table('schools')->where('id', $school)->first(), 'branches' => $branches, 'counts' => $counts, 'subscription' => $subscription, 'feature_overrides' => $featureOverrides, 'feature_access' => $featureAccess, 'billing' => $billing, 'members' => $members, 'available_users' => $availableUsers, 'access' => $access, 'invitations' => $invitations, 'audit' => $audit]);
    }

    public function updateSchoolFeature(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['feature' => ['required', 'string', Rule::in(SchoolEntitlements::FEATURES)], 'enabled' => ['present', 'nullable', 'boolean']]);
        DB::transaction(function () use ($request, $school, $data): void {
            abort_unless(DB::table('schools')->where('id', $school)->exists(), 404);
            $before = DB::table('school_feature_overrides')->where('school_id', $school)->where('feature', $data['feature'])->value('enabled');
            if ($data['enabled'] === null) {
                DB::table('school_feature_overrides')->where('school_id', $school)->where('feature', $data['feature'])->delete();
            } else {
                DB::table('school_feature_overrides')->updateOrInsert(['school_id' => $school, 'feature' => $data['feature']], ['enabled' => $data['enabled'], 'updated_at' => now(), 'created_at' => now()]);
            }
            $changes = ['feature' => $data['feature'], 'before' => $before === null ? null : (bool) $before, 'after' => $data['enabled']];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => null, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => 0, 'action' => 'school_feature_override_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'school_feature', 'entity_id' => $school, 'action' => 'school_feature_override_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School feature access updated and audited.', 'feature' => $data['feature'], 'enabled' => $data['enabled']]);
    }

    public function createBranch(Request $request, int $school): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('school_branches', 'code')->where(fn ($query) => $query->where('school_id', $school))]]);
        $branch = DB::transaction(function () use ($request, $school, $data): object {
            abort_unless(DB::table('schools')->where('id', $school)->lockForUpdate()->exists(), 404);
            $subscription = DB::table('school_subscriptions')->where('school_id', $school)->whereIn('status', ['trialing', 'active'])->lockForUpdate()->first(['plan_id']);
            $maxBranches = $subscription ? DB::table('platform_plans')->where('id', $subscription->plan_id)->value('max_branches') : null;
            abort_if(! $request->user()->hasRole('superadmin') && $maxBranches !== null && DB::table('school_branches')->where('school_id', $school)->where('status', 'active')->count() >= (int) $maxBranches, 422, 'This school has reached its plan branch limit. Upgrade the subscription before adding another branch.');
            $branchId = DB::table('school_branches')->insertGetId([...$data, 'school_id' => $school, 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
            $changes = ['school_id' => $school, 'branch_id' => $branchId, 'name' => $data['name'], 'code' => $data['code']];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branchId, 'action' => 'branch_created', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $branchId, 'entity_type' => 'branch', 'entity_id' => $branchId, 'action' => 'branch_created', 'changes' => json_encode($changes), 'created_at' => now()]);

            return DB::table('school_branches')->where('id', $branchId)->first();
        });

        return response()->json(['branch' => $branch], 201);
    }

    public function updateBranch(Request $request, int $school, int $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('school_branches', 'code')->where(fn ($query) => $query->where('school_id', $school))->ignore($branch)],
        ]);
        DB::transaction(function () use ($request, $school, $branch, $data): void {
            $before = DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->lockForUpdate()->first(['id', 'name', 'code']);
            abort_unless($before, 404);
            DB::table('school_branches')->where('id', $branch)->update(['name' => $data['name'], 'code' => $data['code'], 'updated_at' => now()]);
            $changes = ['school_id' => $school, 'branch_id' => $branch, 'before' => $before, 'after' => $data];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $branch, 'entity_type' => 'branch', 'entity_id' => $branch, 'action' => 'branch_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch details updated.']);
    }

    public function setDefaultBranch(Request $request, int $school, int $branch): JsonResponse
    {
        DB::transaction(function () use ($request, $school, $branch): void {
            $target = DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->lockForUpdate()->first(['id', 'status', 'is_default']);
            abort_unless($target, 404);
            abort_if($target->status !== 'active', 422, 'Only an active branch can be the school default.');
            $previous = DB::table('school_branches')->where('school_id', $school)->where('is_default', true)->where('id', '!=', $branch)->value('id');
            DB::table('school_branches')->where('school_id', $school)->update(['is_default' => false, 'updated_at' => now()]);
            DB::table('school_branches')->where('id', $branch)->update(['is_default' => true, 'updated_at' => now()]);
            $changes = ['school_id' => $school, 'before' => ['branch_id' => $previous], 'after' => ['branch_id' => $branch]];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_default_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $branch, 'entity_type' => 'branch', 'entity_id' => $branch, 'action' => 'branch_default_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School default branch updated.']);
    }

    public function updateBranchStatus(Request $request, int $school, int $branch): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        DB::transaction(function () use ($request, $school, $branch, $data): void {
            $branchRecord = DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->lockForUpdate()->first(['id', 'status', 'is_default']);
            abort_unless($branchRecord, 404);

            if ($branchRecord->status === $data['status']) {
                return;
            }

            abort_if($data['status'] === 'suspended' && (bool) $branchRecord->is_default, 422, 'Set another active default branch before suspending the current default.');

            DB::table('school_branches')->where('id', $branch)->update(['status' => $data['status'], 'updated_at' => now()]);
            $revokedSessions = 0;
            if ($data['status'] === 'suspended' && Schema::hasTable('sessions')) {
                $userIds = DB::table('school_user_branches')->where('school_id', $school)->where('branch_id', $branch)->pluck('user_id');
                if ($userIds->isNotEmpty()) {
                    $revokedSessions = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                }
            }
            $changes = ['school_id' => $school, 'branch_id' => $branch, 'before' => ['status' => $branchRecord->status], 'after' => ['status' => $data['status']], 'revoked_sessions' => $revokedSessions];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $branch, 'entity_type' => 'branch', 'entity_id' => $branch, 'action' => 'branch_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
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
            $schoolRecord = DB::table('schools')->where('id', $school)->lockForUpdate()->first(['status']);
            $branchRecord = DB::table('school_branches')->where('school_id', $school)->where('id', $branch)->lockForUpdate()->first(['status']);
            abort_unless($schoolRecord?->status === 'active' && $branchRecord?->status === 'active', 404);
            DB::table('school_invitations')->updateOrInsert(['school_id' => $school, 'branch_id' => $branch, 'email' => $data['email']], [
                'name' => $data['name'], 'roles' => json_encode($data['roles']), 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48), 'accepted_at' => null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $invitationId = DB::table('school_invitations')->where('school_id', $school)->where('branch_id', $branch)->where('email', $data['email'])->value('id');
            $changes = ['school_id' => $school, 'branch_id' => $branch, 'invitation_id' => $invitationId, 'email' => $data['email'], 'roles' => $data['roles']];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branch, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $branch, 'action' => 'branch_invitation_issued', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $branch, 'entity_type' => 'invitation', 'entity_id' => $invitationId, 'action' => 'branch_invitation_issued', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['url' => route('invitation.show', ['token' => $token]), 'message' => 'Share this single-use link privately. It expires in 48 hours.'], 201);
    }

    public function revokeInvitation(Request $request, int $school, int $invitation): JsonResponse
    {
        DB::transaction(function () use ($request, $school, $invitation): void {
            $record = DB::table('school_invitations')->where('id', $invitation)->where('school_id', $school)->lockForUpdate()->first(['id', 'branch_id', 'email', 'accepted_at']);
            abort_unless($record, 404);
            abort_if($record->accepted_at !== null, 409, 'This invitation was already accepted. Manage the account from People & access.');
            DB::table('school_invitations')->where('id', $invitation)->update(['expires_at' => now(), 'token_hash' => hash('sha256', Str::random(64)), 'updated_at' => now()]);
            $changes = ['school_id' => $school, 'branch_id' => $record->branch_id, 'email' => $record->email];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $record->branch_id, 'user_id' => $request->user()->id, 'module' => 'invitations', 'record_id' => $invitation, 'action' => 'invitation_revoked', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $record->branch_id, 'entity_type' => 'invitation', 'entity_id' => $invitation, 'action' => 'invitation_revoked', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Invitation revoked. The old link no longer works.']);
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
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin access is managed separately.');
            $schoolRecord = DB::table('schools')->where('id', $school)->lockForUpdate()->first(['status']);
            abort_unless($schoolRecord, 404);
            abort_if($data['status'] === 'active' && $schoolRecord->status !== 'active', 422, 'Activate the school before granting active access.');
            $branch = DB::table('school_branches')->where('school_id', $school)->where('id', $data['branch_id'])->lockForUpdate()->first(['status']);
            abort_unless($branch, 404);
            abort_if($data['status'] === 'active' && $branch->status !== 'active', 422, 'Activate the branch before granting active access.');
            $membership = DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->lockForUpdate()->first(['status']);
            $membershipCreated = false;
            if (! $membership) {
                DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $user, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                $membershipCreated = true;
            } else {
                abort_unless($membership->status === 'active', 422, 'Activate the school membership before granting branch access.');
            }
            DB::table('school_user_branches')->updateOrInsert(
                ['school_id' => $school, 'branch_id' => $data['branch_id'], 'user_id' => $user],
                ['roles' => json_encode($data['roles']), 'status' => $data['status'], 'updated_at' => now(), 'created_at' => now()]
            );
            if ($membershipCreated) {
                DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'school_membership_created', 'changes' => json_encode(['user_id' => $user]), 'created_at' => now()]);
            }
            $changes = ['school_id' => $school, 'branch_id' => $data['branch_id'], 'user_id' => $user, 'roles' => $data['roles'], 'status' => $data['status']];
            DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $data['branch_id'], 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'branch_access_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'branch_id' => $data['branch_id'], 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'branch_access_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch access updated.']);
    }

    public function updateBulkBranchAccess(Request $request, int $school, int $user): JsonResponse
    {
        $data = $request->validate([
            'branch_ids' => ['required', 'array', 'min:1', 'max:100'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('school_branches', 'id')->where(fn ($query) => $query->where('school_id', $school))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['owner', 'admin', 'teacher', 'student', 'parent', 'accountant']), 'distinct'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        DB::transaction(function () use ($request, $school, $user, $data): void {
            $userRecord = DB::table('users')->where('id', $user)->lockForUpdate()->first(['id', 'roles']);
            abort_unless($userRecord, 404);
            abort_if(in_array('superadmin', json_decode((string) $userRecord->roles, true) ?: [], true), 422, 'Platform Superadmin access is managed separately.');
            $schoolRecord = DB::table('schools')->where('id', $school)->lockForUpdate()->first(['status']);
            abort_unless($schoolRecord, 404);
            abort_if($data['status'] === 'active' && $schoolRecord->status !== 'active', 422, 'Activate the school before granting active access.');
            $branches = DB::table('school_branches')->where('school_id', $school)->whereIn('id', $data['branch_ids'])->lockForUpdate()->pluck('status', 'id');
            abort_if($branches->count() !== count($data['branch_ids']), 404);
            abort_if($data['status'] === 'active' && $branches->contains(fn (string $status): bool => $status !== 'active'), 422, 'Activate all branches before granting active access.');
            $membership = DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->lockForUpdate()->first(['status']);
            $membershipCreated = false;
            if (! $membership) {
                DB::table('school_user')->insert(['school_id' => $school, 'user_id' => $user, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                $membershipCreated = true;
            } else {
                abort_unless($membership->status === 'active', 422, 'Activate the school membership before granting branch access.');
            }

            foreach ($data['branch_ids'] as $branchId) {
                DB::table('school_user_branches')->updateOrInsert(
                    ['school_id' => $school, 'branch_id' => $branchId, 'user_id' => $user],
                    ['roles' => json_encode($data['roles']), 'status' => $data['status'], 'updated_at' => now(), 'created_at' => now()]
                );
                DB::table('school_audit')->insert(['school_id' => $school, 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'branch_access_updated', 'changes' => json_encode(['branch_id' => $branchId, 'roles' => $data['roles'], 'status' => $data['status'], 'bulk' => true]), 'created_at' => now()]);
            }
            if ($membershipCreated) {
                DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'school_membership_created', 'changes' => json_encode(['user_id' => $user, 'bulk' => true]), 'created_at' => now()]);
            }
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'bulk_branch_access_updated', 'changes' => json_encode(['school_id' => $school, 'branch_ids' => $data['branch_ids'], 'roles' => $data['roles'], 'status' => $data['status']]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch access updated for all selected branches.']);
    }

    public function updateMembershipStatus(Request $request, int $school, int $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);
        $revokedSessions = DB::transaction(function () use ($request, $school, $user, $data): int {
            $membership = DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->lockForUpdate()->first(['status']);
            abort_unless($membership, 404);
            abort_if(DB::table('users')->where('id', $user)->whereJsonContains('roles', 'superadmin')->exists(), 422, 'Platform Superadmin access is managed separately.');
            if ($membership->status === $data['status']) {
                return 0;
            }

            DB::table('school_user')->where('school_id', $school)->where('user_id', $user)->update(['status' => $data['status'], 'updated_at' => now()]);
            $revokedSessions = 0;
            if ($data['status'] === 'suspended') {
                DB::table('school_user_branches')->where('school_id', $school)->where('user_id', $user)->update(['status' => 'suspended', 'updated_at' => now()]);
                $revokedSessions = Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $user)->delete() : 0;
            }
            $changes = ['school_id' => $school, 'user_id' => $user, 'before' => ['status' => $membership->status], 'after' => ['status' => $data['status']], 'revoked_sessions' => $revokedSessions];
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $user, 'action' => 'school_membership_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'user', 'entity_id' => $user, 'action' => 'school_membership_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);

            return $revokedSessions;
        });

        return response()->json(['message' => 'School membership status updated.', 'revoked_sessions' => $revokedSessions]);
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
            $revokedSessions = 0;
            if ($data['status'] === 'suspended' && Schema::hasTable('sessions')) {
                $userIds = DB::table('school_user')->where('school_id', $school)->pluck('user_id');
                if ($userIds->isNotEmpty()) {
                    $revokedSessions = DB::table('sessions')->whereIn('user_id', $userIds)->delete();
                }
            }
            $changes = ['school_id' => $school, 'before' => ['status' => $schoolRecord->status], 'after' => ['status' => $data['status']], 'revoked_sessions' => $revokedSessions];
            DB::table('school_audit')->insert(['school_id' => $school, 'user_id' => $request->user()->id, 'module' => 'platform', 'record_id' => $school, 'action' => 'school_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
            DB::table('platform_audit')->insert(['user_id' => $request->user()->id, 'school_id' => $school, 'entity_type' => 'school', 'entity_id' => $school, 'action' => 'school_status_updated', 'changes' => json_encode($changes), 'created_at' => now()]);
        });

        return response()->json(['message' => 'School status updated.']);
    }
}

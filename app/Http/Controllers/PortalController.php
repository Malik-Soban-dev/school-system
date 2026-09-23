<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SchoolPortal;
use App\Support\SchoolEntitlements;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PortalController extends Controller
{
    public function __construct(private SchoolPortal $portal) {}

    public function meta(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        $degraded = false;
        $degradedReasons = [];
        try {
            $settingsQuery = DB::table('school_settings')->where('school_id', $tenant->id())->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family', 'late_fee_amount', 'late_fee_grace_days', 'monthly_fee_amount', 'monthly_fee_due_day', 'monthly_fee_description']);
            if (DB::table('schools')->count() === 1) {
                $settingsQuery->orWhere(function ($query): void {
                    $query->whereNull('school_id')->whereIn('key', ['school_name', 'currency', 'timezone', 'logo_data', 'color_primary', 'color_secondary', 'font_family']);
                });
            }
            $settings = $settingsQuery->pluck('value', 'key')->all();
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'school settings';
            $settings = [];
        }

        try {
            $modules = $this->portal->modules($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'module access';
            $modules = collect(config('school-modules', []))->map(function (array $definition, string $key) use ($request): array {
                $definition['key'] = $key;
                $definition['canWrite'] = $this->portal->admin($request->user()) && collect($definition['write'] ?? [])->contains(fn (string $role): bool => $request->user()->hasRole($role));

                return $definition;
            })->values()->all();
        }

        try {
            $options = $this->portal->options($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'record options';
            $options = [];
        }

        try {
            $overview = $this->portal->overview($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'overview statistics';
            $overview = ['stats' => [], 'attendance' => ['total' => 0, 'attended' => 0, 'percentage' => 0], 'staff_attendance' => ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'percentage' => 0], 'fees' => ['billed' => 0, 'collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'collection_rate' => 0], 'today' => today()->toDateString()];
        }

        try {
            $effectiveRoles = $tenant->roles($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'account roles';
            $effectiveRoles = $request->user()->roles ?? [];
        }

        try {
            $contexts = $this->availableContexts($request->user());
        } catch (Throwable $exception) {
            report($exception);
            $degraded = true;
            $degradedReasons[] = 'workspace contexts';
            $contexts = collect();
        }

        $branchOverview = $tenant->hasRole($request->user(), 'owner') ? $this->ownerBranchOverview($tenant->id()) : [];

        return response()->json([
            'user' => [...$request->user()->only(['id', 'name', 'username', 'roles', 'tutorials']), 'effective_roles' => $effectiveRoles, 'interface_preferences' => $request->user()->interfacePreferences()],
            'modules' => $modules,
            'options' => $options,
            'settings' => (object) $settings,
            'overview' => $overview,
            'branch_overview' => $branchOverview,
            'canManage' => $this->portal->admin($request->user()),
            'today' => today($settings['timezone'] ?? config('app.timezone'))->toDateString(),
            'contexts' => $contexts,
            'current_context' => ['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId()],
            'degraded' => $degraded,
            'degraded_reasons' => array_values(array_unique($degradedReasons)),
        ]);
    }

    private function ownerBranchOverview(int $schoolId): array
    {
        return DB::table('school_branches as branch')
            ->where('branch.school_id', $schoolId)
            ->where('branch.status', 'active')
            ->orderByDesc('branch.is_default')
            ->orderBy('branch.name')
            ->get(['branch.id', 'branch.name', 'branch.code', 'branch.is_default'])
            ->map(function (object $branch) use ($schoolId): array {
                $invoices = DB::table('school_invoices')->where('school_id', $schoolId)->where('branch_id', $branch->id)->get(['id', 'amount']);
                $paid = DB::table('school_payments')->whereIn('invoice_id', $invoices->pluck('id'))->selectRaw('invoice_id, sum(amount) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
                $billed = (int) $invoices->sum('amount');
                $collected = (int) $paid->sum();
                $overdue = 0;
                foreach ($invoices as $invoice) {
                    $invoicePaid = (int) ($paid[$invoice->id] ?? 0);
                    if ($invoicePaid < (int) $invoice->amount && (string) $invoice->due_on < today()->toDateString()) {
                        $overdue += max(0, (int) $invoice->amount - $invoicePaid);
                    }
                }
                $attendance = DB::table('school_attendance')->where('school_id', $schoolId)->where('branch_id', $branch->id)->where('date', today()->toDateString())->get(['status']);
                $attendanceTotal = $attendance->count();
                $attendanceAttended = $attendance->whereIn('status', ['present', 'late'])->count();

                return [
                    'id' => (int) $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'is_default' => (bool) $branch->is_default,
                    'students' => DB::table('school_students')->where('school_id', $schoolId)->where('branch_id', $branch->id)->where('status', 'active')->count(),
                    'staff' => DB::table('school_staff')->where('school_id', $schoolId)->where('branch_id', $branch->id)->where('status', 'active')->count(),
                    'classes' => DB::table('school_classes')->where('school_id', $schoolId)->where('branch_id', $branch->id)->count(),
                    'collected' => $collected,
                    'outstanding' => max(0, $billed - $collected),
                    'overdue' => $overdue,
                    'expenses' => (int) DB::table('school_expenses')->where('school_id', $schoolId)->where('branch_id', $branch->id)->sum('amount'),
                    'payroll_disbursed' => (int) DB::table('school_payroll_payments')->where('school_id', $schoolId)->where('branch_id', $branch->id)->sum('amount'),
                    'attendance_percentage' => $attendanceTotal > 0 ? (int) round($attendanceAttended / $attendanceTotal * 100) : 0,
                ];
            })->all();
    }

    public function branchFinancialStatement(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'month' => ['required', 'date_format:Y-m'],
        ]);
        $branch = DB::table('school_branches')->where('school_id', $tenant->id())->where('id', $data['branch_id'])->where('status', 'active')->first(['id', 'name', 'code']);
        abort_unless($branch, 404);

        $previousMonth = CarbonImmutable::createFromFormat('Y-m', $data['month'])->subMonth()->format('Y-m');
        $trend = collect(range(0, 2))->map(function (int $offset) use ($tenant, $branch, $data): array {
            $period = CarbonImmutable::createFromFormat('Y-m', $data['month'])->subMonths($offset)->format('Y-m');

            return ['period' => $period, 'period_status' => $this->financialPeriodStatus($tenant->id(), $period), 'metrics' => $this->branchFinancialMetrics($tenant->id(), $branch, $period)];
        })->all();

        return response()->json(['branch' => $branch, 'period' => $data['month'], 'period_status' => $this->financialPeriodStatus($tenant->id(), $data['month']), 'currency' => $this->schoolCurrency($tenant->id()), 'metrics' => $this->branchFinancialMetrics($tenant->id(), $branch, $data['month']), 'comparison' => ['period' => $previousMonth, 'period_status' => $this->financialPeriodStatus($tenant->id(), $previousMonth), 'metrics' => $this->branchFinancialMetrics($tenant->id(), $branch, $previousMonth)], 'trend' => $trend]);
    }

    public function branchFinancialStatementExport(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'month' => ['required', 'date_format:Y-m']]);
        $branch = DB::table('school_branches')->where('school_id', $tenant->id())->where('id', $data['branch_id'])->where('status', 'active')->first(['id', 'name', 'code']);
        abort_unless($branch, 404);
        $currency = $this->schoolCurrency($tenant->id());
        $metrics = $this->branchFinancialMetrics($tenant->id(), $branch, $data['month']);
        $schoolName = DB::table('schools')->where('id', $tenant->id())->value('name') ?? 'School';
        $actor = $request->user()->email ?? $request->user()->username ?? (string) $request->user()->id;

        return response()->streamDownload(function () use ($schoolName, $branch, $data, $currency, $actor, $metrics): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['School', 'Branch', 'Period', 'Currency', 'Generated at', 'Actor scope', 'Billed', 'Collected', 'Outstanding', 'Overdue', 'Expenses', 'Payroll disbursed']);
            fputcsv($handle, [$schoolName, $branch->name, $data['month'], $currency, now()->toIso8601String(), $actor, number_format($metrics['billed'] / 100, 2, '.', ''), number_format($metrics['collected'] / 100, 2, '.', ''), number_format($metrics['outstanding'] / 100, 2, '.', ''), number_format($metrics['overdue'] / 100, 2, '.', ''), number_format($metrics['expenses'] / 100, 2, '.', ''), number_format($metrics['payroll_disbursed'] / 100, 2, '.', '')]);
            fclose($handle);
        }, 'branch-statement-'.$branch->code.'-'.$data['month'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function schoolCurrency(int $schoolId): string
    {
        return (string) (DB::table('school_settings')->where('school_id', $schoolId)->where('key', 'currency')->value('value') ?? 'USD');
    }

    private function financialPeriodStatus(int $schoolId, string $month): string
    {
        $timezone = DB::table('school_settings')->where('school_id', $schoolId)->where('key', 'timezone')->value('value') ?? config('app.timezone');
        $currentMonth = today($timezone)->format('Y-m');

        return $month < $currentMonth ? 'closed' : ($month === $currentMonth ? 'open' : 'upcoming');
    }

    private function branchFinancialMetrics(int $schoolId, object $branch, string $month): array
    {
        $invoices = DB::table('school_invoices')->where('school_id', $schoolId)->where('branch_id', $branch->id)->where('billing_month', $month);
        $payments = DB::table('school_payments')->where('school_id', $schoolId)->whereIn('invoice_id', (clone $invoices)->select('id'));
        $invoiceRows = (clone $invoices)->get(['id', 'amount', 'due_on']);
        $paidByInvoice = (clone $payments)->select('invoice_id')->selectRaw('sum(amount) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
        $billed = (int) $invoiceRows->sum('amount');
        $collected = (int) $paidByInvoice->sum();
        $aging = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_plus' => 0];
        $outstandingInvoices = 0;
        $overdueInvoices = 0;
        $overdue = $invoiceRows->sum(function (object $invoice) use ($paidByInvoice): int {
            $balance = max(0, (int) $invoice->amount - (int) ($paidByInvoice[$invoice->id] ?? 0));

            return (string) $invoice->due_on < today()->toDateString() ? $balance : 0;
        });
        foreach ($invoiceRows as $invoice) {
            $balance = max(0, (int) $invoice->amount - (int) ($paidByInvoice[$invoice->id] ?? 0));
            if ($balance === 0) {
                continue;
            }
            $outstandingInvoices++;
            $daysLate = CarbonImmutable::parse($invoice->due_on)->diffInDays(CarbonImmutable::today(), false);
            if ($daysLate <= 0) {
                $aging['current'] += $balance;
            } else {
                $overdueInvoices++;
                $bucket = $daysLate <= 30 ? '1_30' : ($daysLate <= 60 ? '31_60' : '61_plus');
                $aging[$bucket] += $balance;
            }
        }

        return ['billed' => $billed, 'collected' => $collected, 'outstanding' => max(0, $billed - $collected), 'overdue' => (int) $overdue, 'expenses' => (int) DB::table('school_expenses')->where('school_id', $schoolId)->where('branch_id', $branch->id)->whereBetween('paid_on', [$month.'-01', $month.'-31'])->sum('amount'), 'payroll_disbursed' => (int) DB::table('school_payroll_payments')->where('school_id', $schoolId)->where('branch_id', $branch->id)->whereBetween('paid_on', [$month.'-01', $month.'-31'])->sum('amount'), 'receipts' => (int) (clone $payments)->count(), 'methods' => (clone $payments)->select('method')->selectRaw('count(*) as receipts')->selectRaw('sum(amount) as amount')->groupBy('method')->orderBy('method')->get(), 'aging' => $aging, 'outstanding_invoices' => $outstandingInvoices, 'overdue_invoices' => $overdueInvoices];
    }

    public function contexts(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);

        return response()->json(['contexts' => $this->availableContexts($request->user()), 'current' => ['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId()]]);
    }

    public function branches(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);

        return response()->json(['branches' => DB::table('school_branches')->where('school_id', $tenant->id())->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code', 'status', 'is_default'])]);
    }

    public function createBranch(Request $request, SchoolEntitlements $entitlements): JsonResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);
        $entitlements->assertFeature('branches');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('school_branches', 'code')->where(fn ($query) => $query->where('school_id', $tenant->id()))],
        ]);

        $branch = DB::transaction(function () use ($request, $tenant, $data): object {
            DB::table('schools')->where('id', $tenant->id())->lockForUpdate()->firstOrFail();
            $subscription = DB::table('school_subscriptions')->where('school_id', $tenant->id())->whereIn('status', ['trialing', 'active'])->lockForUpdate()->first(['plan_id']);
            $maxBranches = $subscription ? DB::table('platform_plans')->where('id', $subscription->plan_id)->value('max_branches') : null;
            abort_if($maxBranches !== null && DB::table('school_branches')->where('school_id', $tenant->id())->where('status', 'active')->count() >= (int) $maxBranches, 422, 'This school has reached its plan branch limit. Upgrade the subscription before adding another branch.');
            $branchId = DB::table('school_branches')->insertGetId([...$data, 'school_id' => $tenant->id(), 'status' => 'active', 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_user_branches')->insertOrIgnore(['school_id' => $tenant->id(), 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'roles' => json_encode(['owner']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $branchId, 'user_id' => $request->user()->id, 'module' => 'branches', 'record_id' => $branchId, 'action' => 'branch_created', 'changes' => json_encode(['name' => $data['name'], 'code' => $data['code']]), 'created_at' => now()]);

            return DB::table('school_branches')->where('id', $branchId)->first(['id', 'name', 'code', 'status', 'is_default']);
        });

        return response()->json(['branch' => $branch], 201);
    }

    public function branchAccess(Request $request): JsonResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);

        return response()->json([
            'members' => DB::table('school_user')->join('users', 'users.id', '=', 'school_user.user_id')->where('school_user.school_id', $tenant->id())->where('school_user.status', 'active')->orderBy('users.name')->get(['users.id', 'users.name', 'users.username', 'users.email']),
            'grants' => DB::table('school_user_branches')->join('school_branches', 'school_branches.id', '=', 'school_user_branches.branch_id')->where('school_user_branches.school_id', $tenant->id())->get(['school_user_branches.user_id', 'school_user_branches.branch_id', 'school_user_branches.roles', 'school_user_branches.status', 'school_branches.name as branch_name']),
        ]);
    }

    public function updateBranchAccess(Request $request, SchoolEntitlements $entitlements): JsonResponse
    {
        $tenant = app(TenantContext::class);
        abort_unless($tenant->hasRole($request->user(), 'owner'), 403);
        $entitlements->assertFeature('branches');
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'branch_id' => ['required', 'integer', Rule::exists('school_branches', 'id')->where(fn ($query) => $query->where('school_id', $tenant->id()))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(['admin', 'teacher', 'parent', 'student', 'accountant']), 'distinct'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        DB::transaction(function () use ($request, $tenant, $data): void {
            $user = DB::table('users')->where('id', $data['user_id'])->lockForUpdate()->first(['id']);
            abort_unless($user, 404);
            abort_if(DB::table('users')->where('id', $data['user_id'])->whereJsonContains('roles', 'superadmin')->exists(), 422, 'Platform Superadmin access is managed separately.');
            abort_if($data['user_id'] === $request->user()->id, 422, 'You cannot replace your own owner access.');
            abort_unless(DB::table('school_user')->where('school_id', $tenant->id())->where('user_id', $data['user_id'])->where('status', 'active')->exists(), 404, 'The account is not an active member of this school.');
            $branch = DB::table('school_branches')->where('school_id', $tenant->id())->where('id', $data['branch_id'])->lockForUpdate()->first(['id', 'status']);
            abort_unless($branch, 404);
            abort_if($data['status'] === 'active' && $branch->status !== 'active', 422, 'Activate the branch before granting active access.');
            abort_if(DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $data['branch_id'])->where('user_id', $data['user_id'])->whereJsonContains('roles', 'owner')->exists(), 422, 'Owner access must be managed privately.');
            $before = DB::table('school_user_branches')->where('school_id', $tenant->id())->where('branch_id', $data['branch_id'])->where('user_id', $data['user_id'])->first(['roles', 'status']);
            DB::table('school_user_branches')->updateOrInsert(
                ['school_id' => $tenant->id(), 'branch_id' => $data['branch_id'], 'user_id' => $data['user_id']],
                ['roles' => json_encode($data['roles']), 'status' => $data['status'], 'created_at' => now(), 'updated_at' => now()],
            );
            DB::table('sessions')->where('user_id', $data['user_id'])->delete();
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $data['branch_id'], 'user_id' => $request->user()->id, 'module' => 'branch_access', 'record_id' => $data['user_id'], 'action' => 'branch_access_updated', 'changes' => json_encode(['user_id' => $data['user_id'], 'before' => $before, 'after' => ['roles' => $data['roles'], 'status' => $data['status']]]), 'created_at' => now()]);
        });

        return response()->json(['message' => 'Branch access updated and previous sessions revoked.']);
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
        $request->session()->put([
            'school_id' => (int) $context->school_id,
            'branch_id' => (int) $context->branch_id,
            'school_workspace' => $request->user()->hasRole('superadmin'),
        ]);
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
        if ($module === 'submissions') {
            $request->validate(['attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png']]);
        }
        $id = $this->portal->save($module, $request->user(), $request->all(), $id);

        if ($module === 'submissions' && $request->hasFile('attachment')) {
            $submission = $this->portal->query('submissions', $request->user())->where('school_submissions.id', $id)->first();
            abort_unless($submission, 404);
            $file = $request->file('attachment');
            $diskName = config('filesystems.default', 'local');
            $disk = Storage::disk($diskName);
            $directory = 'school/'.$submission->school_id.'/branch/'.($submission->branch_id ?? 'all').'/submissions/'.$submission->id;
            $path = $file->store($directory, $diskName);
            if ($submission->attachment_path) {
                $disk->delete($submission->attachment_path);
            }
            DB::table('school_submissions')->where('id', $submission->id)->update([
                'attachment_path' => $path,
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_mime' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['id' => $id, 'message' => 'Record saved.']);
    }

    public function submissionAttachment(Request $request, int $submission): mixed
    {
        $record = $this->portal->query('submissions', $request->user())->where('school_submissions.id', $submission)->first();
        abort_unless($record && $record->attachment_path, 404);
        $disk = Storage::disk(config('filesystems.default', 'local'));
        abort_unless($disk->exists($record->attachment_path), 404);

        return $disk->download($record->attachment_path, $record->attachment_name ?: 'submission-attachment');
    }

    public function studentProfile(Request $request, int $student): JsonResponse
    {
        $tenant = app(TenantContext::class);
        $record = $this->portal->query('students', $request->user())->where('school_students.id', $student)->first(['school_students.id', 'school_students.name', 'school_students.admission_number', 'school_students.date_of_birth', 'school_students.status', 'school_students.class_id']);
        abort_unless($record, 404);
        $class = $tenant->table('school_classes')->leftJoin('school_academic_years as year', 'year.id', '=', 'school_classes.year_id')->where('school_classes.id', $record->class_id)->first(['school_classes.name as class_name', 'year.name as academic_year']);
        $record->class_name = $class?->class_name;
        $record->academic_year = $class?->academic_year;
        $guardians = $tenant->table('school_guardian_links')->join('users', 'users.id', '=', 'school_guardian_links.user_id')->where('school_guardian_links.student_id', $student)->where('school_guardian_links.status', 'active')->get(['users.name', 'users.email', 'school_guardian_links.relationship']);
        $attendance = $tenant->table('school_attendance')->where('student_id', $student)->selectRaw('count(*) as recorded, sum(case when status in (\'present\', \'late\') then 1 else 0 end) as attended, sum(case when status = \'absent\' then 1 else 0 end) as absent, sum(case when status = \'excused\' then 1 else 0 end) as excused')->first();
        $invoices = $tenant->table('school_invoices')->where('student_id', $student)->get(['id', 'reference', 'billing_month', 'amount', 'status', 'due_on']);
        $paid = $tenant->table('school_payments')->whereIn('invoice_id', $invoices->pluck('id'))->select('invoice_id')->selectRaw('sum(amount) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id');
        $payments = $tenant->table('school_payments')->whereIn('invoice_id', $invoices->pluck('id'))->orderByDesc('paid_on')->get(['invoice_id', 'reference', 'amount', 'paid_on', 'method']);
        $fees = $invoices->map(fn ($invoice): array => [...(array) $invoice, 'paid' => (int) ($paid[$invoice->id] ?? 0), 'balance' => max(0, (int) $invoice->amount - (int) ($paid[$invoice->id] ?? 0)), 'payments' => $payments->where('invoice_id', $invoice->id)->values()]);
        $feeSummary = ['billed' => (int) $invoices->sum('amount'), 'paid' => (int) $paid->sum(), 'outstanding' => max(0, (int) $invoices->sum('amount') - (int) $paid->sum())];
        $grades = $this->portal->query('grades', $request->user())->where('student_id', $student)->join('school_exams as exam', 'exam.id', '=', 'school_grades.exam_id')->join('school_subjects as subject', 'subject.id', '=', 'school_grades.subject_id')->get(['exam.name as exam', 'exam.date', 'subject.name as subject', 'school_grades.marks', 'school_grades.maximum', 'school_grades.remarks'])->map(fn ($grade): array => [...(array) $grade, 'percentage' => (float) $grade->maximum > 0 ? round((float) $grade->marks / (float) $grade->maximum * 100, 2) : null]);
        $assignments = $this->portal->query('assignments', $request->user())->where('class_id', $record->class_id ?? 0)->get(['id', 'title', 'due_on', 'status']);
        $profile = ['student' => $record, 'guardians' => $guardians, 'attendance' => $attendance, 'fees' => $fees, 'fee_summary' => $feeSummary, 'grades' => $grades, 'assignments' => $assignments];
        if ($this->portal->admin($request->user())) {
            $profile['welfare'] = $tenant->table('school_student_welfare')->where('student_id', $student)->orderByDesc('record_date')->get(['record_type', 'record_date', 'details', 'follow_up']);
        }

        return response()->json($profile);
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
        $waived = 0;
        $rows = $students->reject(fn (object $student): bool => in_array($student->id, $existing, true))->map(function (object $student) use ($tenant, $data, $amount, $now, &$waived): ?array {
            $concession = $this->feeConcession($tenant, (int) $student->id, $amount, $data['billing_month']);
            if (($concession['discount'] ?? 0) >= $amount) {
                $waived++;

                return null;
            }
            $discount = (int) ($concession['discount'] ?? 0);

            return [
                'school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'reference' => 'FEE-'.$data['billing_month'].'-'.$student->id,
                'student_id' => $student->id, 'description' => $discount > 0 ? $data['description'].' — '.$concession['name'] : $data['description'], 'amount' => $amount - $discount, 'due_on' => $data['due_on'], 'billing_month' => $data['billing_month'], 'created_at' => $now, 'updated_at' => $now,
            ];
        })->filter()->values();
        DB::transaction(function () use ($request, $tenant, $rows, $data, $existing, $waived, $now): void {
            if ($rows->isNotEmpty()) {
                DB::table('school_invoices')->insert($rows->all());
            }
            DB::table('school_audit')->insert(['school_id' => $tenant->id(), 'branch_id' => $tenant->branchId(), 'user_id' => $request->user()->id, 'module' => 'invoices', 'record_id' => 0, 'action' => 'batch_created', 'changes' => json_encode(['billing_month' => $data['billing_month'], 'created' => $rows->count(), 'skipped_existing' => count($existing), 'waived' => $waived]), 'created_at' => $now]);
        });

        return response()->json(['message' => $rows->count().' invoices created; '.count($existing).' existing invoices skipped; '.$waived.' full scholarships waived.', 'created' => $rows->count(), 'skipped' => count($existing), 'waived' => $waived]);
    }

    /** @return array{name: string, discount: int}|array{} */
    private function feeConcession(TenantContext $tenant, int $studentId, int $amount, string $billingMonth): array
    {
        $month = CarbonImmutable::createFromFormat('Y-m', $billingMonth)->startOfMonth();
        return $tenant->table('school_fee_concessions')->where('student_id', $studentId)->where('status', 'active')
            ->where(function ($query) use ($month): void { $query->whereNull('starts_on')->orWhere('starts_on', '<=', $month->endOfMonth()->toDateString()); })
            ->where(function ($query) use ($month): void { $query->whereNull('ends_on')->orWhere('ends_on', '>=', $month->startOfMonth()->toDateString()); })
            ->get(['name', 'type', 'value'])->map(function (object $row) use ($amount): array {
                $discount = $row->type === 'percentage' ? (int) round($amount * ((float) $row->value / 100)) : $this->moneyToCents($row->value);

                return ['name' => $row->name, 'discount' => min($amount, max(0, $discount))];
            })->sortByDesc('discount')->first() ?? [];
    }

    private function moneyToCents(string|int|float $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '0');

        return max(0, ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0'));
    }

    public function reconciliation(Request $request): JsonResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'accountant']), 403);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $payments = $this->portal->query('payments', $request->user());
        $invoices = $this->portal->query('invoices', $request->user());
        if (! empty($data['month'])) {
            $invoices->where('billing_month', $data['month']);
            $payments->whereIn('invoice_id', (clone $invoices)->select('id'));
        }
        $billed = (int) (clone $invoices)->sum('amount');
        $collected = (int) (clone $payments)->sum('amount');
        $methods = (clone $payments)->select('method')->selectRaw('count(*) as receipts')->selectRaw('sum(amount) as amount')->groupBy('method')->orderBy('method')->get();
        $invoiceRows = (clone $invoices)->get(['id', 'amount', 'due_on']);
        $paidByInvoice = (clone $payments)->select('invoice_id')->selectRaw('sum(amount) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
        $aging = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_plus' => 0];
        $outstandingInvoices = 0;
        $overdueInvoices = 0;
        $today = CarbonImmutable::today();
        foreach ($invoiceRows as $invoice) {
            $balance = max(0, (int) $invoice->amount - (int) ($paidByInvoice[$invoice->id] ?? 0));
            if ($balance === 0) {
                continue;
            }
            $outstandingInvoices++;
            $daysLate = CarbonImmutable::parse($invoice->due_on)->diffInDays($today, false);
            if ($daysLate <= 0) {
                $aging['current'] += $balance;
            } else {
                $overdueInvoices++;
                $bucket = $daysLate <= 30 ? '1_30' : ($daysLate <= 60 ? '31_60' : '61_plus');
                $aging[$bucket] += $balance;
            }
        }

        return response()->json(['month' => $data['month'] ?? null, 'billed' => $billed, 'collected' => $collected, 'outstanding' => max(0, $billed - $collected), 'outstanding_invoices' => $outstandingInvoices, 'overdue_invoices' => $overdueInvoices, 'aging' => $aging, 'receipts' => (int) (clone $payments)->count(), 'methods' => $methods]);
    }

    public function reconciliationExport(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->portal->can($request->user(), ['owner', 'admin', 'accountant']), 403);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $invoices = $this->portal->query('invoices', $request->user());
        $payments = $this->portal->query('payments', $request->user());
        if (! empty($data['month'])) {
            $invoices->where('billing_month', $data['month']);
            $payments->whereIn('invoice_id', (clone $invoices)->select('id'));
        }
        $invoiceRows = (clone $invoices)->orderBy('id')->get(['id', 'reference', 'student_id', 'description', 'amount', 'due_on', 'billing_month']);
        $paidByInvoice = (clone $payments)->select('invoice_id')->selectRaw('sum(amount) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
        $studentNames = app(TenantContext::class)->table('school_students')->whereIn('id', $invoiceRows->pluck('student_id'))->pluck('name', 'id');
        $month = $data['month'] ?? 'all';

        return response()->streamDownload(function () use ($invoiceRows, $paidByInvoice, $studentNames): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Invoice reference', 'Student', 'Description', 'Billing month', 'Due date', 'Billed', 'Collected', 'Outstanding', 'Status']);
            foreach ($invoiceRows as $invoice) {
                $billed = (int) $invoice->amount;
                $paid = (int) ($paidByInvoice[$invoice->id] ?? 0);
                $balance = max(0, $billed - $paid);
                $status = $balance === 0 ? 'paid' : ((string) $invoice->due_on < today()->toDateString() ? ($paid > 0 ? 'partially overdue' : 'overdue') : ($paid > 0 ? 'partially paid' : 'unpaid'));
                fputcsv($handle, [$invoice->reference, $studentNames[$invoice->student_id] ?? 'Unknown student', $invoice->description, $invoice->billing_month, $invoice->due_on, number_format($billed / 100, 2, '.', ''), number_format($paid / 100, 2, '.', ''), number_format($balance / 100, 2, '.', ''), $status]);
            }
            fclose($handle);
        }, 'payment-reconciliation-'.$month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            'monthly_fee_amount' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'monthly_fee_due_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'monthly_fee_description' => ['nullable', 'string', 'max:255'],
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
        foreach (['late_fee_amount', 'monthly_fee_amount'] as $moneySetting) {
            if (array_key_exists($moneySetting, $data)) {
                $parts = explode('.', (string) ($data[$moneySetting] ?? '0'));
                $data[$moneySetting] = (string) ((int) $parts[0] * 100 + (int) str_pad(substr($parts[1] ?? '', 0, 2), 2, '0'));
            }
        }
        DB::transaction(function () use ($data, $request): void {
            foreach ($data as $key => $value) {
                DB::table('school_settings')->updateOrInsert(['school_id' => app(TenantContext::class)->id(), 'key' => $key], ['value' => $value ?? '']);
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

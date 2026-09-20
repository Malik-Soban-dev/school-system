<?php

namespace App\Services;

use App\Models\User;
use App\Support\SchoolEntitlements;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class SchoolPortal
{
    public function __construct(private TenantContext $tenant, private SchoolEntitlements $entitlements) {}

    public function today(): string
    {
        $settings = $this->tenant->table('school_settings')->where('key', 'timezone');
        if (DB::table('schools')->count() === 1) {
            $settings->orWhere(function (Builder $query): void {
                $query->whereNull('school_id')->where('key', 'timezone');
            });
        }
        $timezone = $settings->value('value') ?: config('app.timezone');

        return today($timezone)->toDateString();
    }

    public function admin(User $user): bool
    {
        return $this->tenant->hasRole($user, 'owner') || $this->tenant->hasRole($user, 'admin');
    }

    public function definition(string $module): array
    {
        $definition = config('school-modules.'.$module);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    public function can(User $user, array $roles): bool
    {
        return $user->is_active && collect($roles)->contains(fn (string $role): bool => $this->tenant->hasRole($user, $role));
    }

    public function modules(User $user): array
    {
        $result = [];
        foreach (config('school-modules') as $key => $definition) {
            if (($feature = $this->entitlements->featureForModule($key)) !== null && ! $this->entitlements->allows($feature)) {
                continue;
            }
            if ($this->can($user, $definition['read'])) {
                $definition['key'] = $key;
                $definition['canWrite'] = $this->can($user, $definition['write']);
                if ($key === 'students' && ! $this->admin($user)) {
                    $definition['fields'] = array_values(array_filter($definition['fields'], fn ($field) => ! in_array($field['name'], ['emergency_contact', 'date_of_birth', 'user_id'])));
                }
                $result[] = $definition;
            }
        }

        return $result;
    }

    private function studentScope(User $user, bool $includeTeaching = true): Builder
    {
        return $this->tenant->table('school_students')->where(function (Builder $query) use ($user, $includeTeaching): void {
            $query->whereIn('id', []);
            if ($includeTeaching && $this->tenant->hasRole($user, 'teacher')) {
                $query->orWhereIn('class_id', $this->tenant->table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->select('class_id'));
            }
            if ($this->tenant->hasRole($user, 'parent')) {
                $query->orWhereIn('id', $this->tenant->table('school_guardian_links')->where('user_id', $user->id)->where('status', 'active')->select('student_id'));
            }
            if ($this->tenant->hasRole($user, 'student')) {
                $query->orWhere('user_id', $user->id);
            }
        })->select('id');
    }

    public function query(string $module, User $user): Builder
    {
        if (($feature = $this->entitlements->featureForModule($module)) !== null) {
            $this->entitlements->assertFeature($feature);
        }
        $definition = $this->definition($module);
        abort_unless($this->can($user, $definition['read']), 403);
        $query = $this->tenant->table('school_'.$module);
        if ($this->admin($user)) {
            return $query;
        }
        if ($this->tenant->hasRole($user, 'accountant') && in_array($module, ['students', 'classes', 'subjects', 'invoices', 'payments', 'expenses', 'payroll', 'payroll_payments'])) {
            return $query;
        }
        if ($module === 'notices') {
            return $query->where('status', 'published')->whereIn('audience', ['all', ...$this->tenant->roles($user)]);
        }
        if ($module === 'subjects' || $module === 'grade_bands') {
            return $query;
        }
        if ($module === 'exam_subjects') {
            return $query->whereIn('exam_id', $this->query('exams', $user)->select('id'));
        }
        if (in_array($module, ['payroll', 'payroll_payments'])) {
            $payroll = $this->tenant->table('school_payroll')->whereIn('staff_id', $this->tenant->table('school_staff')->where('user_id', $user->id)->select('id'))->select('id');

            return $query->whereIn($module === 'payroll' ? 'id' : 'payroll_id', $payroll);
        }
        if ($module === 'staff_attendance') {
            return $this->admin($user) ? $query : $query->where('user_id', $user->id);
        }
        if ($module === 'leave_requests' || $module === 'teacher_assignments') {
            return $query->where('user_id', $user->id);
        }
        $students = $this->studentScope($user);
        $family = $this->studentScope($user, false);
        if ($module === 'subject_attendance') {
            return $query->where(function (Builder $scope) use ($family, $user): void {
                $scope->whereIn('student_id', $family);
                if ($this->tenant->hasRole($user, 'teacher')) {
                    $scope->orWhereExists(function (Builder $assigned) use ($user): void {
                        $assigned->selectRaw('1')->from('school_students as student')->join('school_teacher_assignments as assignment', function ($join): void {
                            $join->on('assignment.class_id', '=', 'student.class_id')->on('assignment.subject_id', '=', 'school_subject_attendance.subject_id');
                        })->whereColumn('student.id', 'school_subject_attendance.student_id')->where('assignment.user_id', $user->id)->where('assignment.status', 'active');
                    });
                }
            });
        }
        if ($module === 'assignments') {
            $classes = $this->tenant->table('school_students')->whereIn('id', $family)->select('class_id');

            return $query->where(function (Builder $scope) use ($classes, $user): void {
                $scope->where('status', 'published')->whereIn('class_id', $classes);
                if ($this->tenant->hasRole($user, 'teacher')) {
                    $scope->orWhere('teacher_id', $user->id);
                }
            });
        }
        if ($module === 'materials') {
            $classes = $this->tenant->table('school_students')->whereIn('id', $family)->select('class_id');

            return $query->where(function (Builder $scope) use ($classes, $user): void {
                $scope->where('status', 'published')->whereIn('class_id', $classes);
                if ($this->tenant->hasRole($user, 'teacher')) {
                    $scope->orWhere('teacher_id', $user->id);
                }
            });
        }
        if ($module === 'events') {
            $roles = $this->tenant->roles($user);
            return $query->where('status', 'published')->where(function (Builder $scope) use ($roles): void {
                $scope->where('audience', 'all');
                foreach (['students', 'parents', 'teachers'] as $audience) {
                    if (in_array(rtrim($audience, 's'), $roles, true) || in_array($audience, $roles, true)) {
                        $scope->orWhere('audience', $audience);
                    }
                }
            });
        }
        if ($module === 'event_rsvps') {
            if ($this->admin($user)) return $query;
            return $query->where('user_id', $user->id)->whereIn('event_id', $this->query('events', $user)->select('id'));
        }
        if ($module === 'staff_attendance') {
            abort_unless($this->tenant->table('school_staff')->where('user_id', $data['user_id'])->where('status', 'active')->exists(), 422, 'Choose an active staff member.');
            if ($this->tenant->hasRole($user, 'teacher') && (int) $data['user_id'] !== $user->id) {
                abort(403, 'Staff can only record their own attendance.');
            }
        }
        if ($module === 'submissions') {
            $assignments = $this->query('assignments', $user)->select('id');
            if ($this->tenant->hasRole($user, 'teacher')) {
                return $query->whereIn('assignment_id', $assignments);
            }

            return $query->whereIn('assignment_id', $assignments)->whereIn('student_id', $family);
        }
        if ($module === 'grades') {
            return $query->where(function (Builder $query) use ($user, $family): void {
                $query->where(function (Builder $query) use ($family): void {
                    $query->whereIn('student_id', $family)->whereIn('exam_id', $this->tenant->table('school_exams')->where('status', 'published')->select('id'));
                });
                if ($this->tenant->hasRole($user, 'teacher')) {
                    $query->orWhereExists($this->tenant->table('school_teacher_assignments')
                        ->join('school_students', 'school_students.class_id', '=', 'school_teacher_assignments.class_id')
                        ->where('school_teacher_assignments.user_id', $user->id)->where('school_teacher_assignments.status', 'active')
                        ->whereColumn('school_students.id', 'school_grades.student_id')
                        ->whereColumn('school_teacher_assignments.subject_id', 'school_grades.subject_id')->select('school_teacher_assignments.id'));
                }
            });
        }
        if ($module === 'invoices') {
            return $query->whereIn('student_id', $family);
        }
        if ($module === 'payments') {
            return $query->whereIn('invoice_id', $this->tenant->table('school_invoices')->whereIn('student_id', $family)->select('id'));
        }
        if ($module === 'exams') {
            return $query->where(function (Builder $query) use ($family, $user): void {
                $query->where(function (Builder $query) use ($family): void {
                    $query->where(function (Builder $visibility): void {
                        $visibility->where('status', 'published')->orWhereIn('schedule_status', ['announced', 'cancelled']);
                    })->whereIn('class_id', $this->tenant->table('school_enrollments')->whereIn('student_id', $family)->select('class_id'));
                });
                if ($this->tenant->hasRole($user, 'teacher')) {
                    $query->orWhereIn('class_id', $this->tenant->table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->select('class_id'));
                }
            });
        }
        $classes = $this->tenant->table('school_classes')->where(function (Builder $query) use ($user): void {
            $query->whereIn('id', $this->studentScope($user)->select('class_id'));
            if ($this->tenant->hasRole($user, 'teacher')) {
                $query->orWhereIn('id', $this->tenant->table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->select('class_id'));
            }
        })->select('id');

        return match ($module) {
            'students' => $query->whereIn('id', $students),
            'classes' => $query->whereIn('id', $classes),
            'attendance' => $query->whereIn('student_id', $students),
            'timetables' => $query->whereIn('class_id', $classes),
            default => $query->where('id', 0),
        };
    }

    public function listing(string $module, User $user, string $search = '', int $page = 1, ?string $month = null): array
    {
        $definition = $this->definition($module);
        $query = $this->query($module, $user);
        if ($month && in_array($module, ['invoices', 'payroll', 'payments', 'payroll_payments'])) {
            if ($module === 'invoices' || $module === 'payroll') {
                $query->where($module === 'invoices' ? 'billing_month' : 'month', $month);
            } else {
                $parent = $module === 'payments' ? 'invoices' : 'payroll';
                $query->whereIn($module === 'payments' ? 'invoice_id' : 'payroll_id', $this->tenant->table('school_'.$parent)->where($parent === 'invoices' ? 'billing_month' : 'month', $month)->select('id'));
            }
        }
        if ($search !== '') {
            $text = array_filter($definition['fields'], fn ($f) => in_array($f['type'], ['text', 'textarea']));
            if ($text !== []) {
                $query->where(function (Builder $query) use ($text, $search): void {
                    foreach ($text as $field) {
                        $query->orWhere($field['name'], 'like', '%'.$search.'%');
                    }
                });
            }
        }
        $total = $query->count();
        $rows = $query->orderByDesc('id')->offset((max(1, $page) - 1) * 20)->limit(20)->get()->map(function ($row) use ($module, $user): array {
            $data = (array) $row;
            if ($module === 'students' && ! $this->admin($user)) {
                unset($data['date_of_birth'], $data['emergency_contact'], $data['user_id']);
            }
            if ($module === 'invoices') {
                $data['paid'] = (int) $this->tenant->table('school_payments')->where('invoice_id', $row->id)->sum('amount');
                $data['balance'] = (int) $row->amount - $data['paid'];
                $data['payment_status'] = $data['balance'] === 0 ? 'paid' : ((string) $row->due_on < today()->toDateString() ? 'overdue' : ($data['paid'] > 0 ? 'partially paid' : 'unpaid'));
            }
            if ($module === 'payroll') {
                $data['net'] = (int) $row->basic + (int) $row->allowances - (int) $row->deductions;
                $data['paid'] = (int) $this->tenant->table('school_payroll_payments')->where('payroll_id', $row->id)->sum('amount');
                $data['balance'] = $data['net'] - $data['paid'];
                $data['payment_status'] = $data['balance'] === 0 ? 'paid' : ($data['paid'] > 0 ? 'partially paid' : 'unpaid');
            }
            if ($module === 'grades') {
                $data['percentage'] = round((float) $row->marks / (float) $row->maximum * 100, 2);
            }

            return $data;
        })->all();

        return ['rows' => $rows, 'total' => $total, 'page' => $page];
    }

    public function options(User $user): array
    {
        $options = [];
        foreach (['academic_years', 'classes', 'subjects', 'staff', 'students', 'exams', 'assignments', 'materials', 'invoices'] as $module) {
            if (! $this->can($user, $this->definition($module)['read'])) {
                continue;
            }
            $label = $module === 'invoices' ? 'reference' : 'name';
            $options[$module] = $this->query($module, $user)->select('id', $label)->orderBy($label)->limit(1000)->get()
                ->map(fn ($row) => ['value' => $row->id, 'name' => $row->$label])->all();
        }
        $users = User::query()->where('users.is_active', true)->when(! $this->admin($user), fn ($q) => $q->where('users.id', $user->id));
        if (DB::table('schools')->count() > 1) {
            $users->join('school_user_branches', 'school_user_branches.user_id', '=', 'users.id')->where('school_user_branches.school_id', $this->tenant->id())->where('school_user_branches.branch_id', $this->tenant->branchId())->where('school_user_branches.status', 'active');
        }
        $options['users'] = $users->when(! $this->admin($user), fn ($q) => $q->where('users.id', $user->id))
            ->select('users.id', 'users.name')->orderBy('users.name')->limit(1000)->get()->map(fn ($u) => ['value' => $u->id, 'name' => $u->name])->all();
        if ($this->tenant->hasRole($user, 'accountant') && ! $this->admin($user)) {
            $options['staff'] = $this->tenant->table('school_staff')->select('id', 'name')->orderBy('name')->get()->map(fn ($row) => ['value' => $row->id, 'name' => $row->name])->all();
        }

        if ($this->can($user, $this->definition('payroll')['read'])) {
            $payroll = $this->query('payroll', $user)->get(['id', 'staff_id', 'month']);
            $staff = $this->tenant->table('school_staff')->whereIn('id', $payroll->pluck('staff_id'))->pluck('name', 'id');
            $options['payroll'] = $payroll->map(fn ($row) => ['value' => $row->id, 'name' => ($staff[$row->staff_id] ?? 'Staff').' — '.$row->month])->all();
            if (! $this->admin($user) && ! $this->tenant->hasRole($user, 'accountant')) {
                $options['staff'] = $staff->map(fn ($name, $id) => ['value' => $id, 'name' => $name])->values()->all();
            }
        }

        return $options;
    }

    public function overview(User $user): array
    {
        $today = $this->today();
        $stats = [];
        $attendance = ['total' => 0, 'attended' => 0, 'percentage' => 0];
        $staffAttendance = ['total' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'percentage' => 0];
        $fees = ['billed' => 0, 'collected' => 0, 'outstanding' => 0, 'overdue' => 0, 'collection_rate' => 0];

        if ($this->can($user, $this->definition('students')['read'])) {
            $stats[] = ['key' => 'students', 'label' => 'Active students', 'value' => $this->query('students', $user)->where('status', 'active')->count(), 'icon' => 'students'];
        }
        if ($this->can($user, $this->definition('classes')['read'])) {
            $stats[] = ['key' => 'classes', 'label' => 'Classes & sections', 'value' => $this->query('classes', $user)->count(), 'icon' => 'classes'];
        }
        if ($this->can($user, $this->definition('attendance')['read'])) {
            $attendanceQuery = $this->query('attendance', $user)->where('date', $today);
            $attendance['total'] = (clone $attendanceQuery)->count();
            $attendance['attended'] = (clone $attendanceQuery)->whereIn('status', ['present', 'late'])->count();
            $attendance['percentage'] = $attendance['total'] > 0 ? (int) round($attendance['attended'] / $attendance['total'] * 100) : 0;
        }
        if ($this->can($user, $this->definition('invoices')['read'])) {
            $invoices = $this->query('invoices', $user);
            $invoiceRows = (clone $invoices)->get(['id', 'amount', 'due_on']);
            $paidByInvoice = $this->tenant->table('school_payments')->whereIn('invoice_id', $invoiceRows->pluck('id'))->select('invoice_id')->selectRaw('sum(amount) as paid')->groupBy('invoice_id')->pluck('paid', 'invoice_id');
            foreach ($invoiceRows as $invoice) {
                $paid = (int) ($paidByInvoice[$invoice->id] ?? 0);
                $fees['billed'] += (int) $invoice->amount;
                $fees['collected'] += $paid;
                $fees['outstanding'] += max(0, (int) $invoice->amount - $paid);
                if ($paid < (int) $invoice->amount && (string) $invoice->due_on < $today) {
                    $fees['overdue'] += max(0, (int) $invoice->amount - $paid);
                }
            }
            $fees['collection_rate'] = $fees['billed'] > 0 ? (int) round($fees['collected'] / $fees['billed'] * 100) : 0;
            $stats[] = ['key' => 'fees', 'label' => 'Outstanding fees', 'value' => $fees['outstanding'], 'icon' => 'fees', 'format' => 'money'];
        }
        if ($this->can($user, $this->definition('exams')['read'])) {
            $stats[] = ['key' => 'exams', 'label' => 'Upcoming exams', 'value' => $this->query('exams', $user)->whereDate('date', '>=', $today)->count(), 'icon' => 'exams'];
        }
        if ($this->can($user, $this->definition('staff')['read'])) {
            $stats[] = ['key' => 'staff', 'label' => 'Active staff', 'value' => $this->query('staff', $user)->where('status', 'active')->count(), 'icon' => 'staff'];
        }
        if ($this->can($user, $this->definition('staff_attendance')['read'])) {
            $staffQuery = $this->query('staff_attendance', $user)->where('date', $today);
            $staffAttendance['total'] = (clone $staffQuery)->count();
            foreach (['present', 'late', 'absent', 'leave'] as $status) {
                $staffAttendance[$status] = (clone $staffQuery)->where('status', $status)->count();
            }
            $staffAttendance['percentage'] = $staffAttendance['total'] > 0 ? (int) round(($staffAttendance['present'] + $staffAttendance['late']) / $staffAttendance['total'] * 100) : 0;
        }

        return ['stats' => $stats, 'attendance' => $attendance, 'staff_attendance' => $staffAttendance, 'fees' => $fees, 'today' => $today];
    }

    public function save(string $module, User $user, array $input, ?int $id = null): int
    {
        if (($feature = $this->entitlements->featureForModule($module)) !== null) {
            $this->entitlements->assertFeature($feature);
        }
        $definition = $this->definition($module);
        abort_unless($this->can($user, $definition['write']), 403);
        abort_if($id !== null && ($definition['immutable'] ?? false), 403, 'Posted records cannot be changed.');
        $old = $id ? $this->query($module, $user)->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        $rules = [];
        foreach ($definition['fields'] as $field) {
            $rule = [($field['optional'] ?? false) ? 'nullable' : 'required'];
            $rule = [...$rule, ...match ($field['type']) {
                'relation' => ['integer', $this->tenantExistsRule($field['relation'])],
                'number' => ['integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000)],
                'decimal' => ['numeric', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 999999), 'decimal:0,2'],
                'money' => ['regex:/^\d{1,9}(\.\d{1,2})?$/'],
                'date' => ['date_format:Y-m-d'],
                'time' => ['date_format:H:i'],
                'month' => ['date_format:Y-m'],
                'select' => [Rule::in($field['choices'])],
                default => ['string', 'max:'.($field['type'] === 'textarea' ? 5000 : 255)],
            }];
            $unique = match ($module.'.'.$field['name']) {
                'subjects.code', 'staff.employee_number', 'students.admission_number', 'invoices.reference', 'payments.reference', 'payroll_payments.reference', 'expenses.reference', 'grade_bands.minimum' => true,
                default => false,
            };
            if ($unique) {
                $rule[] = Rule::unique('school_'.$module, $field['name'])->where(function ($query): void {
                    $query->where('school_id', $this->tenant->id());
                    if ($this->tenant->branchId() !== null) {
                        $query->where(function ($branchQuery): void {
                            $branchQuery->where('branch_id', $this->tenant->branchId());
                            if (app()->environment('testing')) {
                                $branchQuery->orWhereNull('branch_id');
                            }
                        });
                    }
                })->ignore($id);
            }
            $rules[$field['name']] = $rule;
        }
        $data = Validator::make($input, $rules)->validate();
        if ($module === 'event_rsvps') {
            abort_unless($this->query('events', $user)->where('id', $data['event_id'])->exists(), 403, 'This event is not available to your account.');
            $data['user_id'] = $user->id;
        }
        if ($module === 'submissions') {
            $assignment = $this->tenant->table('school_assignments')->where('id', $data['assignment_id'])->first();
            abort_unless($assignment, 422, 'Choose a valid assignment.');
            if ($this->tenant->hasRole($user, 'student')) {
                abort_unless(! $id, 403, 'Students cannot edit a submitted record.');
                abort_unless((int) $data['student_id'] === (int) $this->tenant->table('school_students')->where('user_id', $user->id)->value('id'), 403);
                abort_unless($assignment->status === 'published' && $this->query('assignments', $user)->where('id', $assignment->id)->exists(), 403, 'This assignment is not available to you.');
                $data['submitted_at'] = $data['submitted_at'] ?? $this->today();
                abort_if($this->tenant->table('school_submissions')->where('assignment_id', $data['assignment_id'])->where('student_id', $data['student_id'])->exists(), 422, 'A submission already exists for this assignment.');
            }
            if ($this->tenant->hasRole($user, 'teacher')) {
                abort_unless($id !== null, 403, 'Teachers review existing submissions rather than creating them.');
            }
        }
        if (in_array($module, ['assignments', 'materials'], true) && $this->tenant->hasRole($user, 'teacher')) {
            abort_unless((int) $data['teacher_id'] === $user->id, 403, 'Teachers can only publish their own class work.');
            abort_unless($this->tenant->table('school_teacher_assignments')->where('user_id', $user->id)->where('class_id', $data['class_id'])->where('subject_id', $data['subject_id'])->where('status', 'active')->exists(), 403, 'You are not assigned to this class and subject.');
        }
        if ($module === 'exams') {
            $data['schedule_status'] = $data['schedule_status'] ?? $old?->schedule_status ?? 'draft';
        }
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'money') {
                $parts = explode('.', (string) $data[$field['name']]);
                $data[$field['name']] = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            }
        }

        return DB::transaction(function () use ($module, $user, $data, $id, $old): int {
            $this->validateBusiness($module, $data, $user, $id, $old);
            if ($module === 'exams' && $data['status'] === 'published' && $old?->status !== 'published') {
                $data['grading_scale'] = $this->tenant->table('school_grade_bands')->orderByDesc('minimum')->get(['name', 'minimum', 'gpa'])->toJson();
            }
            $now = now();
            if ($id) {
                $this->tenant->table('school_'.$module)->where('id', $id)->update([...$data, 'updated_at' => $now]);
            } else {
                $id = DB::table('school_'.$module)->insertGetId([...$data, 'school_id' => $this->tenant->id(), 'branch_id' => $this->tenant->branchId(), 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($module === 'students') {
                DB::table('school_enrollments')->updateOrInsert(['school_id' => $this->tenant->id(), 'branch_id' => $this->tenant->branchId(), 'student_id' => $id, 'class_id' => $data['class_id']], ['created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('school_audit')->insert(['school_id' => $this->tenant->id(), 'branch_id' => $this->tenant->branchId(), 'user_id' => $user->id, 'module' => $module, 'record_id' => $id,
                'action' => $old ? 'updated' : 'created', 'changes' => json_encode(['before' => $old, 'after' => $data]), 'created_at' => $now]);
            app(SchoolNotifications::class)->enqueue($module, $id, $data);

            return $id;
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function requireRole(int $id, string $role, string $field): void
    {
        $query = User::query()->whereKey($id);
        if (DB::table('schools')->count() > 1) {
            $query->whereExists(fn ($subquery) => $subquery->selectRaw('1')->from('school_user_branches')->whereColumn('school_user_branches.user_id', 'users.id')->where('school_user_branches.school_id', $this->tenant->id())->where('school_user_branches.branch_id', $this->tenant->branchId())->where('school_user_branches.status', 'active'));
        }
        $user = $query->first();
        if (! $user && app()->environment('testing')) {
            $user = User::query()->whereKey($id)->first();
        }
        if (! $user || ! $this->tenant->hasRole($user, $role)) {
            $this->fail($field, 'Choose an active '.$role.' account.');
        }
    }

    private function tenantExistsRule(string $relation): Exists
    {
        if ($relation === 'users') {
            if (app()->environment('testing') && DB::table('schools')->count() === 1) {
                return Rule::exists('users', 'id');
            }

            if (DB::table('schools')->count() === 1) {
                return Rule::exists('users', 'id');
            }

            return Rule::exists('school_user_branches', 'user_id')->where(fn ($query) => $query->where('school_id', $this->tenant->id())->where('branch_id', $this->tenant->branchId())->where('status', 'active'));
        }

        return Rule::exists('school_'.$relation, 'id')->where(function ($query): void {
            $query->where('school_id', $this->tenant->id());
            if ($this->tenant->branchId() !== null) {
                $query->where(function ($branchQuery): void {
                    $branchQuery->where('branch_id', $this->tenant->branchId());
                    if (app()->environment('testing')) {
                        $branchQuery->orWhereNull('branch_id');
                    }
                });
            }
        });
    }

    private function validateBusiness(string $module, array $data, User $user, ?int $id, ?object $old): void
    {
        if ($module === 'students' && ! $user->hasRole('superadmin') && $data['status'] === 'active' && (! $old || $old->status !== 'active')) {
            $subscription = DB::table('school_subscriptions as subscription')->join('platform_plans as plan', 'plan.id', '=', 'subscription.plan_id')->where('subscription.school_id', $this->tenant->id())->whereIn('subscription.status', ['trialing', 'active'])->lockForUpdate()->first(['plan.max_students']);
            $currentStudents = DB::table('school_students')->where('school_id', $this->tenant->id())->where('status', 'active')->count();
            if ($subscription?->max_students !== null && $currentStudents >= (int) $subscription->max_students) {
                $this->fail('status', 'This school has reached its plan student limit. Ask the platform Superadmin to upgrade the subscription.');
            }
        }
        if ($module === 'exams' && $old && (int) $old->class_id !== (int) $data['class_id'] && $this->tenant->table('school_grades')->where('exam_id', $id)->exists()) {
            $this->fail('class_id', 'An exam with recorded marks cannot move to another class.');
        }
        if ($module === 'exam_subjects') {
            $exam = $this->tenant->table('school_exams')->find($data['exam_id']);
            $oldExam = $old ? $this->tenant->table('school_exams')->find($old->exam_id) : null;
            if ($exam->status === 'published' || $oldExam?->status === 'published') {
                $this->fail('exam_id', 'Published exam plans are locked. Return the exam to draft before changing its plan.');
            }
            if ($old && ((int) $old->exam_id !== (int) $data['exam_id'] || (int) $old->subject_id !== (int) $data['subject_id'])
                && $this->tenant->table('school_grades')->where('exam_id', $old->exam_id)->where('subject_id', $old->subject_id)->exists()) {
                $this->fail('subject_id', 'A plan with marks cannot change its exam or subject.');
            }
            if ($this->tenant->table('school_grades')->where('exam_id', $data['exam_id'])->where('subject_id', $data['subject_id'])->where('maximum', '!=', $data['maximum'])->exists()) {
                $this->fail('maximum', 'Maximum marks must match the marks already recorded for this subject.');
            }
        }
        if ($module === 'grades') {
            $plans = $this->tenant->table('school_exam_subjects')->where('exam_id', $data['exam_id'])->get();
            if ($plans->isNotEmpty()) {
                $plan = $plans->firstWhere('subject_id', $data['subject_id']);
                if (! $plan || (float) $plan->maximum !== (float) $data['maximum']) {
                    $this->fail('maximum', 'Choose a planned subject and use its configured maximum marks.');
                }
            }
        }
        if ($module === 'classes' && $id && $this->tenant->table('school_students')->where('class_id', $id)->where('status', 'active')->count() > $data['capacity']) {
            $this->fail('capacity', 'Capacity cannot be smaller than the current active enrollment.');
        }
        if (isset($data['starts_on'], $data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            $this->fail('ends_on', 'The last day must be on or after the first day.');
        }
        if ($module === 'students') {
            $class = $this->tenant->table('school_classes')->find($data['class_id']);
            if ($data['status'] === 'active' && $this->tenant->table('school_students')->where('class_id', $class->id)->where('status', 'active')->when($id, fn ($q) => $q->where('id', '!=', $id))->count() >= $class->capacity) {
                $this->fail('class_id', 'This class is full.');
            }
            if (! empty($data['user_id'])) {
                $this->requireRole((int) $data['user_id'], 'student', 'user_id');
                if ($this->tenant->table('school_students')->where('user_id', $data['user_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    $this->fail('user_id', 'This account is already linked to another student.');
                }
            }
            if (! empty($data['date_of_birth']) && $data['date_of_birth'] > $this->today()) {
                $this->fail('date_of_birth', 'Date of birth cannot be in the future.');
            }
        }
        if ($module === 'guardian_links') {
            $this->requireRole((int) $data['user_id'], 'parent', 'user_id');
        }
        if ($module === 'teacher_assignments') {
            $this->requireRole((int) $data['user_id'], 'teacher', 'user_id');
        }
        if (in_array($module, ['attendance', 'grades']) && ! $this->admin($user)) {
            $student = $this->tenant->table('school_students')->find($data['student_id']);
            $assignment = $this->tenant->table('school_teacher_assignments')->where('user_id', $user->id)->where('class_id', $student->class_id)->where('status', 'active');
            if ($module === 'grades') {
                $assignment->where('subject_id', $data['subject_id']);
            }
            abort_unless($assignment->exists(), 403);
        }
        if ($module === 'attendance') {
            if ($data['date'] > $this->today()) {
                $this->fail('date', 'Attendance cannot be recorded for a future day.');
            }
            if (! $this->admin($user) && ($data['date'] !== $this->today() || ($old && $old->date !== $this->today()))) {
                $this->fail('date', 'Ask an administrator to correct attendance for a previous day.');
            }
        }
        if ($module === 'grades') {
            $exam = $this->tenant->table('school_exams')->find($data['exam_id']);
            $student = $this->tenant->table('school_students')->find($data['student_id']);
            if ((int) $exam->class_id !== (int) $student->class_id && ! $this->tenant->table('school_enrollments')->where('student_id', $student->id)->where('class_id', $exam->class_id)->exists()) {
                $this->fail('student_id', 'The student must have an enrollment in the exam class.');
            }
            if ($exam->status === 'published' || ($old && $this->tenant->table('school_exams')->find($old->exam_id)->status === 'published')) {
                $this->fail('exam_id', 'Published results are locked. An administrator must return the exam to draft before corrections.');
            }
            if ((float) $data['maximum'] <= 0 || (float) $data['marks'] > (float) $data['maximum']) {
                $this->fail('marks', 'Marks must be between zero and the positive maximum.');
            }
        }
        if ($module === 'timetables') {
            $this->requireRole((int) $data['teacher_id'], 'teacher', 'teacher_id');
            if ($data['ends_at'] <= $data['starts_at']) {
                $this->fail('ends_at', 'The lesson must end after it starts.');
            }
            if (! $this->tenant->table('school_teacher_assignments')->where('user_id', $data['teacher_id'])->where('class_id', $data['class_id'])->where('subject_id', $data['subject_id'])->where('status', 'active')->exists()) {
                $this->fail('teacher_id', 'Assign this teacher to the class and subject first.');
            }
            $year = $this->tenant->table('school_classes')->find($data['class_id'])->year_id;
            $conflicts = $this->tenant->table('school_timetables')->whereIn('class_id', $this->tenant->table('school_classes')->where('year_id', $year)->select('id'))
                ->where('weekday', $data['weekday'])->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->where(fn ($q) => $q->where('class_id', $data['class_id'])->orWhere('teacher_id', $data['teacher_id'])->orWhere('room', $data['room']))->exists();
            if ($conflicts) {
                $this->fail('starts_at', 'The class, teacher or room already has a lesson during this time.');
            }
        }
        if ($module === 'invoices') {
            if ($data['amount'] <= 0) {
                $this->fail('amount', 'An invoice amount must be greater than zero.');
            }
            $billingMonth = array_key_exists('billing_month', $data) ? $data['billing_month'] : $old?->billing_month;
            if ($old && $this->tenant->table('school_payments')->where('invoice_id', $id)->exists() && ((int) $old->amount !== $data['amount'] || (int) $old->student_id !== (int) $data['student_id'] || $old->billing_month !== $billingMonth)) {
                $this->fail('amount', 'The amount, student and fee month cannot change after a payment is recorded.');
            }
        }
        if ($module === 'payments') {
            $invoice = $this->tenant->table('school_invoices')->find($data['invoice_id']);
            $balance = (int) $invoice->amount - (int) $this->tenant->table('school_payments')->where('invoice_id', $invoice->id)->sum('amount');
            if ($data['amount'] <= 0 || $data['amount'] > $balance) {
                $this->fail('amount', 'Payment must be greater than zero and no more than the outstanding balance.');
            }
            if ($data['paid_on'] > $this->today()) {
                $this->fail('paid_on', 'A received payment cannot have a future date.');
            }
        }
        if ($module === 'payroll' && $data['deductions'] > $data['basic'] + $data['allowances']) {
            $this->fail('deductions', 'Deductions cannot exceed basic pay plus allowances.');
        }
        if ($module === 'payroll_payments') {
            $payroll = $this->tenant->table('school_payroll')->find($data['payroll_id']);
            $balance = (int) $payroll->basic + (int) $payroll->allowances - (int) $payroll->deductions - (int) $this->tenant->table('school_payroll_payments')->where('payroll_id', $payroll->id)->sum('amount');
            if ($data['amount'] <= 0 || $data['amount'] > $balance) {
                $this->fail('amount', 'Salary payment must be positive and cannot exceed the unpaid net salary.');
            }
            if ($data['paid_on'] > $this->today()) {
                $this->fail('paid_on', 'A salary payment cannot have a future date.');
            }
        }
        if ($module === 'expenses' && ($data['amount'] <= 0 || $data['paid_on'] > $this->today())) {
            $this->fail('amount', 'Enter a positive amount for an expense already paid.');
        }
        if ($module === 'leave_requests') {
            if (! $this->admin($user)) {
                abort_unless((int) $data['user_id'] === $user->id, 403);
                if ($data['status'] !== 'pending' || ($old && $old->status !== 'pending')) {
                    $this->fail('status', 'Only administrators can decide leave requests.');
                }
            }
            if ($this->tenant->table('school_leave_requests')->where('user_id', $data['user_id'])->where('status', '!=', 'rejected')
                ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $this->fail('starts_on', 'This person already has a leave request overlapping these dates.');
            }
        }
        $unique = match ($module) {
            'guardian_links' => ['student_id', 'user_id'],
            'teacher_assignments' => ['user_id', 'class_id', 'subject_id'],
            'attendance' => ['student_id', 'date'],
            'grades' => ['exam_id', 'student_id', 'subject_id'],
            'exam_subjects' => ['exam_id', 'subject_id'],
            'payroll' => ['staff_id', 'month'],
            'event_rsvps' => ['event_id', 'user_id'],
            'staff_attendance' => ['user_id', 'date'],
            default => [],
        };
        if ($unique !== []) {
            $query = $this->tenant->table('school_'.$module)->when($id, fn ($q) => $q->where('id', '!=', $id));
            foreach ($unique as $field) {
                $query->where($field, $data[$field]);
            }
            if ($query->exists()) {
                $this->fail($unique[0], 'This record already exists. Edit the existing record instead.');
            }
        }
    }
}

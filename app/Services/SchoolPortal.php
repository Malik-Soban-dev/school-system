<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchoolPortal
{
    public function today(): string
    {
        $timezone = DB::table('school_settings')->where('key', 'timezone')->value('value') ?: config('app.timezone');

        return today($timezone)->toDateString();
    }

    public function admin(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('admin');
    }

    public function definition(string $module): array
    {
        $definition = config('school-modules.'.$module);
        abort_unless(is_array($definition), 404);

        return $definition;
    }

    public function can(User $user, array $roles): bool
    {
        return $user->is_active && count(array_intersect($roles, $user->roles ?? [])) > 0;
    }

    public function modules(User $user): array
    {
        $result = [];
        foreach (config('school-modules') as $key => $definition) {
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

    public function studentIds(User $user): array
    {
        if ($user->hasRole('teacher')) {
            $classes = DB::table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->pluck('class_id');
        } else {
            $classes = [];
        }
        $guardianIds = $user->hasRole('parent')
            ? DB::table('school_guardian_links')->where('user_id', $user->id)->where('status', 'active')->pluck('student_id')->all() : [];

        return DB::table('school_students')->where(function (Builder $query) use ($user, $classes, $guardianIds): void {
            $query->whereIn('class_id', $classes)->orWhereIn('id', $guardianIds);
            if ($user->hasRole('student')) {
                $query->orWhere('user_id', $user->id);
            }
        })->pluck('id')->all();
    }

    public function query(string $module, User $user): Builder
    {
        $definition = $this->definition($module);
        abort_unless($this->can($user, $definition['read']), 403);
        $query = DB::table('school_'.$module);
        if ($this->admin($user)) {
            return $query;
        }
        if ($user->hasRole('accountant') && in_array($module, ['students', 'classes', 'subjects', 'invoices', 'payments', 'expenses', 'payroll'])) {
            return $query;
        }
        if ($module === 'notices') {
            return $query->where('status', 'published')->whereIn('audience', ['all', ...($user->roles ?? [])]);
        }
        if ($module === 'subjects') {
            return $query;
        }
        if ($module === 'leave_requests' || $module === 'teacher_assignments') {
            return $query->where('user_id', $user->id);
        }
        $students = $this->studentIds($user);
        $family = DB::table('school_students')->where(function (Builder $query) use ($user): void {
            $query->whereIn('id', $user->hasRole('parent') ? DB::table('school_guardian_links')->where('user_id', $user->id)->where('status', 'active')->pluck('student_id') : []);
            if ($user->hasRole('student')) {
                $query->orWhere('user_id', $user->id);
            }
        })->pluck('id');
        if ($module === 'grades') {
            return $query->where(function (Builder $query) use ($user, $family): void {
                $query->where(function (Builder $query) use ($family): void {
                    $query->whereIn('student_id', $family)->whereIn('exam_id', DB::table('school_exams')->where('status', 'published')->select('id'));
                });
                if ($user->hasRole('teacher')) {
                    foreach (DB::table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->get() as $assignment) {
                        $query->orWhere(function (Builder $query) use ($assignment): void {
                            $query->where('subject_id', $assignment->subject_id)->whereIn('student_id', DB::table('school_students')->where('class_id', $assignment->class_id)->select('id'));
                        });
                    }
                }
            });
        }
        if ($module === 'invoices') {
            return $query->whereIn('student_id', $family);
        }
        if ($module === 'payments') {
            return $query->whereIn('invoice_id', DB::table('school_invoices')->whereIn('student_id', $family)->select('id'));
        }
        if ($module === 'exams') {
            return $query->where(function (Builder $query) use ($family, $user): void {
                $query->where(function (Builder $query) use ($family): void {
                    $query->where('status', 'published')->whereIn('class_id', DB::table('school_students')->whereIn('id', $family)->select('class_id'));
                });
                if ($user->hasRole('teacher')) {
                    $query->orWhereIn('class_id', DB::table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->select('class_id'));
                }
            });
        }
        $classes = DB::table('school_students')->whereIn('id', $students)->pluck('class_id')->all();
        if ($user->hasRole('teacher')) {
            $classes = array_unique([...$classes, ...DB::table('school_teacher_assignments')->where('user_id', $user->id)->where('status', 'active')->pluck('class_id')->all()]);
        }

        return match ($module) {
            'students' => $query->whereIn('id', $students),
            'classes' => $query->whereIn('id', $classes),
            'attendance' => $query->whereIn('student_id', $students),
            'timetables' => $query->whereIn('class_id', $classes),
            default => $query->where('id', 0),
        };
    }

    public function listing(string $module, User $user, string $search = '', int $page = 1): array
    {
        $definition = $this->definition($module);
        $query = $this->query($module, $user);
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
                $data['paid'] = (int) DB::table('school_payments')->where('invoice_id', $row->id)->sum('amount');
                $data['balance'] = (int) $row->amount - $data['paid'];
            }
            if ($module === 'payroll') {
                $data['net'] = (int) $row->basic + (int) $row->allowances - (int) $row->deductions;
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
        foreach (['academic_years', 'classes', 'subjects', 'staff', 'students', 'exams', 'invoices'] as $module) {
            if (! $this->can($user, $this->definition($module)['read'])) {
                continue;
            }
            $label = $module === 'invoices' ? 'reference' : 'name';
            $options[$module] = $this->query($module, $user)->select('id', $label)->orderBy($label)->limit(1000)->get()
                ->map(fn ($row) => ['value' => $row->id, 'name' => $row->$label])->all();
        }
        $options['users'] = User::query()->where('is_active', true)->when(! $this->admin($user), fn ($q) => $q->where('id', $user->id))
            ->select('id', 'name')->orderBy('name')->limit(1000)->get()->map(fn ($u) => ['value' => $u->id, 'name' => $u->name])->all();
        if ($user->hasRole('accountant') && ! $this->admin($user)) {
            $options['staff'] = DB::table('school_staff')->select('id', 'name')->orderBy('name')->get()->map(fn ($row) => ['value' => $row->id, 'name' => $row->name])->all();
        }

        return $options;
    }

    public function save(string $module, User $user, array $input, ?int $id = null): int
    {
        $definition = $this->definition($module);
        abort_unless($this->can($user, $definition['write']), 403);
        abort_if($id !== null && ($definition['immutable'] ?? false), 403, 'Posted records cannot be changed.');
        $old = $id ? $this->query($module, $user)->where('id', $id)->first() : null;
        abort_if($id && ! $old, 404);
        $rules = [];
        foreach ($definition['fields'] as $field) {
            $rule = [($field['optional'] ?? false) ? 'nullable' : 'required'];
            $rule = [...$rule, ...match ($field['type']) {
                'relation' => ['integer', Rule::exists($field['relation'] === 'users' ? 'users' : 'school_'.$field['relation'], 'id')],
                'number' => ['integer', 'min:'.($field['min'] ?? 0), 'max:'.($field['max'] ?? 1000000)],
                'decimal' => ['numeric', 'min:0', 'max:999999', 'decimal:0,2'],
                'money' => ['regex:/^\d{1,9}(\.\d{1,2})?$/'],
                'date' => ['date_format:Y-m-d'],
                'time' => ['date_format:H:i'],
                'month' => ['date_format:Y-m'],
                'select' => [Rule::in($field['choices'])],
                default => ['string', 'max:'.($field['type'] === 'textarea' ? 5000 : 255)],
            }];
            $unique = match ($module.'.'.$field['name']) {
                'subjects.code', 'staff.employee_number', 'students.admission_number', 'invoices.reference', 'payments.reference', 'expenses.reference' => true,
                default => false,
            };
            if ($unique) {
                $rule[] = Rule::unique('school_'.$module, $field['name'])->ignore($id);
            }
            $rules[$field['name']] = $rule;
        }
        $data = Validator::make($input, $rules)->validate();
        foreach ($definition['fields'] as $field) {
            if ($field['type'] === 'money') {
                $parts = explode('.', (string) $data[$field['name']]);
                $data[$field['name']] = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            }
        }

        return DB::transaction(function () use ($module, $user, $data, $id, $old): int {
            $this->validateBusiness($module, $data, $user, $id, $old);
            $now = now();
            if ($id) {
                DB::table('school_'.$module)->where('id', $id)->update([...$data, 'updated_at' => $now]);
            } else {
                $id = DB::table('school_'.$module)->insertGetId([...$data, 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($module === 'students') {
                DB::table('school_enrollments')->updateOrInsert(['student_id' => $id, 'class_id' => $data['class_id']], ['created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('school_audit')->insert(['user_id' => $user->id, 'module' => $module, 'record_id' => $id,
                'action' => $old ? 'updated' : 'created', 'changes' => json_encode(['before' => $old, 'after' => $data]), 'created_at' => $now]);

            return $id;
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function requireRole(int $id, string $role, string $field): void
    {
        if (! User::find($id)?->hasRole($role)) {
            $this->fail($field, 'Choose an active '.$role.' account.');
        }
    }

    private function validateBusiness(string $module, array $data, User $user, ?int $id, ?object $old): void
    {
        if ($module === 'classes' && $id && DB::table('school_students')->where('class_id', $id)->where('status', 'active')->count() > $data['capacity']) {
            $this->fail('capacity', 'Capacity cannot be smaller than the current active enrollment.');
        }
        if (isset($data['starts_on'], $data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            $this->fail('ends_on', 'The last day must be on or after the first day.');
        }
        if ($module === 'students') {
            $class = DB::table('school_classes')->find($data['class_id']);
            if ($data['status'] === 'active' && DB::table('school_students')->where('class_id', $class->id)->where('status', 'active')->when($id, fn ($q) => $q->where('id', '!=', $id))->count() >= $class->capacity) {
                $this->fail('class_id', 'This class is full.');
            }
            if (! empty($data['user_id'])) {
                $this->requireRole((int) $data['user_id'], 'student', 'user_id');
                if (DB::table('school_students')->where('user_id', $data['user_id'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
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
            $student = DB::table('school_students')->find($data['student_id']);
            $assignment = DB::table('school_teacher_assignments')->where('user_id', $user->id)->where('class_id', $student->class_id)->where('status', 'active');
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
            $exam = DB::table('school_exams')->find($data['exam_id']);
            $student = DB::table('school_students')->find($data['student_id']);
            if ((int) $exam->class_id !== (int) $student->class_id) {
                $this->fail('student_id', 'The student must belong to the exam class.');
            }
            if ($exam->status === 'published' || ($old && DB::table('school_exams')->find($old->exam_id)->status === 'published')) {
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
            if (! DB::table('school_teacher_assignments')->where('user_id', $data['teacher_id'])->where('class_id', $data['class_id'])->where('subject_id', $data['subject_id'])->where('status', 'active')->exists()) {
                $this->fail('teacher_id', 'Assign this teacher to the class and subject first.');
            }
            $year = DB::table('school_classes')->find($data['class_id'])->year_id;
            $conflicts = DB::table('school_timetables')->whereIn('class_id', DB::table('school_classes')->where('year_id', $year)->select('id'))
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
            if ($old && DB::table('school_payments')->where('invoice_id', $id)->exists() && ((int) $old->amount !== $data['amount'] || (int) $old->student_id !== (int) $data['student_id'])) {
                $this->fail('amount', 'The amount and student cannot change after a payment is recorded.');
            }
        }
        if ($module === 'payments') {
            $invoice = DB::table('school_invoices')->find($data['invoice_id']);
            $balance = (int) $invoice->amount - (int) DB::table('school_payments')->where('invoice_id', $invoice->id)->sum('amount');
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
            if (DB::table('school_leave_requests')->where('user_id', $data['user_id'])->where('status', '!=', 'rejected')
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
            'payroll' => ['staff_id', 'month'],
            default => [],
        };
        if ($unique !== []) {
            $query = DB::table('school_'.$module)->when($id, fn ($q) => $q->where('id', '!=', $id));
            foreach ($unique as $field) {
                $query->where($field, $data[$field]);
            }
            if ($query->exists()) {
                $this->fail($unique[0], 'This record already exists. Edit the existing record instead.');
            }
        }
    }
}

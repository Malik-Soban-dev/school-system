<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SchoolNotifications
{
    public function enqueue(string $module, int $id, array $data): void
    {
        if (! in_array($module, ['notices', 'exams', 'invoices', 'payments', 'payroll', 'payroll_payments', 'attendance', 'leave_requests'])) {
            return;
        }
        DB::table('school_notification_events')->insertOrIgnore(['module' => $module, 'record_id' => $id,
            'event_key' => $module.':'.$id.':'.hash('sha256', json_encode($data)), 'created_at' => now()]);
    }

    public function visible(User $user): Builder
    {
        $portal = app(SchoolPortal::class);

        return DB::table('school_notifications')->where('user_id', $user->id)->where(function (Builder $query) use ($portal, $user): void {
            $query->whereRaw('1 = 0');
            foreach (config('school-modules') as $module => $definition) {
                if ($portal->can($user, $definition['read'])) {
                    $query->orWhere(function (Builder $scope) use ($portal, $user, $module): void {
                        $scope->where('module', $module)->whereIn('record_id', $portal->query($module, $user)->select('id'));
                    });
                }
            }
        });
    }

    private function family(array $studentIds): array
    {
        $students = DB::table('school_students')->whereIn('id', $studentIds)->whereNotNull('user_id')->pluck('user_id')->all();
        $guardians = DB::table('school_guardian_links')->whereIn('student_id', $studentIds)->where('status', 'active')->pluck('user_id')->all();

        return array_values(array_unique([...$students, ...$guardians]));
    }

    private function recipients(string $module, object $record): array
    {
        if ($module === 'notices') {
            return User::where('is_active', true)->get(['id', 'roles', 'is_active'])->filter(fn (User $user) => $record->audience === 'all' || $user->hasRole($record->audience) || $user->hasRole('owner') || $user->hasRole('admin'))->pluck('id')->all();
        }
        if ($module === 'exams') {
            return $this->family(DB::table('school_students')->where('class_id', $record->class_id)->where('status', 'active')->pluck('id')->all());
        }
        if ($module === 'invoices' || $module === 'attendance') {
            return $this->family([$record->student_id]);
        }
        if ($module === 'payments') {
            return $this->family([DB::table('school_invoices')->where('id', $record->invoice_id)->value('student_id')]);
        }
        if ($module === 'payroll' || $module === 'payroll_payments') {
            $staffId = $module === 'payroll' ? $record->staff_id : DB::table('school_payroll')->where('id', $record->payroll_id)->value('staff_id');

            return array_filter([DB::table('school_staff')->where('id', $staffId)->value('user_id')]);
        }
        if ($module === 'leave_requests') {
            $admins = User::where('is_active', true)->get(['id', 'roles', 'is_active'])->filter(fn (User $user) => $user->hasRole('owner') || $user->hasRole('admin'))->pluck('id')->all();

            return array_unique([$record->user_id, ...$admins]);
        }

        return [];
    }

    public function publish(string $module, int $id, string $eventKey, ?string $reminder = null): void
    {
        $record = DB::table('school_'.$module)->find($id);
        if (! $record || ($module === 'notices' && $record->status !== 'published') || ($module === 'exams' && $record->schedule_status === 'draft' && $record->status !== 'published')) {
            return;
        }
        [$title, $body] = match ($module) {
            'notices' => [$record->title, $record->body],
            'exams' => [$reminder ?? 'Exam update', $record->name.' — '.$record->date.'. '.($record->schedule_status === 'cancelled' ? 'This exam has been cancelled.' : ($record->status === 'published' ? 'Results are published.' : 'Prepare for the upcoming exam. Results are not published yet.'))],
            'invoices' => ['Fee invoice available', 'Invoice '.$record->reference.' is due on '.$record->due_on.'. Open Fee invoices to check the amount and balance.'],
            'payments' => ['Fee payment recorded', 'Receipt '.$record->reference.' was recorded on '.$record->paid_on.' using '.str_replace('_', ' ', $record->method).'. Open Payments & receipts for details.'],
            'payroll' => ['Monthly payroll available', 'Your payroll calculation for '.$record->month.' is available. This is not confirmation of payment.'],
            'payroll_payments' => ['Salary payment recorded', 'Payment '.$record->reference.' was recorded on '.$record->paid_on.' using '.str_replace('_', ' ', $record->method).'. Open Salary payments for details.'],
            'attendance' => ['Attendance update', 'Attendance for '.$record->date.' was recorded as '.$record->status.'. Open Attendance for the student and details.'],
            'leave_requests' => ['Leave request update', 'Leave from '.$record->starts_on.' to '.$record->ends_on.' is '.$record->status.'.'],
            default => ['', ''],
        };
        $ids = $this->recipients($module, $record);
        $active = User::whereIn('id', $ids)->where('is_active', true)->pluck('id');
        foreach ($active->chunk(100) as $chunk) {
            DB::table('school_notifications')->insertOrIgnore($chunk->map(fn ($userId) => ['user_id' => $userId, 'event_key' => $eventKey, 'module' => $module, 'record_id' => $id, 'title' => $title, 'body' => $body, 'created_at' => now(), 'updated_at' => now()])->all());
        }
    }

    public function process(int $limit = 100): int
    {
        $events = DB::table('school_notification_events')->orderBy('id')->limit($limit)->get();
        foreach ($events as $event) {
            $this->publish($event->module, $event->record_id, $event->event_key);
            DB::table('school_notification_events')->where('id', $event->id)->delete();
        }

        return $events->count();
    }

    public function remind(): void
    {
        $timezone = DB::table('school_settings')->where('key', 'timezone')->value('value') ?: config('app.timezone');
        foreach ([7, 1] as $days) {
            $date = today($timezone)->addDays($days)->toDateString();
            foreach (DB::table('school_exams')->where('schedule_status', 'announced')->where('date', $date)->get() as $exam) {
                $this->publish('exams', $exam->id, 'exam-reminder:'.$exam->id.':'.$date.':'.$days, 'Exam reminder: '.$days.' day'.($days === 1 ? '' : 's').' to go');
            }
        }
    }
}

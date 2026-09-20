<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlySettlementTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_salary_payments_preserve_exact_balances_and_teacher_privacy(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $teacher = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $other = User::factory()->create(['roles' => ['teacher'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $staff = $portal->save('staff', $owner, ['name' => 'Paid Teacher', 'employee_number' => 'STAFF-1', 'department' => 'Teaching', 'designation' => 'Teacher', 'user_id' => $teacher->id, 'joined_on' => '2026-01-01', 'status' => 'active']);
        $payroll = $portal->save('payroll', $owner, ['staff_id' => $staff, 'month' => '2026-09', 'basic' => '100.10', 'allowances' => '10.00', 'deductions' => '5.05']);
        $payment = ['payroll_id' => $payroll, 'reference' => 'SAL-1', 'amount' => '40.02', 'paid_on' => today()->toDateString(), 'method' => 'cash'];
        $id = $this->actingAs($owner)->postJson('/portal/records/payroll_payments', $payment)->assertOk()->json('id');
        $this->getJson('/portal/records/payroll?month=2026-09')->assertJsonPath('rows.0.net', 10505)->assertJsonPath('rows.0.paid', 4002)->assertJsonPath('rows.0.balance', 6503)->assertJsonPath('rows.0.payment_status', 'partially paid');
        $this->postJson('/portal/records/payroll_payments', [...$payment, 'reference' => 'SAL-2', 'amount' => '65.04'])->assertUnprocessable();
        $this->postJson('/portal/records/payroll_payments', [...$payment, 'reference' => 'SAL-2', 'amount' => '65.03', 'method' => 'online_manual'])->assertOk();
        $this->postJson('/portal/records/payroll_payments', $payment)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->putJson('/portal/records/payroll_payments/'.$id, $payment)->assertForbidden();
        $this->actingAs($teacher)->getJson('/portal/records/payroll')->assertJsonPath('rows.0.balance', 0)->assertJsonPath('rows.0.payment_status', 'paid');
        $this->getJson('/portal/records/payroll_payments?month=2026-09')->assertJsonCount(2, 'rows');
        $this->get('/reports/payroll_payments/'.$id)->assertOk()->assertSee('SAL-1');
        $this->postJson('/portal/records/payroll_payments', $payment)->assertForbidden();
        $this->actingAs($other)->getJson('/portal/records/payroll')->assertJsonCount(0, 'rows');
        $this->getJson('/portal/records/payroll_payments')->assertJsonCount(0, 'rows');
        $this->get('/reports/payroll_payments/'.$id)->assertNotFound();
    }

    public function test_fee_month_is_independent_of_payment_date_and_frozen_after_payment(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 5', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Student', 'admission_number' => 'ADM-1', 'class_id' => $class, 'status' => 'active']);
        $invoice = ['student_id' => $student, 'reference' => 'FEE-1', 'description' => 'August tuition', 'amount' => '100.10', 'due_on' => '2026-08-15', 'billing_month' => '2026-08'];
        $id = $portal->save('invoices', $owner, $invoice);
        $this->actingAs($owner)->postJson('/portal/records/payments', ['invoice_id' => $id, 'reference' => 'REC-1', 'amount' => '100.10', 'paid_on' => today()->toDateString(), 'method' => 'online_manual'])->assertOk();
        $this->actingAs($owner)->getJson('/portal/payments/reconciliation?month=2026-08')->assertOk()->assertJsonPath('billed', 10010)->assertJsonPath('collected', 10010)->assertJsonPath('outstanding', 0)->assertJsonPath('receipts', 1)->assertJsonPath('methods.0.method', 'online_manual')->assertJsonPath('methods.0.amount', 10010);
        $this->getJson('/portal/records/payments?month=2026-08')->assertJsonCount(1, 'rows');
        $this->getJson('/portal/records/payments?month=2026-07')->assertJsonCount(0, 'rows');
        $this->getJson('/portal/records/invoices?month=2026-08')->assertJsonPath('rows.0.payment_status', 'paid');
        $this->putJson('/portal/records/invoices/'.$id, [...$invoice, 'billing_month' => '2026-09'])->assertUnprocessable();
        $this->putJson('/portal/records/invoices/'.$id, [...$invoice, 'billing_month' => null])->assertUnprocessable();
        $this->assertDatabaseHas('school_invoices', ['id' => $id, 'billing_month' => '2026-08']);
        $this->getJson('/portal/records/invoices?month=invalid')->assertUnprocessable();
        $this->assertDatabaseHas('school_notification_events', ['module' => 'payments']);
    }

    public function test_admin_can_create_repeat_safe_batch_monthly_invoices(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 6', 'year_id' => $year, 'capacity' => 30]);
        $first = $portal->save('students', $owner, ['name' => 'First Student', 'admission_number' => 'BATCH-1', 'class_id' => $class, 'status' => 'active']);
        $second = $portal->save('students', $owner, ['name' => 'Second Student', 'admission_number' => 'BATCH-2', 'class_id' => $class, 'status' => 'active']);

        $payload = ['billing_month' => '2026-10', 'amount' => '1250.50', 'due_on' => '2026-10-10', 'description' => 'October tuition'];
        $this->actingAs($owner)->postJson('/portal/invoices/batch', $payload)->assertOk()->assertJsonPath('created', 2)->assertJsonPath('skipped', 0);
        $this->actingAs($owner)->postJson('/portal/invoices/batch', $payload)->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 2);

        $this->assertDatabaseCount('school_invoices', 2);
        $this->assertDatabaseHas('school_invoices', ['student_id' => $first, 'billing_month' => '2026-10', 'amount' => 125050]);
        $this->assertDatabaseHas('school_invoices', ['student_id' => $second, 'reference' => 'FEE-2026-10-'.$second]);
        $this->assertDatabaseHas('school_audit', ['module' => 'invoices', 'action' => 'batch_created']);
    }

    public function test_invoice_listing_marks_past_due_unpaid_balances_as_overdue(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 7', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Past Due Student', 'admission_number' => 'OVERDUE-1', 'class_id' => $class, 'status' => 'active']);
        $invoice = $portal->save('invoices', $owner, ['student_id' => $student, 'reference' => 'OVERDUE-1', 'description' => 'Past due tuition', 'amount' => '100.00', 'due_on' => today()->subDay()->toDateString(), 'billing_month' => '2026-09']);

        $this->actingAs($owner)->getJson('/portal/records/invoices')->assertOk()->assertJsonPath('rows.0.id', $invoice)->assertJsonPath('rows.0.payment_status', 'overdue');
        $this->actingAs($owner)->getJson('/portal/meta')->assertOk()->assertJsonPath('overview.fees.billed', 10000)->assertJsonPath('overview.fees.collected', 0)->assertJsonPath('overview.fees.outstanding', 10000)->assertJsonPath('overview.fees.overdue', 10000)->assertJsonPath('overview.fees.collection_rate', 0);
    }

    public function test_school_late_fee_command_is_repeat_safe_and_skips_paid_invoices(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 8', 'year_id' => $year, 'capacity' => 30]);
        $student = $portal->save('students', $owner, ['name' => 'Late Fee Student', 'admission_number' => 'LATE-1', 'class_id' => $class, 'status' => 'active']);
        $invoice = $portal->save('invoices', $owner, ['student_id' => $student, 'reference' => 'LATE-ORIGINAL', 'description' => 'September tuition', 'amount' => '100.00', 'due_on' => today()->subDays(3)->toDateString(), 'billing_month' => '2026-09']);
        DB::table('school_settings')->updateOrInsert(['school_id' => app(TenantContext::class)->id(), 'key' => 'late_fee_amount'], ['value' => '500']);
        DB::table('school_settings')->updateOrInsert(['school_id' => app(TenantContext::class)->id(), 'key' => 'late_fee_grace_days'], ['value' => '1']);

        $this->artisan('school:late-fees', ['--until' => today()->toDateString()])->assertSuccessful();
        $this->artisan('school:late-fees', ['--until' => today()->toDateString()])->assertSuccessful();
        $this->assertDatabaseCount('school_invoices', 2);
        $this->assertDatabaseHas('school_invoices', ['reference' => 'LATE-'.$invoice.'-'.today()->format('Ym'), 'amount' => 500]);
        $this->assertDatabaseHas('school_audit', ['module' => 'invoices', 'action' => 'late_fee_created']);

        $paid = $portal->save('invoices', $owner, ['student_id' => $student, 'reference' => 'LATE-PAID', 'description' => 'Paid tuition', 'amount' => '100.00', 'due_on' => today()->subDays(3)->toDateString(), 'billing_month' => '2026-09']);
        $this->actingAs($owner)->postJson('/portal/records/payments', ['invoice_id' => $paid, 'reference' => 'LATE-PAYMENT', 'amount' => '100.00', 'paid_on' => today()->toDateString(), 'method' => 'cash'])->assertOk();
        $this->artisan('school:late-fees', ['--until' => today()->toDateString()])->assertSuccessful();
        $this->assertDatabaseMissing('school_invoices', ['reference' => 'LATE-'.$paid.'-'.today()->format('Ym')]);
    }

    public function test_school_monthly_invoice_command_creates_repeat_safe_active_student_fees(): void
    {
        $owner = User::factory()->create(['roles' => ['owner'], 'is_active' => true]);
        $portal = app(SchoolPortal::class);
        $year = $portal->save('academic_years', $owner, ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $class = $portal->save('classes', $owner, ['name' => 'Grade 9', 'year_id' => $year, 'capacity' => 30]);
        $active = $portal->save('students', $owner, ['name' => 'Monthly Active', 'admission_number' => 'MONTH-1', 'class_id' => $class, 'status' => 'active']);
        $portal->save('students', $owner, ['name' => 'Monthly Withdrawn', 'admission_number' => 'MONTH-2', 'class_id' => $class, 'status' => 'withdrawn']);
        DB::table('school_settings')->upsert([
            ['school_id' => app(TenantContext::class)->id(), 'key' => 'monthly_fee_amount', 'value' => '25000'],
            ['school_id' => app(TenantContext::class)->id(), 'key' => 'monthly_fee_due_day', 'value' => '12'],
            ['school_id' => app(TenantContext::class)->id(), 'key' => 'monthly_fee_description', 'value' => 'October tuition'],
        ], ['school_id', 'key'], ['value']);

        $this->artisan('school:monthly-invoices', ['--month' => '2026-10'])->assertSuccessful();
        $this->artisan('school:monthly-invoices', ['--month' => '2026-10'])->assertSuccessful();
        $this->assertDatabaseCount('school_invoices', 1);
        $this->assertDatabaseHas('school_invoices', ['student_id' => $active, 'reference' => 'FEE-2026-10-'.$active, 'amount' => 25000, 'due_on' => '2026-10-12']);
        $this->assertDatabaseHas('school_notification_events', ['module' => 'invoices', 'record_id' => DB::table('school_invoices')->value('id')]);
    }
}

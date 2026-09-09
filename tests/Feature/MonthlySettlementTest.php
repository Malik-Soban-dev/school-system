<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolPortal;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
        $this->getJson('/portal/records/payments?month=2026-08')->assertJsonCount(1, 'rows');
        $this->getJson('/portal/records/payments?month=2026-07')->assertJsonCount(0, 'rows');
        $this->getJson('/portal/records/invoices?month=2026-08')->assertJsonPath('rows.0.payment_status', 'paid');
        $this->putJson('/portal/records/invoices/'.$id, [...$invoice, 'billing_month' => '2026-09'])->assertUnprocessable();
        $this->putJson('/portal/records/invoices/'.$id, [...$invoice, 'billing_month' => null])->assertUnprocessable();
        $this->assertDatabaseHas('school_invoices', ['id' => $id, 'billing_month' => '2026-08']);
        $this->getJson('/portal/records/invoices?month=invalid')->assertUnprocessable();
        $this->assertDatabaseHas('school_notification_events', ['module' => 'payments']);
    }
}

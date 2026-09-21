<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InterfacePreferenceController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\SuperadminSetupController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureMfa;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\ResolveSchool;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', EnsureActiveAccount::class, EnsureMfa::class, EnsureSuperadmin::class])->group(function () {
    Route::get('/superadmin', [PlatformController::class, 'index'])->name('superadmin.dashboard');
    Route::get('/superadmin/data', [PlatformController::class, 'data']);
    Route::get('/superadmin/branches', [PlatformController::class, 'branches']);
    Route::get('/superadmin/billing/invoices', [PlatformController::class, 'billingInvoices']);
    Route::post('/superadmin/schools/{school}/billing/invoices', [PlatformController::class, 'createBillingInvoice'])->whereNumber('school');
    Route::put('/superadmin/billing/invoices/{invoice}/status', [PlatformController::class, 'updateBillingInvoiceStatus'])->whereNumber('invoice');
    Route::get('/superadmin/health', [PlatformController::class, 'health']);
    Route::post('/superadmin/operations/backups', [PlatformController::class, 'createBackup']);
    Route::post('/superadmin/operations/backups/verify', [PlatformController::class, 'verifyBackup']);
    Route::get('/superadmin/operations/backups/{name}/download', [PlatformController::class, 'downloadBackup'])->where('name', '[A-Za-z0-9._-]+\.enc')->name('superadmin.backup.download');
    Route::get('/superadmin/operations/failed-jobs', [PlatformController::class, 'failedJobs']);
    Route::post('/superadmin/operations/failed-jobs/{job}/retry', [PlatformController::class, 'retryFailedJob'])->whereNumber('job');
    Route::delete('/superadmin/operations/failed-jobs/{job}', [PlatformController::class, 'forgetFailedJob'])->whereNumber('job');
    Route::post('/superadmin/operations/exports/users', [PlatformController::class, 'createUserExport']);
    Route::post('/superadmin/schools/{school}/operations/exports', [PlatformController::class, 'createSchoolExport'])->whereNumber('school');
    Route::get('/superadmin/operations/exports', [PlatformController::class, 'exports']);
    Route::get('/superadmin/operations/exports/{export}/download', [PlatformController::class, 'downloadExport'])->whereNumber('export')->name('superadmin.export.download');
    Route::get('/superadmin/audit', [PlatformController::class, 'audit']);
    Route::get('/superadmin/users', [PlatformController::class, 'users']);
    Route::get('/superadmin/schools/{school}/records/{module}', [PlatformController::class, 'records'])->whereNumber('school')->whereIn('module', ['staff_attendance', 'events', 'event_rsvps', 'academic_years', 'students', 'staff', 'classes', 'subjects', 'student_welfare', 'teacher_assignments', 'guardian_links', 'attendance', 'subject_attendance', 'timetables', 'exams', 'grade_bands', 'exam_subjects', 'grades', 'invoices', 'fee_concessions', 'payments', 'expenses', 'leave_requests', 'payroll', 'payroll_payments', 'notices', 'enrollments', 'invitations', 'notifications', 'notification_deliveries', 'notification_preferences', 'notification_events', 'settings', 'users', 'branch_access', 'audit']);
    Route::put('/superadmin/users/{user}', [PlatformController::class, 'updateUserProfile'])->whereNumber('user');
    Route::put('/superadmin/users/{user}/branch-access', [PlatformController::class, 'grantUserBranchAccess'])->whereNumber('user');
    Route::post('/superadmin/users/{user}/password-reset', [PlatformController::class, 'issuePasswordReset'])->whereNumber('user');
    Route::post('/superadmin/users/{user}/sessions/revoke', [PlatformController::class, 'revokeUserSessions'])->whereNumber('user');
    Route::put('/superadmin/users/{user}/status', [PlatformController::class, 'updateUserStatus'])->whereNumber('user');
    Route::post('/superadmin/schools', [PlatformController::class, 'createSchool']);
    Route::put('/superadmin/schools/{school}/subscription', [PlatformController::class, 'updateSubscription'])->whereNumber('school');
    Route::put('/superadmin/schools/{school}/features', [PlatformController::class, 'updateSchoolFeature'])->whereNumber('school');
    Route::post('/superadmin/plans', [PlatformController::class, 'createPlan']);
    Route::put('/superadmin/plans/{plan}', [PlatformController::class, 'updatePlan'])->whereNumber('plan');
    Route::put('/superadmin/schools/{school}', [PlatformController::class, 'updateSchool'])->whereNumber('school');
    Route::get('/superadmin/schools/{school}', [PlatformController::class, 'school'])->whereNumber('school');
    Route::post('/superadmin/schools/{school}/branches', [PlatformController::class, 'createBranch'])->whereNumber('school');
    Route::put('/superadmin/schools/{school}/branches/{branch}', [PlatformController::class, 'updateBranch'])->whereNumber(['school', 'branch']);
    Route::put('/superadmin/schools/{school}/branches/{branch}/default', [PlatformController::class, 'setDefaultBranch'])->whereNumber(['school', 'branch']);
    Route::put('/superadmin/schools/{school}/branches/{branch}/status', [PlatformController::class, 'updateBranchStatus'])->whereNumber(['school', 'branch']);
    Route::post('/superadmin/schools/{school}/branches/{branch}/invitations', [PlatformController::class, 'inviteBranchUser'])->whereNumber(['school', 'branch']);
    Route::delete('/superadmin/schools/{school}/invitations/{invitation}', [PlatformController::class, 'revokeInvitation'])->whereNumber(['school', 'invitation']);
    Route::put('/superadmin/schools/{school}/members/{user}/branch-access', [PlatformController::class, 'updateBranchAccess'])->whereNumber(['school', 'user']);
    Route::put('/superadmin/schools/{school}/members/{user}/branch-access/bulk', [PlatformController::class, 'updateBulkBranchAccess'])->whereNumber(['school', 'user']);
    Route::put('/superadmin/schools/{school}/members/{user}/status', [PlatformController::class, 'updateMembershipStatus'])->whereNumber(['school', 'user']);
    Route::put('/superadmin/schools/{school}/status', [PlatformController::class, 'updateStatus'])->whereNumber('school');
});

Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive']);

Route::get('/superadmin/setup', [SuperadminSetupController::class, 'create'])->name('superadmin.setup');
Route::post('/superadmin/setup', [SuperadminSetupController::class, 'store'])->middleware('throttle:5,1')->name('superadmin.setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');
    Route::get('/password/reset/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/password/reset/{token}', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1')->name('password.reset.store');
    Route::get('/password/forgot', [AuthController::class, 'showForgotForm'])->name('password.request');
    Route::post('/password/forgot', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/mfa/challenge', [AuthController::class, 'showMfaChallenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [AuthController::class, 'verifyMfaChallenge'])->middleware('throttle:10,1')->name('mfa.challenge.verify');
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware(['auth', EnsureActiveAccount::class, EnsureMfa::class, ResolveSchool::class])->group(function () {
    Route::get('/portal/meta', [PortalController::class, 'meta']);
    Route::get('/portal/contexts', [PortalController::class, 'contexts']);
    Route::put('/portal/context', [PortalController::class, 'switchContext'])->middleware('throttle:30,1');
    Route::get('/portal/interface-preferences', [InterfacePreferenceController::class, 'show']);
    Route::put('/portal/interface-preferences', [InterfacePreferenceController::class, 'update'])->middleware('throttle:20,1');
    Route::get('/portal/notifications', [NotificationController::class, 'index']);
    Route::get('/portal/notification-preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/portal/notification-preferences', [NotificationPreferenceController::class, 'update'])->middleware('throttle:5,1');
    Route::put('/portal/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
    Route::get('/portal/users', [PortalController::class, 'users']);
    Route::put('/portal/users/{user}', [PortalController::class, 'updateUser']);
    Route::post('/portal/invitations', [InvitationController::class, 'store']);
    Route::get('/portal/invitations', [InvitationController::class, 'index']);
    Route::delete('/portal/invitations/{id}', [InvitationController::class, 'revoke'])->whereNumber('id');
    Route::put('/portal/settings', [PortalController::class, 'settings']);
    Route::post('/portal/settings', [PortalController::class, 'settings']);
    Route::put('/portal/tutorial', [PortalController::class, 'tutorial']);
    Route::put('/portal/attendance/batch', [PortalController::class, 'attendanceBatch']);
    Route::get('/portal/attendance/roster', [PortalController::class, 'attendanceRoster']);
    Route::get('/portal/students/{student}/360', [PortalController::class, 'studentProfile'])->whereNumber('student');
    Route::post('/portal/promotions', [PortalController::class, 'promoteStudents']);
    Route::post('/portal/invoices/batch', [PortalController::class, 'createBatchInvoices']);
    Route::get('/portal/payments/reconciliation', [PortalController::class, 'reconciliation']);
    Route::get('/portal/audit', [PortalController::class, 'audit']);
    Route::get('/portal/records/{module}', [PortalController::class, 'index']);
    Route::post('/portal/records/{module}', [PortalController::class, 'save']);
    Route::put('/portal/records/{module}/{id}', [PortalController::class, 'save'])->whereNumber('id');
    Route::get('/portal/submissions/{submission}/attachment', [PortalController::class, 'submissionAttachment'])->whereNumber('submission');
    Route::get('/reports/{module}/{id}', [PortalController::class, 'report'])->whereNumber('id')->name('record.report');
    Route::get('/report-cards/{exam}/{student}', [ReportCardController::class, 'show'])->whereNumber(['exam', 'student'])->name('report-card.show');
    Route::get('/dashboard', function (\Illuminate\Http\Request $request) {
        if ($request->user()->hasRole('superadmin') && ! $request->session()->get('school_workspace', false)) {
            return redirect()->route('superadmin.dashboard');
        }

        return view('dashboard');
    })->name('dashboard');
    Route::get('/account', [AuthController::class, 'account'])->name('account');
    Route::put('/account/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('password.update');
    Route::post('/account/mfa/setup', [AuthController::class, 'beginMfaEnrollment'])->middleware('throttle:5,1')->name('mfa.setup');
    Route::post('/account/mfa/confirm', [AuthController::class, 'confirmMfaEnrollment'])->middleware('throttle:5,1')->name('mfa.confirm');
    Route::post('/account/mfa/recovery-codes', [AuthController::class, 'regenerateRecoveryCodes'])->middleware('throttle:5,1')->name('mfa.recovery-codes');
    Route::delete('/account/mfa', [AuthController::class, 'disableMfa'])->middleware('throttle:5,1')->name('mfa.disable');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

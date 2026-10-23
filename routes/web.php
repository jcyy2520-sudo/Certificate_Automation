<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditTrailController;
use App\Http\Controllers\Admin\CertificateController as AdminCertificateController;
use App\Http\Controllers\Admin\CertificationController as AdminCertificationController;
use App\Http\Controllers\Admin\EmailDeliveryController as AdminEmailDeliveryController;
use App\Http\Controllers\Admin\FormController as AdminFormController;
use App\Http\Controllers\Admin\FormResponseController;
use App\Http\Controllers\Admin\ImportController as AdminImportController;
use App\Http\Controllers\Admin\ManualCertificateRecipientController;
use App\Http\Controllers\Admin\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Admin\ParticipantEligibilityController;
use App\Http\Controllers\Admin\ParticipantMessageController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Admin\WebinarController as AdminWebinarController;
use App\Http\Controllers\Admin\WebinarDocumentController;
use App\Http\Controllers\Admin\WebinarLockController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ParticipantAccessController;
use App\Http\Controllers\ParticipantReviewController;
use App\Http\Controllers\ParticipantStatusController;
use App\Http\Controllers\PasswordConfirmationController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WebinarAccessController;
use App\Http\Middleware\AuditAccountOperations;
use App\Http\Middleware\AuthorizeWebinarManagement;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureAdminSessionContext;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Http\Middleware\EnsureRecentPassword;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\RejectDeletedWebinar;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/f/{token}', [PublicFormController::class, 'show'])->where('token', '[a-z0-9]{24}')->middleware('throttle:public-form-view')->name('forms.public');
Route::post('/f/{token}/access/request', [ParticipantAccessController::class, 'request'])->where('token', '[a-z0-9]{24}')->middleware('throttle:participant-access-request')->name('forms.public.access.request');
Route::get('/f/{token}/access/confirm', [ParticipantAccessController::class, 'confirm'])->where('token', '[a-z0-9]{24}')->middleware('throttle:60,1')->name('forms.public.access.confirm');
Route::post('/f/{token}/access/confirm', [ParticipantAccessController::class, 'consume'])->where('token', '[a-z0-9]{24}')->middleware('throttle:participant-access-confirm')->name('forms.public.access.consume');
Route::post('/f/{token}', [PublicFormController::class, 'submit'])->where('token', '[a-z0-9]{24}')->middleware('throttle:public-form-submit')->name('forms.public.submit');
Route::get('/f/{token}/submitted', [PublicFormController::class, 'thanks'])->where('token', '[a-z0-9]{24}')->middleware('throttle:public-form-thanks')->name('forms.public.thanks');
Route::get('/f/{token}/status', [ParticipantStatusController::class, 'entry'])->where('token', '[a-z0-9]{24}')->middleware('throttle:public-form-view')->name('forms.public.status');
Route::post('/f/{token}/status/access/request', [ParticipantStatusController::class, 'request'])->where('token', '[a-z0-9]{24}')->middleware('throttle:participant-access-request')->name('forms.public.status.access.request');
Route::get('/f/{token}/status/access/confirm', [ParticipantStatusController::class, 'confirm'])->where('token', '[a-z0-9]{24}')->middleware('throttle:60,1')->name('forms.public.status.access.confirm');
Route::post('/f/{token}/status/access/confirm', [ParticipantStatusController::class, 'consume'])->where('token', '[a-z0-9]{24}')->middleware('throttle:participant-access-confirm')->name('forms.public.status.access.consume');
Route::get('/f/{token}/status/view', [ParticipantStatusController::class, 'show'])->where('token', '[a-z0-9]{24}')->middleware('throttle:public-form-view')->name('forms.public.status.view');
Route::post('/f/{token}/status/reviews', [ParticipantReviewController::class, 'store'])->where('token', '[a-z0-9]{24}')->middleware('throttle:participant-review')->name('forms.public.status.review.store');
Route::get('/f/{token}/status/reviews/{reviewPublicId}/evidence', [ParticipantReviewController::class, 'evidence'])->where(['token' => '[a-z0-9]{24}', 'reviewPublicId' => '[0-9A-HJKMNP-TV-Z]{26}'])->middleware('throttle:30,1')->name('forms.public.status.review.evidence');
Route::get('/f/{token}/status/certificates/{certificatePublicId}/download', [ParticipantStatusController::class, 'download'])->where(['token' => '[a-z0-9]{24}', 'certificatePublicId' => '[0-9A-HJKMNP-TV-Z]{26}'])->middleware('throttle:30,1')->name('forms.public.status.certificate.download');

Route::get('/certificates/verify/{code}', CertificateVerificationController::class)->where('code', '[A-Za-z0-9-]{12,40}')->middleware('throttle:30,1')->name('certificates.verify');

// The root exposes no webinar inventory or marketing surface; only explicit
// administrator sign-in and narrowly scoped participant links are public.
Route::get('/', RedirectController::class)
    ->defaults('destination', '/admin/login')
    ->defaults('status', 302)
    ->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/request-account', [AccountController::class, 'register'])->name('account.request');
    Route::post('/request-account', [AccountController::class, 'store'])->middleware('throttle:account-request')->name('account.store');
    Route::get('/account-status', [AccountController::class, 'status'])->name('account.status');
    Route::get('/verify-account/{user}', [AccountController::class, 'verify'])->middleware('throttle:30,1')->name('account.verify');
    Route::post('/verify-account/{user}', [AccountController::class, 'consume'])->middleware('throttle:30,1')->name('account.verify.consume');
    Route::get('/forgot-password', [AccountController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [AccountController::class, 'sendReset'])->middleware('throttle:account-request')->name('password.email');
    Route::get('/reset-password', [AccountController::class, 'reset'])->middleware('throttle:30,1')->name('password.reset');
    Route::post('/reset-password', [AccountController::class, 'updatePassword'])->middleware('throttle:10,1')->name('password.update');
    Route::get('/admin/login', [AuthController::class, 'create'])->name('login');
    Route::post('/admin/login', [AuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
    Route::get('/admin/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/admin/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:admin-two-factor-challenge')->name('two-factor.challenge.store');
});

Route::middleware(['auth', EnsureApprovedAccount::class, 'auth.session', EnsureTwoFactorEnabled::class, EnsureAdminSessionContext::class, AuditAccountOperations::class, AuthorizeWebinarManagement::class, RejectDeletedWebinar::class])
    ->scopeBindings()->prefix('admin')->name('admin.')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/profile', [AccountController::class, 'profile'])->name('profile');
        Route::put('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::put('/profile/password', [AccountController::class, 'changePassword'])->middleware([EnsureRecentPassword::class, 'throttle:admin-password-confirm'])->name('profile.password');
        Route::get('/users', [UserManagementController::class, 'index'])->middleware(EnsureAdministrator::class)->name('users.index');
        Route::get('/audit-trail', [AuditTrailController::class, 'index'])->middleware(EnsureAdministrator::class)->name('audit.index');
        Route::get('/participant-eligibility', [ParticipantEligibilityController::class, 'selectWebinar'])->name('eligibility.select');
        Route::post('/users/{user}', [UserManagementController::class, 'update'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('users.update');
        Route::post('/users', [UserManagementController::class, 'store'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('users.store');
        Route::put('/users/{user}/access', [UserManagementController::class, 'access'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('users.access');
        Route::get('/webinar-access/{webinar}', [WebinarAccessController::class, 'administrative'])->middleware(EnsureAdministrator::class)->withoutMiddleware(AuthorizeWebinarManagement::class)->name('webinars.administrative-access');
        Route::post('/webinar-access/{webinar}', [WebinarAccessController::class, 'authorizeAccess'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class])->withoutMiddleware(AuthorizeWebinarManagement::class)->name('webinars.administrative-access.store');
        Route::patch('/webinars/{webinar}/administrator-lock', [WebinarLockController::class, 'update'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class, 'throttle:admin-sensitive'])->withoutMiddleware(AuthorizeWebinarManagement::class)->name('webinars.lock');
        Route::get('/webinars/{webinar}/unlock', [WebinarAccessController::class, 'unlock'])->name('webinars.unlock');
        Route::post('/webinars/{webinar}/unlock', [WebinarAccessController::class, 'store'])->middleware('throttle:webinar-unlock')->name('webinars.unlock.store');
        Route::put('/webinars/{webinar}/protection', [WebinarAccessController::class, 'protection'])->middleware(EnsureRecentPassword::class)->name('webinars.protection');
        Route::get('/confirm-password', [PasswordConfirmationController::class, 'show'])->name('password.confirm');
        Route::post('/confirm-password', [PasswordConfirmationController::class, 'store'])->middleware('throttle:admin-password-confirm')->name('password.confirm.store');
        Route::get('/security/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('/security/two-factor', [TwoFactorController::class, 'enable'])->middleware('throttle:admin-sensitive')->name('two-factor.enable');
        Route::post('/security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:admin-sensitive')->name('two-factor.recovery-codes');
        Route::delete('/security/two-factor', [TwoFactorController::class, 'disable'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('two-factor.disable');
        Route::delete('/security/trusted-devices', [TwoFactorController::class, 'revokeDevices'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('two-factor.devices.revoke');

        Route::resource('webinars', AdminWebinarController::class)->except('destroy');
        Route::get('/webinars/{webinar}/audit', [AuditTrailController::class, 'webinar'])->name('webinars.audit');
        Route::get('/webinars/{webinar}/eligibility', [ParticipantEligibilityController::class, 'index'])->name('webinars.eligibility.index');
        Route::post('/webinars/{webinar}/eligibility/filter', [ParticipantEligibilityController::class, 'filter'])->name('webinars.eligibility.filter');
        Route::post('/webinars/{webinar}/eligibility/notify', [ParticipantEligibilityController::class, 'notify'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.eligibility.notify');
        Route::get('/webinars/{webinar}/eligibility/{participant}', [ParticipantEligibilityController::class, 'show'])->name('webinars.eligibility.show');
        Route::post('/webinars/{webinar}/eligibility/{participant}/finalize', [ParticipantEligibilityController::class, 'finalize'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.eligibility.finalize');
        Route::post('/webinars/{webinar}/eligibility/{participant}/revoke-links', [ParticipantEligibilityController::class, 'revoke'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.eligibility.revoke');
        Route::post('/webinars/{webinar}/eligibility/{participant}/reviews/{review}/decision', [ParticipantEligibilityController::class, 'decide'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.eligibility.decide');
        Route::get('/webinars/{webinar}/eligibility/{participant}/reviews/{review}/evidence', [ParticipantEligibilityController::class, 'evidence'])->middleware(EnsureRecentPassword::class)->name('webinars.eligibility.evidence');
        Route::get('/webinars/{webinar}/messages', [ParticipantMessageController::class, 'index'])->name('webinars.messages.index');
        Route::post('/webinars/{webinar}/messages', [ParticipantMessageController::class, 'store'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.messages.store');
        Route::post('/webinars/{webinar}/messages/{delivery}/retry', [ParticipantMessageController::class, 'retry'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.messages.retry');
        Route::get('/webinars/{webinar}/documents', [WebinarDocumentController::class, 'index'])->name('webinars.documents.index');
        Route::post('/webinars/{webinar}/documents', [WebinarDocumentController::class, 'store'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.documents.store');
        Route::put('/webinars/{webinar}/documents/{document}', [WebinarDocumentController::class, 'update'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.documents.update');
        Route::get('/webinars/{webinar}/documents/{document}/download', [WebinarDocumentController::class, 'download'])->middleware(EnsureRecentPassword::class)->name('webinars.documents.download');
        Route::delete('/webinars/{webinar}/documents/{document}', [WebinarDocumentController::class, 'destroy'])->middleware(EnsureRecentPassword::class)->name('webinars.documents.destroy');
        Route::get('/webinars/{webinar}/reports', [AdminWebinarController::class, 'reports'])->name('webinars.reports');
        Route::delete('/webinars/{webinar}', [AdminWebinarController::class, 'destroy'])->middleware(EnsureRecentPassword::class)->name('webinars.destroy');
        Route::post('/webinars/{webinar}/archive', [AdminWebinarController::class, 'archive'])->middleware(EnsureRecentPassword::class)->name('webinars.archive');

        Route::get('/webinars/{webinar}/forms/{form}/edit', [AdminFormController::class, 'edit'])->name('forms.edit');
        Route::put('/webinars/{webinar}/forms/{form}', [AdminFormController::class, 'update'])->name('forms.update');
        Route::post('/webinars/{webinar}/forms/{form}/toggle', [AdminFormController::class, 'toggle'])->name('forms.toggle');
        Route::post('/webinars/{webinar}/forms/{form}/rotate-link', [AdminFormController::class, 'rotateLink'])->middleware(EnsureRecentPassword::class)->name('forms.rotate-link');
        Route::post('/webinars/{webinar}/forms/{form}/fields', [AdminFormController::class, 'storeField'])->name('forms.fields.store');
        Route::put('/webinars/{webinar}/forms/{form}/fields/{field}', [AdminFormController::class, 'updateField'])->name('forms.fields.update');
        Route::delete('/webinars/{webinar}/forms/{form}/fields/{field}', [AdminFormController::class, 'destroyField'])->middleware(EnsureRecentPassword::class)->name('forms.fields.destroy');
        Route::post('/webinars/{webinar}/forms/{form}/questions', [AdminFormController::class, 'storeQuestion'])->name('forms.questions.store');
        Route::get('/webinars/{webinar}/forms/{form}/preview', [AdminFormController::class, 'preview'])->name('forms.preview');
        Route::post('/webinars/{webinar}/forms/{form}/reorder', [AdminFormController::class, 'reorder'])->name('forms.reorder');
        Route::put('/webinars/{webinar}/forms/{form}/questions/{question}', [AdminFormController::class, 'updateQuestion'])->name('forms.questions.update');
        Route::delete('/webinars/{webinar}/forms/{form}/questions/{question}', [AdminFormController::class, 'destroyQuestion'])->middleware(EnsureRecentPassword::class)->name('forms.questions.destroy');

        Route::get('/webinars/{webinar}/certification', [AdminCertificationController::class, 'edit'])->name('certification.edit');
        Route::post('/webinars/{webinar}/certification/email', [AdminCertificationController::class, 'updateEmail'])->middleware(EnsureRecentPassword::class)->name('certification.email');
        Route::match(['get', 'post'], '/webinars/{webinar}/certification/email-preview', [AdminCertificationController::class, 'previewEmail'])->name('certification.email-preview');
        Route::post('/webinars/{webinar}/certification/email-test', [AdminCertificationController::class, 'testEmail'])->middleware('throttle:admin-sensitive')->name('certification.email-test');
        Route::put('/webinars/{webinar}/certification/rules', [AdminCertificationController::class, 'updateRules'])->middleware(EnsureRecentPassword::class)->name('certification.rules');
        Route::put('/webinars/{webinar}/certification/template', [AdminCertificationController::class, 'updateTemplate'])->middleware(EnsureRecentPassword::class)->name('certification.template');
        Route::put('/webinars/{webinar}/certification/design', [AdminCertificationController::class, 'updateDesign'])->name('certification.design');
        Route::match(['get', 'post'], '/webinars/{webinar}/certification/preview', [AdminCertificationController::class, 'preview'])->name('certification.preview');
        Route::get('/webinars/{webinar}/certification/background', [AdminCertificationController::class, 'background'])->name('certification.background');

        Route::get('/webinars/{webinar}/participants', [AdminParticipantController::class, 'index'])->name('participants.index');
        Route::get('/webinars/{webinar}/responses', [FormResponseController::class, 'index'])->name('responses.index');
        Route::post('/webinars/{webinar}/responses/filter', [FormResponseController::class, 'filter'])->name('responses.filter');
        Route::get('/webinars/{webinar}/responses/export', [FormResponseController::class, 'export'])->middleware(EnsureRecentPassword::class)->name('responses.export');
        Route::post('/webinars/{webinar}/responses/approve', [FormResponseController::class, 'approve'])->middleware(EnsureRecentPassword::class)->name('responses.approve');
        Route::get('/webinars/{webinar}/responses/{submission}', [FormResponseController::class, 'show'])->name('responses.show');
        Route::post('/webinars/{webinar}/participants', [AdminParticipantController::class, 'store'])->middleware(EnsureRecentPassword::class)->name('participants.store');
        Route::post('/webinars/{webinar}/participants/filter', [AdminParticipantController::class, 'filter'])->name('participants.filter');
        Route::get('/webinars/{webinar}/participants/export', [AdminParticipantController::class, 'export'])->middleware(EnsureRecentPassword::class)->name('participants.export');
        Route::get('/webinars/{webinar}/participants/{participant}', [AdminParticipantController::class, 'show'])->name('participants.show');
        Route::post('/webinars/{webinar}/participants/{participant}/attendance', [AdminParticipantController::class, 'attendance'])->name('participants.attendance');
        Route::put('/webinars/{webinar}/participants/{participant}/name', [AdminParticipantController::class, 'updateName'])->middleware(EnsureRecentPassword::class)->name('participants.name');
        Route::post('/webinars/{webinar}/participants/{participant}/merge', [AdminParticipantController::class, 'merge'])->middleware(EnsureRecentPassword::class)->name('participants.merge');
        Route::post('/webinars/{webinar}/participants/{participant}/override', [AdminParticipantController::class, 'override'])->middleware(EnsureRecentPassword::class)->name('participants.override');
        Route::delete('/webinars/{webinar}/participants/{participant}/overrides/{eligibilityOverride}', [AdminParticipantController::class, 'destroyOverride'])->middleware(EnsureRecentPassword::class)->name('participants.override.destroy');
        Route::delete('/webinars/{webinar}/participants/{participant}', [AdminParticipantController::class, 'destroy'])->middleware(EnsureRecentPassword::class)->name('participants.destroy');

        Route::get('/email-logs', [AdminEmailDeliveryController::class, 'index'])->middleware(EnsureAdministrator::class)->name('email-logs.index');
        Route::post('/email-logs/{delivery:public_id}/status', [AdminEmailDeliveryController::class, 'refresh'])->middleware([EnsureAdministrator::class, EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('email-logs.status');
        Route::get('/webinars/{webinar}/email-logs', [AdminEmailDeliveryController::class, 'webinar'])->name('webinars.email-logs.index');
        Route::post('/webinars/{webinar}/email-logs/{emailDelivery:public_id}/status', [AdminEmailDeliveryController::class, 'refreshWebinar'])->middleware([EnsureRecentPassword::class, 'throttle:admin-sensitive'])->name('webinars.email-logs.status');

        Route::get('/webinars/{webinar}/imports', [AdminImportController::class, 'index'])->name('webinars.imports.index');
        Route::get('/webinars/{webinar}/imports/upload', [AdminImportController::class, 'create'])->name('webinars.imports.create');
        Route::post('/webinars/{webinar}/imports/preview', [AdminImportController::class, 'preview'])->middleware(EnsureRecentPassword::class)->name('webinars.imports.preview');
        Route::get('/webinars/{webinar}/imports/{import}/map', [AdminImportController::class, 'map'])->name('webinars.imports.map');
        Route::get('/webinars/{webinar}/imports/{import}/reconciliation', [AdminImportController::class, 'reconciliation'])->middleware(EnsureRecentPassword::class)->name('webinars.imports.reconciliation');
        Route::post('/webinars/{webinar}/imports/{import}/dry-run', [AdminImportController::class, 'dryRun'])->middleware(EnsureRecentPassword::class)->name('webinars.imports.dry-run');
        Route::match(['post', 'put'], '/webinars/{webinar}/imports/{import}', [AdminImportController::class, 'store'])->middleware(EnsureRecentPassword::class)->name('webinars.imports.store');
        Route::post('/webinars/{webinar}/imports/{import}/cancel', [AdminImportController::class, 'cancel'])->middleware(EnsureRecentPassword::class)->name('webinars.imports.cancel');
        Route::post('/webinars/{webinar}/import-issues/{importIssue}/promote', [AdminImportController::class, 'promote'])->middleware(EnsureRecentPassword::class)->name('webinars.import-issues.promote');
        Route::post('/webinars/{webinar}/import-issues/{importIssue}/replace', [AdminImportController::class, 'replaceResponse'])->middleware(EnsureRecentPassword::class)->name('webinars.import-issues.replace');
        Route::post('/webinars/{webinar}/import-issues/{importIssue}/dismiss', [AdminImportController::class, 'dismiss'])->middleware(EnsureRecentPassword::class)->name('webinars.import-issues.dismiss');

        Route::get('/webinars/{webinar}/certificates/studio', [AdminCertificateController::class, 'studio'])->name('certificates.studio');
        Route::post('/webinars/{webinar}/certificates/studio/recipients', [AdminCertificateController::class, 'studio'])->name('certificates.studio.recipients');
        Route::post('/webinars/{webinar}/certificates/studio/manual', [ManualCertificateRecipientController::class, 'store'])->middleware('throttle:admin-sensitive')->name('certificates.manual.store');
        Route::put('/webinars/{webinar}/certificates/studio/manual/{participant}', [ManualCertificateRecipientController::class, 'update'])->middleware(EnsureRecentPassword::class)->name('certificates.manual.update');
        Route::delete('/webinars/{webinar}/certificates/studio/manual/{participant}', [ManualCertificateRecipientController::class, 'destroy'])->middleware(EnsureRecentPassword::class)->name('certificates.manual.destroy');
        Route::get('/webinars/{webinar}/certificates/studio/status', [AdminCertificateController::class, 'status'])->name('certificates.studio.status');
        Route::get('/webinars/{webinar}/certificates/export-zip', [AdminCertificateController::class, 'exportZip'])->name('webinars.certificates.export-zip');
        Route::post('/webinars/{webinar}/certificates/issue-selected', [AdminCertificateController::class, 'issueSelected'])->middleware('throttle:admin-sensitive')->name('certificates.issue-selected');
        Route::post('/webinars/{webinar}/certificates/send-plan', [AdminCertificateController::class, 'sendPlan'])->middleware('throttle:admin-sensitive')->name('certificates.send-plan');
        Route::post('/webinars/{webinar}/certificates/send-step', [AdminCertificateController::class, 'sendStep'])->middleware('throttle:30,1')->name('certificates.send-step');
        Route::post('/certificates/{certificate}/resend', [AdminCertificateController::class, 'resend'])->middleware('throttle:admin-sensitive')->name('certificates.resend');
        Route::post('/webinars/{webinar}/certificates/batch', [AdminCertificateController::class, 'batch'])->middleware('throttle:admin-sensitive')->name('certificates.batch');
        Route::post('/webinars/{webinar}/participants/{participant}/certificate', [AdminCertificateController::class, 'store'])->middleware('throttle:admin-sensitive')->name('certificates.store');
        Route::get('/certificates/{certificate}/download', [AdminCertificateController::class, 'download'])->name('certificates.download');
        Route::post('/certificates/{certificate}/revoke', [AdminCertificateController::class, 'revoke'])->middleware(EnsureRecentPassword::class)->name('certificates.revoke');
    });

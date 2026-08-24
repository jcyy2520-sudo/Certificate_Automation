<?php

use App\Http\Controllers\Admin\CertificateController as AdminCertificateController;
use App\Http\Controllers\Admin\CertificationController as AdminCertificationController;
use App\Http\Controllers\Admin\FormController as AdminFormController;
use App\Http\Controllers\Admin\ParticipantController as AdminParticipantController;
use App\Http\Controllers\Admin\WebinarController as AdminWebinarController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificateVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ParticipantAccessController;
use App\Http\Controllers\ParticipantStatusController;
use App\Http\Controllers\PasswordConfirmationController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureRecentPassword;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Participant surface
|---------------------------------------------------------------------------
| A share link opens exactly one form and nothing else. There is deliberately
| no event listing, no participant account, and no navigation off these pages.
*/
Route::get('/f/{token}', [PublicFormController::class, 'show'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:public-form-view')
    ->name('forms.public');

Route::post('/f/{token}/access/request', [ParticipantAccessController::class, 'request'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:participant-access-request')
    ->name('forms.public.access.request');

Route::get('/f/{token}/access/confirm', [ParticipantAccessController::class, 'confirm'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:60,1')
    ->name('forms.public.access.confirm');

Route::post('/f/{token}/access/confirm', [ParticipantAccessController::class, 'consume'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:participant-access-confirm')
    ->name('forms.public.access.consume');

Route::post('/f/{token}', [PublicFormController::class, 'submit'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:public-form-submit')
    ->name('forms.public.submit');

Route::get('/f/{token}/submitted', [PublicFormController::class, 'thanks'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:public-form-thanks')
    ->name('forms.public.thanks');

Route::get('/f/{token}/status', [ParticipantStatusController::class, 'entry'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:public-form-view')
    ->name('forms.public.status');

Route::post('/f/{token}/status/access/request', [ParticipantStatusController::class, 'request'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:participant-access-request')
    ->name('forms.public.status.access.request');

Route::get('/f/{token}/status/access/confirm', [ParticipantStatusController::class, 'confirm'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:60,1')
    ->name('forms.public.status.access.confirm');

Route::post('/f/{token}/status/access/confirm', [ParticipantStatusController::class, 'consume'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:participant-access-confirm')
    ->name('forms.public.status.access.consume');

Route::get('/f/{token}/status/view', [ParticipantStatusController::class, 'show'])
    ->where('token', '[a-z0-9]{24}')
    ->middleware('throttle:public-form-view')
    ->name('forms.public.status.view');

Route::get('/f/{token}/status/certificates/{certificatePublicId}/download', [ParticipantStatusController::class, 'download'])
    ->where([
        'token' => '[a-z0-9]{24}',
        'certificatePublicId' => '[0-9A-HJKMNP-TV-Z]{26}',
    ])
    ->middleware('throttle:30,1')
    ->name('forms.public.status.certificate.download');

// Certificate holders and anyone they show a certificate to. Reveals only the
// event, issue date, and validity for an exact code — never personal data.
Route::get('/certificates/verify/{code}', CertificateVerificationController::class)
    ->where('code', '[A-Za-z0-9-]{12,40}')
    ->middleware('throttle:30,1')
    ->name('certificates.verify');

// The root gives visitors nothing to explore.
Route::get('/', fn () => redirect()->route('login'))->name('home');

/*
|---------------------------------------------------------------------------
| Administrator surface
|---------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AuthController::class, 'create'])->name('login');
    Route::post('/admin/login', [AuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');
    Route::get('/admin/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/admin/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:admin-two-factor-challenge')->name('two-factor.challenge.store');
});

Route::middleware(['auth', EnsureAdministrator::class, 'auth.session', EnsureTwoFactorEnabled::class])
    ->scopeBindings()
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/confirm-password', [PasswordConfirmationController::class, 'show'])->name('password.confirm');
        Route::post('/confirm-password', [PasswordConfirmationController::class, 'store'])
            ->middleware('throttle:admin-sensitive')
            ->name('password.confirm.store');

        Route::get('/security/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('/security/two-factor', [TwoFactorController::class, 'enable'])->middleware('throttle:admin-sensitive')->name('two-factor.enable');
        Route::post('/security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->middleware('throttle:admin-sensitive')->name('two-factor.recovery-codes');
        Route::delete('/security/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:admin-sensitive')->name('two-factor.disable');

        Route::resource('webinars', AdminWebinarController::class)->except('destroy');
        Route::get('/webinars/{webinar}/reports', [AdminWebinarController::class, 'reports'])->name('webinars.reports');
        Route::delete('/webinars/{webinar}', [AdminWebinarController::class, 'destroy'])
            ->middleware(EnsureRecentPassword::class)
            ->name('webinars.destroy');
        Route::post('/webinars/{webinar}/archive', [AdminWebinarController::class, 'archive'])
            ->middleware(EnsureRecentPassword::class)
            ->name('webinars.archive');

        Route::get('/webinars/{webinar}/forms/{form}/edit', [AdminFormController::class, 'edit'])->name('forms.edit');
        Route::put('/webinars/{webinar}/forms/{form}', [AdminFormController::class, 'update'])->name('forms.update');
        Route::post('/webinars/{webinar}/forms/{form}/toggle', [AdminFormController::class, 'toggle'])->name('forms.toggle');
        Route::post('/webinars/{webinar}/forms/{form}/rotate-link', [AdminFormController::class, 'rotateLink'])
            ->middleware(EnsureRecentPassword::class)
            ->name('forms.rotate-link');
        Route::post('/webinars/{webinar}/forms/{form}/fields', [AdminFormController::class, 'storeField'])->name('forms.fields.store');
        Route::put('/webinars/{webinar}/forms/{form}/fields/{field}', [AdminFormController::class, 'updateField'])->name('forms.fields.update');
        Route::delete('/webinars/{webinar}/forms/{form}/fields/{field}', [AdminFormController::class, 'destroyField'])
            ->middleware(EnsureRecentPassword::class)
            ->name('forms.fields.destroy');
        Route::post('/webinars/{webinar}/forms/{form}/questions', [AdminFormController::class, 'storeQuestion'])->name('forms.questions.store');
        Route::put('/webinars/{webinar}/forms/{form}/questions/{question}', [AdminFormController::class, 'updateQuestion'])->name('forms.questions.update');
        Route::delete('/webinars/{webinar}/forms/{form}/questions/{question}', [AdminFormController::class, 'destroyQuestion'])
            ->middleware(EnsureRecentPassword::class)
            ->name('forms.questions.destroy');

        Route::get('/webinars/{webinar}/certification', [AdminCertificationController::class, 'edit'])->name('certification.edit');
        Route::put('/webinars/{webinar}/certification/rules', [AdminCertificationController::class, 'updateRules'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certification.rules');
        Route::put('/webinars/{webinar}/certification/template', [AdminCertificationController::class, 'updateTemplate'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certification.template');
        // Cosmetic name placement from the certificate editor — audited but not
        // recent-password gated, so visual editing stays fluid.
        Route::put('/webinars/{webinar}/certification/design', [AdminCertificationController::class, 'updateDesign'])
            ->name('certification.design');
        Route::get('/webinars/{webinar}/certification/preview', [AdminCertificationController::class, 'preview'])->name('certification.preview');
        Route::get('/webinars/{webinar}/certification/background', [AdminCertificationController::class, 'background'])->name('certification.background');

        Route::get('/webinars/{webinar}/participants', [AdminParticipantController::class, 'index'])->name('participants.index');
        Route::post('/webinars/{webinar}/participants', [AdminParticipantController::class, 'store'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.store');
        Route::post('/webinars/{webinar}/participants/filter', [AdminParticipantController::class, 'filter'])->name('participants.filter');
        Route::get('/webinars/{webinar}/participants/export', [AdminParticipantController::class, 'export'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.export');
        Route::get('/webinars/{webinar}/participants/{participant}', [AdminParticipantController::class, 'show'])->name('participants.show');
        Route::post('/webinars/{webinar}/participants/{participant}/attendance', [AdminParticipantController::class, 'attendance'])
            ->name('participants.attendance');
        Route::put('/webinars/{webinar}/participants/{participant}/name', [AdminParticipantController::class, 'updateName'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.name');
        Route::post('/webinars/{webinar}/participants/{participant}/override', [AdminParticipantController::class, 'override'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.override');
        Route::delete('/webinars/{webinar}/participants/{participant}/overrides/{eligibilityOverride}', [AdminParticipantController::class, 'destroyOverride'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.override.destroy');
        Route::delete('/webinars/{webinar}/participants/{participant}', [AdminParticipantController::class, 'destroy'])
            ->middleware(EnsureRecentPassword::class)
            ->name('participants.destroy');

        Route::get('/webinars/{webinar}/certificates/studio', [AdminCertificateController::class, 'studio'])->name('certificates.studio');
        Route::get('/webinars/{webinar}/certificates/studio/status', [AdminCertificateController::class, 'status'])->name('certificates.studio.status');
        // Issue only the explicit selection prepared in the participant table;
        // the final send remains behind the recent-password gate.
        Route::post('/webinars/{webinar}/certificates/issue-selected', [AdminCertificateController::class, 'issueSelected'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.issue-selected');
        Route::post('/certificates/{certificate}/resend', [AdminCertificateController::class, 'resend'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.resend');
        Route::post('/webinars/{webinar}/certificates/batch', [AdminCertificateController::class, 'batch'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.batch');
        Route::post('/webinars/{webinar}/participants/{participant}/certificate', [AdminCertificateController::class, 'store'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.store');
        Route::get('/certificates/{certificate}/download', [AdminCertificateController::class, 'download'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.download');
        Route::post('/certificates/{certificate}/revoke', [AdminCertificateController::class, 'revoke'])
            ->middleware(EnsureRecentPassword::class)
            ->name('certificates.revoke');
    });

<?php

use App\Models\User;
use App\Models\Webinar;
use App\Services\FormResponseService;
use App\Services\TwoFactorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('webinar.email.provider') !== 'log') {
    throw new RuntimeException('Browser fixtures may only reset the dedicated workflow-browser.sqlite database with log email.');
}
if (! is_file($expected)) {
    touch($expected);
}
Artisan::call('migrate:fresh', ['--force' => true]);
$admin = User::query()->create(['name' => 'Workflow Reviewer', 'email' => 'workflow@example.test', 'password' => Hash::make('Workflow-fixture-2026!'), 'role' => User::ADMINISTRATOR_ROLE, 'is_active' => true, 'approval_status' => 'approved', 'password_setup_at' => now()]);
// Independent synthetic identity for the sensitive invitation/MFA workflow.
$inviter = User::query()->create(['name' => 'Invitation Reviewer', 'email' => 'inviter@example.test', 'password' => Hash::make('Inviter-fixture-2026!'), 'role' => User::ADMINISTRATOR_ROLE, 'is_active' => true, 'approval_status' => 'approved', 'password_setup_at' => now()]);
$inviter->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => app(TwoFactorService::class)->hashRecoveryCodes(['FIXTR-00001', 'FIXTR-00002'])])->save();
$webinar = Webinar::query()->create(['title' => 'Workflow browser fixture', 'slug' => 'workflow-browser-fixture', 'status' => 'published', 'timezone' => 'Asia/Manila', 'ends_at' => now()->addDay(), 'data_retention_days' => 30, 'created_by' => $admin->id, 'requires_verification' => false]);
foreach (FormResponseService::TYPES as $type => $label) {
    $form = $webinar->forms()->create(['type' => $type, 'title' => $label, 'status' => 'published', 'max_attempts' => 3]);
    $form->fields()->create(['key' => 'feedback', 'label' => 'Session feedback', 'field_type' => 'radio', 'options' => ['Useful', 'Very useful'], 'is_required' => true, 'sort_order' => 1]);
}
$webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
$image = imagecreatetruecolor(1200, 850);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
$ink = imagecolorallocate($image, 25, 35, 50);
imagestring($image, 5, 420, 160, 'CERTIFICATE OF PARTICIPATION', $ink);
imageline($image, 250, 510, 950, 510, $ink);
ob_start();
imagepng($image);
$png = ob_get_clean();
imagedestroy($image);
Storage::disk('local')->put('certificate-backgrounds/workflow-browser.png', $png);
$webinar->certificateTemplates()->create(['name' => 'Workflow fixture', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/workflow-browser.png', 'is_active' => true, 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554']]);

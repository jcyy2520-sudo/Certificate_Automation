<?php

use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('webinar.email.provider') !== 'log' || config('webinar.email.certificate_provider') !== 'log') {
    throw new RuntimeException('UI fixtures require the dedicated workflow database and log transport.');
}
$admin = User::query()->where('email', 'workflow@example.test')->sole();
$suffix = bin2hex(random_bytes(5));
$password = 'UI-fixture-only-2026!';
$owner = User::forceCreate(['name' => 'Synthetic Webinar Owner', 'email' => 'ui-owner-'.$suffix.'@example.test', 'password' => Hash::make($password),
    'role' => User::USER_ROLE, 'account_source' => 'public_request', 'approval_status' => 'approved', 'is_active' => true, 'email_verified_at' => now(), 'password_setup_at' => now()]);
$manager = User::forceCreate(['name' => 'Synthetic Webinar Manager', 'email' => 'ui-manager-'.$suffix.'@example.test', 'password' => Hash::make($password),
    'role' => User::MANAGER_ROLE, 'account_source' => 'admin_invitation', 'approval_status' => 'approved', 'is_active' => true, 'email_verified_at' => now(), 'password_setup_at' => now()]);
$makeWebinar = function (User $creator, string $title) use ($suffix): Webinar {
    $webinar = Webinar::create(['title' => $title, 'slug' => 'ui-'.$creator->id.'-'.$suffix, 'created_by' => $creator->id,
        'status' => 'published', 'ends_at' => now()->addDay(), 'data_retention_days' => 30, 'timezone' => 'Asia/Manila', 'requires_verification' => false]);
    foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
        $webinar->forms()->create(['type' => $type, 'title' => ucfirst($type), 'status' => 'published']);
    }
    $webinar->certificateTemplates()->create(['name' => 'Synthetic UI template', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/workflow-browser.png',
        'is_active' => true, 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans']]);

    return $webinar;
};
$webinar = $makeWebinar($admin, 'Teaching, learning and evidence: a practical webinar for educational teams');
$protected = $makeWebinar($owner, 'Administrator lock browser fixture '.$suffix);
$manager->assignedWebinars()->attach($protected, ['assigned_by' => $admin->id, 'manage_participants' => true, 'manage_certificates' => true, 'send_emails' => true]);
$form = $webinar->forms()->where('type', 'registration')->sole();
$fields = [];
foreach (['Organization', 'Position', 'Learning goals', 'Preferred session', 'Accessibility requests', 'Additional comments'] as $index => $label) {
    $fields[] = $form->fields()->create(['key' => 'ui_'.$index, 'label' => $label, 'field_type' => 'text', 'is_required' => false, 'sort_order' => $index]);
}
for ($i = 1; $i <= 8; $i++) {
    $participant = $webinar->participants()->create(['full_name' => 'Synthetic Participant '.$i.' With a Longer Display Name', 'email' => 'long-ui-participant-'.$i.'@example.test', 'verified_at' => now()]);
    $submission = Submission::create(['participant_id' => $participant->id, 'form_id' => $form->id, 'status' => 'submitted', 'submitted_at' => now(), 'attempt_number' => 1]);
    foreach ($fields as $index => $field) {
        $answer = $index === 5 ? 'Later answer '.$i.': '.str_repeat('Complete response content remains available on mobile. ', 5) : 'Synthetic answer '.$i.' for '.$field->label;
        $submission->answers()->create(['form_field_id' => $field->id, 'value' => [$answer]]);
    }
}
echo json_encode(['webinar' => $webinar->id, 'form' => $form->id, 'protected' => $protected->id, 'protectedForm' => $protected->forms()->where('type', 'registration')->sole()->id, 'protectedTitle' => $protected->title,
    'ownerEmail' => $owner->email, 'managerEmail' => $manager->email, 'password' => $password]);

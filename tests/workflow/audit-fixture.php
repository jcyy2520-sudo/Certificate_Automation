<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('accounts.email_provider') !== 'log' || config('webinar.email.provider') !== 'log' || filled(config('services.brevo.key'))) {
    throw new RuntimeException('Only isolated browser fixtures with no live mail are allowed.');
}
$admin = User::query()->where('email', 'workflow@example.test')->sole();
$manager = User::factory()->create(['name' => 'Audit Browser Manager', 'email' => 'audit-manager@example.test',
    'role' => User::MANAGER_ROLE, 'password' => Hash::make('Audit-browser-fixture-2026!')]);
$a = Webinar::factory()->create(['title' => 'Audit Webinar A', 'created_by' => $admin->id]);
$b = Webinar::factory()->create(['title' => 'Audit Webinar B', 'created_by' => $admin->id]);
$manager->assignedWebinars()->attach($a->id, ['assigned_by' => $admin->id, 'manage_participants' => true, 'manage_certificates' => false, 'send_emails' => false]);
foreach ([[$a, 'webinar_a.reviewed'], [$b, 'webinar_b.private'], [null, 'account.private_global']] as [$event, $action]) {
    AuditLog::query()->create(['action' => $action, 'webinar_id' => $event?->id, 'actor_user_id' => $admin->id,
        'user_id' => $admin->id, 'actor_name' => $admin->name, 'actor_email' => $admin->email]);
}
echo json_encode(['a' => $a->id, 'b' => $b->id]);

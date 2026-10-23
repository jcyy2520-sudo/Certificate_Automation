<?php

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || filled(config('database.connections.sqlite.url'))
    || config('accounts.email_provider') !== 'log' || config('webinar.email.provider') !== 'log'
    || config('webinar.email.certificate_provider') !== 'log') {
    throw new RuntimeException('Studio style fixtures require the dedicated synthetic workflow database and log email.');
}
$owner = User::query()->where('email_normalized', 'workflow@example.test')->sole();
if (! $owner->isAdministrator() || ! $owner->canAccessPlatform()) {
    throw new RuntimeException('The synthetic workflow administrator is unavailable.');
}
// Each style test owns its complete roster and template. Other workflow files
// intentionally retain their participants and must not determine this setup.
$webinar = Webinar::query()->create([
    'title' => 'Isolated Studio style fixture', 'slug' => 'studio-style-'.Str::uuid(), 'created_by' => $owner->id,
    'status' => 'published', 'timezone' => 'Asia/Manila', 'ends_at' => now()->addDay(), 'data_retention_days' => 30,
]);
$webinar->certificateTemplates()->create([
    'name' => 'Isolated Studio style design', 'storage_disk' => 'local',
    'background_path' => 'certificate-backgrounds/workflow-browser.png', 'is_active' => true,
    'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50,
        'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554'],
]);
echo json_encode(['webinar' => $webinar->id], JSON_THROW_ON_ERROR);

<?php

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.url')
    || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected) || config('webinar.email.provider') !== 'log') {
    throw new RuntimeException('Synthetic fixtures require the isolated workflow database and log mail.');
}
$owner = User::where('email', 'workflow@example.test')->firstOrFail();
$webinar = Webinar::create(['title' => 'Message workflow fixture', 'slug' => 'message-workflow-'.bin2hex(random_bytes(6)), 'created_by' => $owner->id, 'status' => 'published', 'ends_at' => now()->addDay(), 'data_retention_days' => 30]);
foreach (['Alex', 'Jamie'] as $name) {
    $webinar->participants()->create(['full_name' => $name.' Synthetic', 'email' => strtolower($name).'@example.test', 'verified_at' => now()]);
}
echo json_encode(['webinar' => $webinar->id]);

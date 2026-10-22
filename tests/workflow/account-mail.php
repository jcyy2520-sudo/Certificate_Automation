<?php

use App\Models\EmailDelivery;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('webinar.email.provider') !== 'log') {
    throw new RuntimeException('Only the isolated synthetic browser database may be inspected.');
}
$delivery = EmailDelivery::query()->where('type', $argv[1])->whereHas('account', fn ($q) => $q->where('email', 'browser.account@example.test'))->latest('id')->firstOrFail();
preg_match('/href="([^"]+)"/', $delivery->payload['html'], $match);
echo html_entity_decode($match[1]);

<?php

use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$expected = realpath(__DIR__.'/../../storage/app/private').DIRECTORY_SEPARATOR.'workflow-browser.sqlite';
if (! $app->environment('testing') || str_replace('\\', '/', config('database.connections.sqlite.database')) !== str_replace('\\', '/', $expected)
    || config('accounts.email_provider') !== 'log' || config('webinar.email.provider') !== 'log') {
    throw new RuntimeException('Only the synthetic browser database may be used.');
}
if (($argv[1] ?? '') === 'otp') {
    $user = User::query()->where('email', 'inviter@example.test')->sole();
    $google = app(Google2FA::class);
    echo $google->oathTotp($user->two_factor_secret, $google->getTimestamp() + min(1, max(0, (int) ($argv[2] ?? 0))));
} elseif (($argv[1] ?? '') === 'invitation') {
    $row = EmailDelivery::query()->where('type', 'account_setup')->whereHas('account', fn ($q) => $q->where('email', 'invited.browser@example.test'))->latest('id')->firstOrFail();
    preg_match('/href="([^"]+)"/', $row->payload['html'], $match);
    echo html_entity_decode($match[1]);
}

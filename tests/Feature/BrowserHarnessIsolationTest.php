<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BrowserHarnessIsolationTest extends TestCase
{
    public function test_browser_router_refuses_unsafe_environments_before_bootstrapping_laravel(): void
    {
        $safe = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => base_path('storage/app/private/workflow-browser.sqlite'), 'DB_URL' => '',
            'BREVO_API_KEY' => '', 'TRANSACTIONAL_EMAIL_PROVIDER' => 'log',
            'ACCOUNT_EMAIL_PROVIDER' => 'log', 'CERTIFICATE_EMAIL_PROVIDER' => 'log', 'MAIL_MAILER' => 'log',
        ];
        foreach ([
            ['APP_ENV' => 'production'], ['DB_CONNECTION' => 'pgsql'],
            ['DB_DATABASE' => database_path('database.sqlite')], ['DB_URL' => 'sqlite://wrong-database'],
            ['BREVO_API_KEY' => 'synthetic-must-never-be-used'], ['TRANSACTIONAL_EMAIL_PROVIDER' => 'brevo'],
            ['ACCOUNT_EMAIL_PROVIDER' => 'brevo'], ['CERTIFICATE_EMAIL_PROVIDER' => 'brevo'], ['MAIL_MAILER' => 'smtp'],
        ] as $unsafe) {
            $process = new Process([PHP_BINARY, base_path('tests/workflow/server.php')], base_path(), array_replace($safe, $unsafe));
            $process->setTimeout(10)->run();
            $this->assertSame('Browser test isolation guard refused to start.', $process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
        }
    }
}

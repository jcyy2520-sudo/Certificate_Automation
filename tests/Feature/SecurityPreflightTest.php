<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SecurityPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_preflight_fails_closed_without_disclosing_credentials(): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.debug' => false,
            'security.bootstrap_admin_password_present' => true,
            'services.brevo.key' => 'do-not-print-this-secret',
            'webinar.email.provider' => 'brevo',
        ]);

        $exit = Artisan::call('security:check', ['--production' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL  APP_URL uses HTTPS', $output);
        $this->assertStringContainsString('FAIL  No bootstrap administrator password is retained', $output);
        $this->assertStringContainsString('FAIL  At least one active administrator has MFA enrolled', $output);
        $this->assertStringNotContainsString('do-not-print-this-secret', $output);
    }
}

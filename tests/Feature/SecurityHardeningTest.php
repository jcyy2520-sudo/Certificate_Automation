<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    public function test_dynamic_responses_are_not_cached_and_send_browser_defenses(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '0')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('Origin-Agent-Cluster', '?1')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $permissions = (string) $response->headers->get('Permissions-Policy');
        $this->assertStringContainsString('camera=()', $permissions);
        $this->assertStringContainsString('microphone=()', $permissions);
        $this->assertStringContainsString('geolocation=()', $permissions);
    }

    public function test_content_security_policy_blocks_external_content_and_framing(): void
    {
        $response = $this->get('/admin/login');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertNotSame('', $policy);
        $this->assertStringContainsString("default-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("script-src-attr 'none'", $policy);
        $this->assertStringContainsString("require-trusted-types-for 'script'", $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $policy);

        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
    }

    public function test_inline_scripts_require_the_request_nonce_and_event_attributes_are_absent(): void
    {
        $templates = glob(resource_path('views/**/*.blade.php')) ?: [];
        $templates = [...$templates, ...(glob(resource_path('views/**/**/*.blade.php')) ?: [])];

        foreach (array_unique($templates) as $template) {
            $contents = (string) file_get_contents($template);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*nonce="\{\{ \$cspNonce \}\}")/i', $contents, $template);
            $this->assertDoesNotMatchRegularExpression('/\son(?:click|change|submit|load|error|focus|blur|input|key\w+|mouse\w+)\s*=/i', $contents, $template);
        }
    }

    public function test_https_responses_enable_hsts_and_upgrade_mixed_content(): void
    {
        $response = $this->get('https://localhost/admin/login');

        $response->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $this->assertStringContainsString(
            'upgrade-insecure-requests',
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_session_cookie_is_secure_http_only_and_same_site(): void
    {
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');

        $response = $this->get('https://localhost/admin/login');
        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    public function test_only_exact_configured_hosts_are_trusted(): void
    {
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $trustedPatterns = app(TrustHosts::class)->hosts();

        $this->assertNotEmpty($appHost);
        $this->assertContains('^'.preg_quote($appHost, '/').'$', $trustedPatterns);
        $this->assertNotContains('^(.+\.)?'.preg_quote($appHost, '/').'$', $trustedPatterns);
    }
}

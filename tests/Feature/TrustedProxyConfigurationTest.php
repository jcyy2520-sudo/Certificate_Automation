<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\TrustedProxyConfiguration;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_proxy_mode_disabled_does_not_trust_forwarded_headers(): void
    {
        $this->bootProxyConfiguration(false, ['173.245.48.0/20']);

        $request = $this->requestFrom('173.245.48.10');
        $this->handleTrustedProxies($request);

        $this->assertFalse($request->isSecure());
        $this->assertSame('173.245.48.10', $request->ip());
    }

    public function test_proxy_mode_enabled_uses_a_valid_proxy_list(): void
    {
        $this->bootProxyConfiguration(true, ['173.245.48.0/20']);

        $request = $this->requestFrom('173.245.48.10');
        $this->handleTrustedProxies($request);

        $this->assertTrue($request->isSecure());
        $this->assertSame('198.51.100.72', $request->ip());
    }

    public function test_proxy_configuration_parses_multiple_cidrs_and_whitespace(): void
    {
        $proxies = TrustedProxyConfiguration::proxies(' 173.245.48.0/20, 103.21.244.0/22, , 173.245.48.0/20 ');

        $this->assertSame(['173.245.48.0/20', '103.21.244.0/22'], $proxies);

        $this->bootProxyConfiguration(true, $proxies);
        $request = $this->requestFrom('103.21.244.8');
        $this->handleTrustedProxies($request);

        $this->assertTrue($request->isSecure());
        $this->assertSame('198.51.100.72', $request->ip());
    }

    public function test_empty_proxy_value_produces_no_trusted_proxies_and_boots_safely(): void
    {
        $this->assertSame([], TrustedProxyConfiguration::proxies('  ,   '));

        $this->bootProxyConfiguration(true, []);
        $request = $this->requestFrom('173.245.48.10');
        $this->handleTrustedProxies($request);

        $this->assertFalse($request->isSecure());
        $this->assertSame('173.245.48.10', $request->ip());
    }

    public function test_malformed_or_unsafe_proxy_values_are_rejected(): void
    {
        $proxies = TrustedProxyConfiguration::proxies(
            '*, **, REMOTE_ADDR, 0.0.0.0/0, ::/0, 198.51.100.0/0, 198.51.100.0/33, not-an-ip',
        );

        $this->assertSame([], $proxies);

        $this->bootProxyConfiguration(true, $proxies);
        $request = $this->requestFrom('203.0.113.42');
        $this->handleTrustedProxies($request);

        $this->assertFalse($request->isSecure());
        $this->assertSame('203.0.113.42', $request->ip());
    }

    public function test_forwarded_headers_from_an_untrusted_address_are_ignored(): void
    {
        $this->bootProxyConfiguration(true, ['173.245.48.0/20']);

        $request = $this->requestFrom('203.0.113.42');
        $this->handleTrustedProxies($request);

        $this->assertFalse($request->isSecure());
        $this->assertSame('203.0.113.42', $request->ip());
    }

    public function test_trusted_hosts_are_trimmed_exact_and_never_wildcards(): void
    {
        $this->assertSame(
            ['app.jeeycee.site', 'admin.jeeycee.site'],
            TrustedProxyConfiguration::hosts(' app.jeeycee.site, *.jeeycee.site, admin.jeeycee.site, '),
        );
    }

    /**
     * @param  array<int, string>  $proxies
     */
    private function bootProxyConfiguration(bool $behindProxy, array $proxies): void
    {
        TrustProxies::flushState();
        config()->set('security.behind_proxy', $behindProxy);
        config()->set('app.trusted_proxies', $proxies);

        (new AppServiceProvider($this->app))->boot();
    }

    private function requestFrom(string $remoteAddress): Request
    {
        return Request::create('http://localhost/proxy-test', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_X_FORWARDED_FOR' => '198.51.100.72',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
    }

    private function handleTrustedProxies(Request $request): void
    {
        app(TrustProxies::class)->handle($request, fn (): Response => new Response);
    }
}

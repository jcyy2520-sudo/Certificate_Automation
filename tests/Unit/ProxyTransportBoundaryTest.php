<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ProxyTransportBoundaryTest extends TestCase
{
    public function test_container_passes_listener_tls_state_instead_of_a_client_header_to_php(): void
    {
        $nginx = file_get_contents(__DIR__.'/../../docker/nginx.conf');
        preg_match_all('/^\s*fastcgi_param\s+HTTPS\s+([^;]+);/m', $nginx, $parameters);

        $this->assertSame(['$https if_not_empty'], $parameters[1]);
    }

    public function test_forwarded_scheme_requires_a_trusted_proxy_and_preserves_http_and_https(): void
    {
        $previousProxies = Request::getTrustedProxies();
        $previousHeaders = Request::getTrustedHeaderSet();

        try {
            Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_PROTO);

            foreach (['http', 'https'] as $scheme) {
                $server = [
                    'SERVER_PORT' => 80,
                    'REMOTE_ADDR' => '198.51.100.8',
                    'HTTP_X_FORWARDED_PROTO' => $scheme,
                ];
                $this->assertFalse((new Request(server: $server))->isSecure());

                $server['REMOTE_ADDR'] = '192.0.2.10';
                $this->assertSame($scheme === 'https', (new Request(server: $server))->isSecure());
            }

            $this->assertTrue((new Request(server: ['HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.8']))->isSecure());
        } finally {
            Request::setTrustedProxies($previousProxies, $previousHeaders);
        }
    }
}

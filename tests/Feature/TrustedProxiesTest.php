<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/request-scheme', fn (Request $request): array => [
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]);
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->withHeaders($this->forwardedHeaders())
            ->get('/_test/request-scheme')
            ->assertExactJson(['secure' => false, 'host' => 'localhost']);
    }

    public function test_forwarded_headers_are_used_when_all_proxies_are_trusted(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->withHeaders($this->forwardedHeaders())
            ->get('/_test/request-scheme')
            ->assertExactJson(['secure' => true, 'host' => 'helpdesk.example.com']);
    }

    public function test_forwarded_headers_are_used_from_a_listed_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 192.168.1.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders($this->forwardedHeaders())
            ->get('/_test/request-scheme')
            ->assertExactJson(['secure' => true, 'host' => 'helpdesk.example.com']);
    }

    public function test_forwarded_headers_are_ignored_from_an_unlisted_proxy(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8, 192.168.1.10']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeaders($this->forwardedHeaders())
            ->get('/_test/request-scheme')
            ->assertExactJson(['secure' => false, 'host' => 'localhost']);
    }

    /**
     * @return array<string, string>
     */
    private function forwardedHeaders(): array
    {
        return [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'helpdesk.example.com',
            'X-Forwarded-Port' => '443',
        ];
    }
}

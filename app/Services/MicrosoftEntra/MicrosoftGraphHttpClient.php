<?php

namespace App\Services\MicrosoftEntra;

use App\Support\EntraSyncTarget;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MicrosoftGraphHttpClient
{
    private ?string $token = null;

    public function __construct(
        private readonly string $directoryTenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public static function fromTarget(EntraSyncTarget $target): self
    {
        $c = $target->config;

        return new self(
            directoryTenantId: trim((string) ($c['azure_tenant_id'] ?? '')),
            clientId: trim((string) ($c['application_client_id'] ?? '')),
            clientSecret: trim((string) ($c['client_secret'] ?? '')),
        );
    }

    public function authenticate(): void
    {
        $url = 'https://login.microsoftonline.com/'.$this->directoryTenantId.'/oauth2/v2.0/token';
        $response = Http::asForm()->timeout(30)->post($url, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Entra token request failed: HTTP '.$response->status().' '.$response->body());
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Microsoft Entra token response missing access_token.');
        }

        $this->token = $token;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    public function getJson(string $relativePath, array $query = []): array
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $url = 'https://graph.microsoft.com/v1.0/'.ltrim($relativePath, '/');
        $response = Http::withToken($this->token)->timeout(60)->retry(2, 500)->get($url, $query);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = Http::withToken($this->token)->timeout(60)->get($url, $query);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph GET '.$relativePath.' failed: HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginateCollection(string $relativePath, array $query = []): \Generator
    {
        $nextUrl = null;
        $isFirst = true;

        while (true) {
            if ($isFirst) {
                $data = $this->getJson($relativePath, $query);
                $isFirst = false;
            } else {
                $data = $this->getAbsoluteJson((string) $nextUrl);
            }

            foreach ($data['value'] ?? [] as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    yield $row;
                }
            }

            $next = $data['@odata.nextLink'] ?? null;
            if (! is_string($next) || $next === '') {
                break;
            }
            $nextUrl = $next;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getAbsoluteJson(string $absoluteUrl): array
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $response = Http::withToken($this->token)->timeout(120)->retry(2, 500)->get($absoluteUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph pagination request failed: HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }
}

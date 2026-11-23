<?php

namespace App\Services\MicrosoftEntra;

use App\Support\EntraSyncTarget;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Defender for Endpoint API client. It uses the same app registration and client secret as the Microsoft
 * Entra integration, but a token for the WindowsDefenderATP resource, so its application permissions
 * (Machine.Read.All) are granted and checked separately from Microsoft Graph.
 */
final class MicrosoftDefenderHttpClient
{
    public const TOKEN_SCOPE = 'https://api.securitycenter.microsoft.com/.default';

    public const API_BASE_URL = 'https://api.security.microsoft.com/api/';

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
            'scope' => self::TOKEN_SCOPE,
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Defender token request failed: HTTP '.$response->status().' '.$response->body());
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Microsoft Defender token response missing access_token.');
        }

        $this->token = $token;
    }

    /**
     * Application permissions granted on the Defender API (the `roles` claim of the Defender token), or null when
     * the token cannot be decoded.
     *
     * @return list<string>|null
     */
    public function grantedApplicationPermissions(): ?array
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $segments = explode('.', (string) $this->token);
        if (count($segments) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        $claims = $payload !== false ? json_decode($payload, true) : null;
        if (! is_array($claims)) {
            return null;
        }

        $roles = $claims['roles'] ?? [];

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /**
     * Pages through a Defender API collection (OData `value` + `@odata.nextLink`).
     *
     * @param  array<string, string|int>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginateCollection(string $relativePath, array $query = []): \Generator
    {
        $url = self::API_BASE_URL.ltrim($relativePath, '/');
        $params = $query;

        while ($url !== null) {
            $data = $this->get($url, $params);
            $params = [];

            foreach ($data['value'] ?? [] as $row) {
                if (is_array($row)) {
                    /** @var array<string, mixed> $row */
                    yield $row;
                }
            }

            $next = $data['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $response = Http::withToken($this->token)->timeout(120)->retry(2, 500, throw: false)->get($url, $query);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = Http::withToken($this->token)->timeout(120)->get($url, $query);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Defender GET '.parse_url($url, PHP_URL_PATH).' failed: HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }
}

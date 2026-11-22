<?php

namespace App\Services\MicrosoftEntra;

use App\Support\EntraSyncTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
     * Application permissions (the `roles` claim) granted to the app registration, read from the access token.
     * Returns null when the token cannot be decoded, so callers can tell "unknown" apart from "none granted".
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
        if ($payload === false) {
            return null;
        }

        $claims = json_decode($payload, true);
        if (! is_array($claims)) {
            return null;
        }

        $roles = $claims['roles'] ?? [];
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter($roles, 'is_string'));
    }

    /**
     * Best-effort lookup of when the client secret in use expires. Needs Application.Read.All (or broader);
     * returns null when the app may not read its own registration or no matching secret is found.
     */
    public function clientSecretExpiresAt(): ?Carbon
    {
        try {
            $application = $this->getJson("applications(appId='".rawurlencode($this->clientId)."')", [
                '$select' => 'passwordCredentials',
            ]);
        } catch (Throwable) {
            return null;
        }

        $credentials = $application['passwordCredentials'] ?? [];
        if (! is_array($credentials)) {
            return null;
        }

        foreach ($credentials as $credential) {
            if (! is_array($credential)) {
                continue;
            }

            $hint = (string) ($credential['hint'] ?? '');
            $endDateTime = $credential['endDateTime'] ?? null;
            if ($hint === '' || ! is_string($endDateTime) || ! str_starts_with($this->clientSecret, $hint)) {
                continue;
            }

            try {
                return Carbon::parse($endDateTime);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postJson(string $relativePath, array $payload = []): array
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $url = 'https://graph.microsoft.com/v1.0/'.ltrim($relativePath, '/');
        $response = Http::withToken($this->token)->timeout(60)->retry(2, 500)->post($url, $payload);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = Http::withToken($this->token)->timeout(60)->post($url, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph POST '.$relativePath.' failed: HTTP '.$response->status().' '.$response->body());
        }

        if ($response->body() === '') {
            return [];
        }

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }

    public function getRaw(string $relativePath): string
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $url = 'https://graph.microsoft.com/v1.0/'.ltrim($relativePath, '/');
        $response = Http::withToken($this->token)->timeout(120)->retry(2, 500)->get($url);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = Http::withToken($this->token)->timeout(120)->get($url);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph GET raw '.$relativePath.' failed: HTTP '.$response->status().' '.$response->body());
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function patchJson(string $relativePath, array $payload): void
    {
        if ($this->token === null) {
            $this->authenticate();
        }

        $url = 'https://graph.microsoft.com/v1.0/'.ltrim($relativePath, '/');
        $response = Http::withToken($this->token)->timeout(60)->retry(2, 500)->patch($url, $payload);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = Http::withToken($this->token)->timeout(60)->patch($url, $payload);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft Graph PATCH '.$relativePath.' failed: HTTP '.$response->status().' '.$response->body());
        }
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

<?php

namespace App\Services\Action1;

use App\Support\Action1SyncTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class Action1HttpClient
{
    private ?string $token = null;

    public function __construct(
        private readonly string $apiBaseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $apiKey = '',
    ) {}

    public static function fromTarget(Action1SyncTarget $target): self
    {
        $config = $target->config;
        $credentials = $target->credentials;

        $apiBaseUrl = trim((string) ($target->apiBaseUrl ?? $config['api_base_url'] ?? ''));
        if ($apiBaseUrl === '') {
            $apiBaseUrl = config('helixdesk_integrations.action1.default_api_base_url');
        }

        return new self(
            apiBaseUrl: self::normalizeBaseUrl($apiBaseUrl),
            clientId: trim((string) ($config['client_id'] ?? $credentials['client_id'] ?? '')),
            clientSecret: trim((string) ($config['client_secret'] ?? $credentials['client_secret'] ?? '')),
            apiKey: trim((string) ($config['api_key'] ?? $credentials['api_key'] ?? '')),
        );
    }

    public function authenticate(): void
    {
        if ($this->clientSecret === '' && $this->apiKey !== '') {
            $this->token = $this->apiKey;

            return;
        }

        $url = $this->apiBaseUrl.'oauth2/token';

        try {
            $response = $this->authHttpClient()->post($url, [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        } catch (ConnectionException $exception) {
            $this->logHttpFailure('Action1 token request connection failed.', 'oauth2/token', $exception);

            throw new RuntimeException('Action1 token request connection failed: '.$exception->getMessage(), 0, $exception);
        } catch (RequestException $exception) {
            $this->logHttpFailure('Action1 token request failed.', 'oauth2/token', $exception);

            throw new RuntimeException('Action1 token request failed: '.$exception->getMessage(), 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Action1 token request failed: HTTP '.$response->status().' '.$response->body());
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Action1 token response missing access_token.');
        }

        $this->token = $token;
    }

    /**
     * @return list<string>
     */
    public function listGroupEndpointIds(string $action1OrganizationId, string $groupId): array
    {
        $ids = [];

        foreach ($this->paginateData('endpoints/groups/'.$action1OrganizationId.'/'.$groupId.'/contents') as $item) {
            $endpointId = trim((string) ($item['id'] ?? $item['endpoint_id'] ?? ''));
            if ($endpointId !== '') {
                $ids[] = $endpointId;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listManagedEndpoints(string $action1OrganizationId): array
    {
        return $this->paginateData('endpoints/managed/'.$action1OrganizationId, [
            'fields' => '*',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getManagedEndpoint(string $action1OrganizationId, string $endpointId): array
    {
        return $this->getJson('endpoints/managed/'.$action1OrganizationId.'/'.$endpointId, [
            'fields' => '*',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInstalledSoftware(string $action1OrganizationId, string $endpointId): array
    {
        return $this->paginateData('installed-software/'.$action1OrganizationId.'/data/'.$endpointId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMissingUpdates(string $action1OrganizationId, string $endpointId): array
    {
        return $this->paginateData('endpoints/managed/'.$action1OrganizationId.'/'.$endpointId.'/missing-updates');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAuditEvents(string $action1OrganizationId): array
    {
        return $this->paginateData('audit/events', [
            'organization_id' => $action1OrganizationId,
        ]);
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

        $url = $this->apiBaseUrl.ltrim($relativePath, '/');
        $response = $this->sendGet($url, $query, $relativePath);

        if ($response->status() === 401) {
            $this->authenticate();
            $response = $this->sendGet($url, $query, $relativePath, retryOnAuth: false);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Action1 GET '.$relativePath.' failed: HTTP '.$response->status().' '.$response->body());
        }

        /** @var array<string, mixed> */
        $json = $response->json();

        return $json;
    }

    /**
     * @param  array<string, string|int>  $query
     * @return list<array<string, mixed>>
     */
    public function paginateData(string $relativePath, array $query = []): array
    {
        $items = [];
        $cursor = null;

        do {
            $pageQuery = $query;
            if ($cursor !== null) {
                $pageQuery['cursor'] = $cursor;
            }

            $payload = $this->getJson($relativePath, $pageQuery);
            $chunk = $payload['data'] ?? $payload['items'] ?? $payload['value'] ?? null;

            if (is_array($chunk)) {
                foreach ($chunk as $item) {
                    if (is_array($item)) {
                        $items[] = $item;
                    }
                }
            }

            $cursorRaw = $payload['cursor'] ?? $payload['next_cursor'] ?? null;
            $cursor = is_string($cursorRaw) && $cursorRaw !== '' ? $cursorRaw : null;
        } while ($cursor !== null);

        return $items;
    }

    public function apiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function sendGet(string $url, array $query, string $relativePath, bool $retryOnAuth = true): Response
    {
        try {
            $client = $this->apiHttpClient();

            if ($retryOnAuth) {
                $client = $client->retry($this->retryTimes(), $this->retrySleepMilliseconds());
            }

            return $client->withToken((string) $this->token)->get($url, $query);
        } catch (ConnectionException $exception) {
            $this->logHttpFailure('Action1 GET connection failed.', $relativePath, $exception);

            throw new RuntimeException('Action1 GET '.$relativePath.' connection failed: '.$exception->getMessage(), 0, $exception);
        } catch (RequestException $exception) {
            $this->logHttpFailure('Action1 GET request failed.', $relativePath, $exception);

            throw new RuntimeException('Action1 GET '.$relativePath.' request failed: '.$exception->getMessage(), 0, $exception);
        }
    }

    private function authHttpClient(): PendingRequest
    {
        return Http::asForm()
            ->connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->authTimeoutSeconds());
    }

    private function apiHttpClient(): PendingRequest
    {
        return Http::connectTimeout($this->connectTimeoutSeconds())
            ->timeout($this->requestTimeoutSeconds());
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('helixdesk_integrations.action1.http.connect_timeout_seconds', 10));
    }

    private function authTimeoutSeconds(): int
    {
        return max(5, (int) config('helixdesk_integrations.action1.http.auth_timeout_seconds', 30));
    }

    private function requestTimeoutSeconds(): int
    {
        return max(5, (int) config('helixdesk_integrations.action1.http.request_timeout_seconds', 120));
    }

    private function retryTimes(): int
    {
        return max(0, (int) config('helixdesk_integrations.action1.http.retry_times', 2));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('helixdesk_integrations.action1.http.retry_sleep_milliseconds', 500));
    }

    private function logHttpFailure(string $message, string $relativePath, Throwable $exception): void
    {
        Log::error($message, [
            'relative_path' => $relativePath,
            'api_base_url' => $this->apiBaseUrl,
            'exception_class' => $exception::class,
            'exception' => $exception->getMessage(),
        ]);
    }

    private static function normalizeBaseUrl(string $apiBaseUrl): string
    {
        $normalized = rtrim(trim($apiBaseUrl), '/').'/';

        return $normalized;
    }
}

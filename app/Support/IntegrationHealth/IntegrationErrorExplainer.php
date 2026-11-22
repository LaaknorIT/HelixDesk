<?php

namespace App\Support\IntegrationHealth;

/**
 * Turns raw provider error messages (HTTP status codes, AADSTS codes, ...) into a short, actionable hint.
 */
final class IntegrationErrorExplainer
{
    public static function hint(string $rawError): ?string
    {
        $patterns = [
            '/AADSTS7000222/' => __('The client secret has expired. Create a new secret in the app registration and paste it into the integration.'),
            '/AADSTS7000215/' => __('The client secret is not valid. Make sure you copied the secret value, not the secret ID.'),
            '/AADSTS700016/' => __('The application (client) ID was not found in this directory. Check the client ID and the directory (tenant) ID.'),
            '/AADSTS90002|AADSTS900023/' => __('The directory (tenant) ID was not found. Check the value from the Entra overview page.'),
            '/AADSTS65001|AADSTS500011/' => __('Admin consent is missing. Grant admin consent for the API permissions in the app registration.'),
            '/Resource not found for the segment/i' => __('The API endpoint was not recognised by Microsoft Graph. This is usually fixed by updating HelixDesk.'),
            '/(HTTP|status code) 401/' => __('The credentials were rejected. Check the client ID and secret, and whether the secret has expired.'),
            '/(HTTP|status code) 403|Authorization_RequestDenied|ErrorAccessDenied|Forbidden/i' => __('Access was denied. The app is missing an API permission or admin consent for one of the enabled capabilities.'),
            '/(HTTP|status code) 404/' => __('A resource was not found. Check organization, group or tenant identifiers.'),
            '/(HTTP|status code) 429/' => __('The provider rate-limited HelixDesk. The next scheduled sync will retry automatically.'),
            '/(HTTP|status code) 5\d\d/' => __('The provider returned a server error. This is often temporary; the next scheduled sync will retry.'),
            '/connection failed|cURL error|Could not resolve host|timed out/i' => __('HelixDesk could not reach the provider. Check network access and the API base URL.'),
        ];

        foreach ($patterns as $pattern => $hint) {
            if (preg_match($pattern, $rawError) === 1) {
                return $hint;
            }
        }

        return null;
    }

    /**
     * A one-line, readable summary of a raw error, without long response bodies.
     */
    public static function summary(string $rawError, int $limit = 160): string
    {
        $firstLine = trim(strtok($rawError, "\n") ?: $rawError);
        $withoutBody = preg_replace('/\s*\{.*$/s', '', $firstLine) ?? $firstLine;

        return mb_strimwidth($withoutBody !== '' ? $withoutBody : $firstLine, 0, $limit, '…');
    }
}

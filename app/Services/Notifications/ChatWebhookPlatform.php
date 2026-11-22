<?php

namespace App\Services\Notifications;

use App\Enums\ChatPlatform;

/**
 * Builds webhook payloads and checks webhook addresses for Microsoft Teams and Slack.
 */
final class ChatWebhookPlatform
{
    /**
     * Whether HelixDesk may post to this webhook URL. Only HTTPS URLs on the platform's own hosts are allowed,
     * so a webhook setting cannot be used to make the server call internal addresses.
     */
    public static function allowsUrl(ChatPlatform $platform, ?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (config('helixdesk.notifications.webhook_hosts.'.$platform->value, []) as $allowedHost) {
            $allowedHost = strtolower((string) $allowedHost);

            if (str_starts_with($allowedHost, '.') ? str_ends_with($host, $allowedHost) : $host === $allowedHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(ChatPlatform $platform, WorkItemNotificationContent $content): array
    {
        return match ($platform) {
            ChatPlatform::Slack => self::slackPayload($content),
            ChatPlatform::Teams => self::teamsPayload($content),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function slackPayload(WorkItemNotificationContent $content): array
    {
        $lines = ['*'.self::slackEscape($content->heading).'*: <'.$content->url.'|'.self::slackEscape($content->ticketLabel).'>'];

        if ($content->detail !== null) {
            $lines[] = '>'.str_replace("\n", "\n>", self::slackEscape($content->detail));
        }

        $facts = [];
        foreach ($content->facts as $label => $value) {
            $facts[] = $label.': '.self::slackEscape($value);
        }

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => implode("\n", $lines)]],
        ];

        if ($facts !== []) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => implode(' · ', $facts)]]];
        }

        return [
            'text' => $content->subject(),
            'blocks' => $blocks,
        ];
    }

    /**
     * Adaptive Card wrapped for the Teams "post to a channel when a webhook request is received" workflow.
     *
     * @return array<string, mixed>
     */
    private static function teamsPayload(WorkItemNotificationContent $content): array
    {
        $body = [
            ['type' => 'TextBlock', 'text' => $content->heading, 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true],
            ['type' => 'TextBlock', 'text' => $content->ticketLabel, 'wrap' => true],
        ];

        if ($content->detail !== null) {
            $body[] = ['type' => 'TextBlock', 'text' => $content->detail, 'wrap' => true, 'isSubtle' => true];
        }

        if ($content->facts !== []) {
            $facts = [];
            foreach ($content->facts as $label => $value) {
                $facts[] = ['title' => $label, 'value' => $value];
            }

            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $body,
                    'actions' => [
                        ['type' => 'Action.OpenUrl', 'title' => 'Open ticket', 'url' => $content->url],
                    ],
                ],
            ]],
        ];
    }

    private static function slackEscape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}

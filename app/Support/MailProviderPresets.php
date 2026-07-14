<?php

namespace App\Support;

final class MailProviderPresets
{
    public const Gmail = 'gmail';

    public const Generic = 'generic';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Gmail => 'Gmail / Google Workspace',
            self::Generic => 'Other (manual)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionDefaults(string $preset): array
    {
        return match ($preset) {
            self::Gmail => [
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'smtp_host' => 'smtp.gmail.com',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
            ],
            default => [],
        };
    }

    public static function gmailPasswordHelperText(): string
    {
        return 'Gmail does not accept your normal Google account password for IMAP/SMTP. '
            .'Enable IMAP in Gmail settings, turn on 2-Step Verification, then create an App Password '
            .'at myaccount.google.com/apppasswords and paste the 16-character value here (not your login password).';
    }
}

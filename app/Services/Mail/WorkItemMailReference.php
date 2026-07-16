<?php

namespace App\Services\Mail;

use Illuminate\Support\Str;

final class WorkItemMailReference
{
    public static function subjectToken(string $publicId): string
    {
        return '[#'.$publicId.']';
    }

    public static function appendSubjectToken(string $subject, string $publicId): string
    {
        $token = self::subjectToken($publicId);
        if (str_contains($subject, $token) || self::extractSubjectToken($subject) !== null) {
            return $subject;
        }

        return trim($subject).' '.$token;
    }

    public static function extractSubjectToken(string $subject): ?string
    {
        if (preg_match('/\[#([A-Za-z0-9]+)\]/', $subject, $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token !== '' ? $token : null;
    }

    public static function plusAddress(string $mailboxEmail, string $publicId): ?string
    {
        $mailboxEmail = Str::lower(trim($mailboxEmail));
        if ($mailboxEmail === '' || ! str_contains($mailboxEmail, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $mailboxEmail, 2);
        $local = explode('+', $local, 2)[0];
        if ($local === '' || $domain === '') {
            return null;
        }

        return $local.'+'.$publicId.'@'.$domain;
    }

    /**
     * @param  list<string>  $addresses
     */
    public static function extractPlusTokenFromAddresses(array $addresses, string $mailboxEmail): ?string
    {
        $mailboxEmail = Str::lower(trim($mailboxEmail));
        if ($mailboxEmail === '' || ! str_contains($mailboxEmail, '@')) {
            return null;
        }

        [$mailboxLocal, $mailboxDomain] = explode('@', $mailboxEmail, 2);
        $mailboxLocal = explode('+', $mailboxLocal, 2)[0];

        foreach ($addresses as $address) {
            $normalized = Str::lower(trim($address));
            if ($normalized === '' || ! str_contains($normalized, '@')) {
                continue;
            }

            [$local, $domain] = explode('@', $normalized, 2);
            if ($domain !== $mailboxDomain || ! str_contains($local, '+')) {
                continue;
            }

            [$baseLocal, $plusToken] = explode('+', $local, 2);
            if ($baseLocal !== $mailboxLocal) {
                continue;
            }

            $plusToken = trim($plusToken);
            if ($plusToken !== '') {
                return $plusToken;
            }
        }

        return null;
    }
}

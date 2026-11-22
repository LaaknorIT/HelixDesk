<?php

namespace App\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Draws initials avatars locally, so no user names are sent to a third-party avatar service and avatars
 * also work on servers without internet access.
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(fn (string $segment): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment), 0, 1))
            ->filter()
            ->take(2)
            ->join('');

        $initials = mb_strtoupper($initials !== '' ? $initials : '?');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#312e81"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="600">'
            .htmlspecialchars($initials, ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

<?php

namespace App\Enums;

enum ChatPlatform: string
{
    case Teams = 'teams';
    case Slack = 'slack';

    public function label(): string
    {
        return match ($this) {
            self::Teams => 'Microsoft Teams',
            self::Slack => 'Slack',
        };
    }

    public function webhookAttribute(): string
    {
        return $this->value.'_webhook_url';
    }
}

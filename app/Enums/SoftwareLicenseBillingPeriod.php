<?php

namespace App\Enums;

enum SoftwareLicenseBillingPeriod: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Annual => __('Annual'),
            self::Other => __('Other'),
        };
    }
}

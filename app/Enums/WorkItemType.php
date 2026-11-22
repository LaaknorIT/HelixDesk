<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkItemType: string implements HasColor, HasLabel
{
    case Incident = 'incident';
    case ServiceRequest = 'service_request';
    case Task = 'task';
    case Defect = 'defect';
    case FeatureRequest = 'feature_request';

    public function getLabel(): string
    {
        return match ($this) {
            self::Incident => __('Incident'),
            self::ServiceRequest => __('Service request'),
            self::Task => __('Task'),
            self::Defect => __('Defect'),
            self::FeatureRequest => __('Feature request'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Incident => 'danger',
            self::ServiceRequest => 'info',
            self::Task => 'gray',
            self::Defect => 'warning',
            self::FeatureRequest => 'success',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}

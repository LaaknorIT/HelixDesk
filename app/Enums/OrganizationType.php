<?php

namespace App\Enums;

enum OrganizationType: string
{
    case Group = 'group';
    case Customer = 'customer';
    case Department = 'department';

    public function label(): string
    {
        return match ($this) {
            self::Group => __('Customer group'),
            self::Customer => __('Customer'),
            self::Department => __('Department'),
        };
    }

    /**
     * @return list<self>
     */
    public static function allowedForParent(?self $parentType): array
    {
        if ($parentType === null) {
            return [self::Group, self::Customer];
        }

        return match ($parentType) {
            self::Group => [self::Group, self::Customer],
            self::Customer => [self::Customer, self::Department],
            self::Department => [self::Department],
        };
    }

    public function acceptsServiceOfferings(): bool
    {
        return $this === self::Customer || $this === self::Group;
    }
}

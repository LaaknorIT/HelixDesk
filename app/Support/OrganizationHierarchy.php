<?php

namespace App\Support;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

final class OrganizationHierarchy
{
    public static function assertValidParent(Organization $organization): void
    {
        $parentId = $organization->parent_id;

        if ($parentId === null || $parentId === Organization::defaultParentKey()) {
            return;
        }

        if ((int) $parentId === (int) $organization->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => __('An organization cannot be its own parent.'),
            ]);
        }

        /** @var Organization|null $parent */
        $parent = Organization::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => __('The selected parent organization does not exist.'),
            ]);
        }

        if ((int) $parent->tenant_id !== (int) $organization->tenant_id) {
            throw ValidationException::withMessages([
                'parent_id' => __('The parent organization must belong to the same tenant.'),
            ]);
        }

        $childType = $organization->type instanceof OrganizationType
            ? $organization->type
            : OrganizationType::from((string) $organization->type);

        $allowed = OrganizationType::allowedForParent($parent->type);

        if (! in_array($childType, $allowed, true)) {
            throw ValidationException::withMessages([
                'type' => __('A :child type cannot be placed under a :parent.', [
                    'child' => $childType->label(),
                    'parent' => $parent->type->label(),
                ]),
            ]);
        }

        if ($organization->exists) {
            self::assertNotDescendant($organization, $parent);
        }
    }

    private static function assertNotDescendant(Organization $organization, Organization $candidateParent): void
    {
        $current = $candidateParent;

        while (! $current->isRoot()) {
            if ((int) $current->getKey() === (int) $organization->getKey()) {
                throw ValidationException::withMessages([
                    'parent_id' => __('An organization cannot be moved under one of its descendants.'),
                ]);
            }

            $parentKey = $current->parent_id;

            if ($parentKey === null || $parentKey === Organization::defaultParentKey()) {
                break;
            }

            $current = Organization::query()->find($parentKey);

            if ($current === null) {
                break;
            }
        }
    }
}

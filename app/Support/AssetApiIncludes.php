<?php

namespace App\Support;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class AssetApiIncludes
{
    /**
     * @var list<string>
     */
    public const ALLOWED = [
        'external_identifiers',
        'software_installations',
        'patch_status',
        'patch_items',
        'provenance',
    ];

    /**
     * @return list<string>
     */
    public static function parse(?string $include): array
    {
        if ($include === null || trim($include) === '') {
            return [];
        }

        $requested = array_map('trim', explode(',', $include));
        $allowed = array_flip(self::ALLOWED);

        return array_values(array_filter(
            $requested,
            fn (string $key): bool => isset($allowed[$key]),
        ));
    }

    /**
     * @param  Asset|EloquentCollection<int, Asset>|Collection<int, Asset>  $assets
     * @param  list<string>  $includes
     */
    public static function apply(Asset|EloquentCollection|Collection $assets, array $includes): void
    {
        if ($includes === []) {
            return;
        }

        $relations = [];

        if (in_array('external_identifiers', $includes, true)) {
            $relations[] = 'externalIdentifiers';
        }

        if (in_array('software_installations', $includes, true)) {
            $relations[] = 'softwareInstallations';
        }

        if (in_array('patch_status', $includes, true)) {
            $relations[] = 'patchStatuses';
        }

        if (in_array('patch_items', $includes, true)) {
            $relations[] = 'patchItems';
        }

        if (in_array('provenance', $includes, true)) {
            $relations[] = 'attributeProvenance';
        }

        if ($relations === []) {
            return;
        }

        if ($assets instanceof Asset) {
            $assets->loadMissing($relations);

            return;
        }

        $assets->loadMissing($relations);
    }
}

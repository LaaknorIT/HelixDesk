<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers "which organizations are above or below this one" for one tenant, from a single read of the tree.
 *
 * The tenant itself is the root: a null organization ID means "the root level", which is an ancestor of every
 * organization. An instance caches the tree, so create one per sync run or request, not per long-lived worker.
 */
final class OrganizationLineage
{
    /** @var array<int, array<int, ?int>> tenant ID => organization ID => parent ID (null at the top) */
    private array $parentsByTenant = [];

    /**
     * Ancestors of the organization, nearest first. The root level (null) is not listed.
     *
     * @return list<int>
     */
    public function ancestorIds(int $tenantId, ?int $organizationId): array
    {
        if ($organizationId === null) {
            return [];
        }

        $parents = $this->parents($tenantId);
        $ancestors = [];
        $current = $parents[$organizationId] ?? null;

        while ($current !== null && ! in_array($current, $ancestors, true) && $current !== $organizationId) {
            $ancestors[] = $current;
            $current = $parents[$current] ?? null;
        }

        return $ancestors;
    }

    /**
     * Every organization below the organization, at any depth. For the root level, every organization.
     *
     * @return list<int>
     */
    public function descendantIds(int $tenantId, ?int $organizationId): array
    {
        $parents = $this->parents($tenantId);

        if ($organizationId === null) {
            return array_keys($parents);
        }

        $children = [];
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $descendants = [];
        $queue = $children[$organizationId] ?? [];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === $organizationId || in_array($id, $descendants, true)) {
                continue;
            }

            $descendants[] = $id;
            array_push($queue, ...($children[$id] ?? []));
        }

        return $descendants;
    }

    /**
     * True when $candidate sits strictly above $organizationId. The root level (null) is above every organization.
     */
    public function isStrictAncestor(int $tenantId, ?int $candidate, ?int $organizationId): bool
    {
        if ($organizationId === null) {
            return false;
        }

        if ($candidate === null) {
            return true;
        }

        return in_array($candidate, $this->ancestorIds($tenantId, $organizationId), true);
    }

    /**
     * True when $candidate is the organization itself or anywhere below it.
     */
    public function isSameOrBelow(int $tenantId, ?int $candidate, ?int $organizationId): bool
    {
        if ($organizationId === null) {
            return true;
        }

        if ($candidate === null) {
            return false;
        }

        return $candidate === $organizationId || in_array($candidate, $this->descendantIds($tenantId, $organizationId), true);
    }

    /**
     * The organizations an integration at $organizationId can see devices in: the root level, its ancestors, itself
     * and everything below it. Sibling branches (other customers) are left out, so one customer's integration never
     * claims a device that lives under another customer.
     *
     * @return list<int>|null null when the scope is the whole tenant (an integration at the root level)
     */
    public function scopeIds(int $tenantId, ?int $organizationId): ?array
    {
        if ($organizationId === null) {
            return null;
        }

        return [
            ...$this->ancestorIds($tenantId, $organizationId),
            $organizationId,
            ...$this->descendantIds($tenantId, $organizationId),
        ];
    }

    /**
     * Limits a query to rows whose organization is in the lineage scope of $organizationId (see scopeIds()).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(Builder $query, int $tenantId, ?int $organizationId, string $column = 'organization_id'): Builder
    {
        $ids = $this->scopeIds($tenantId, $organizationId);

        if ($ids === null) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner->whereNull($column)->orWhereIn($column, $ids));
    }

    /**
     * Number of steps from the root level: 0 for the root level, 1 for a top-level organization, and so on.
     */
    public function depth(int $tenantId, ?int $organizationId): int
    {
        return $organizationId === null ? 0 : count($this->ancestorIds($tenantId, $organizationId)) + 1;
    }

    /**
     * @return array<int, ?int>
     */
    private function parents(int $tenantId): array
    {
        if (! isset($this->parentsByTenant[$tenantId])) {
            $rootKey = Organization::defaultParentKey();

            $this->parentsByTenant[$tenantId] = Organization::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->get(['id', 'parent_id'])
                ->mapWithKeys(function (Organization $organization) use ($rootKey): array {
                    $parentId = $organization->parent_id;

                    return [(int) $organization->getKey() => ($parentId === null || (int) $parentId === (int) $rootKey || (int) $parentId <= 0) ? null : (int) $parentId];
                })
                ->all();
        }

        return $this->parentsByTenant[$tenantId];
    }
}

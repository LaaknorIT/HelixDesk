<?php

namespace App\Services;

use App\Models\SlaPolicy;
use App\Models\WorkItem;

class SlaPolicyResolver
{
    public function attachBestPolicy(WorkItem $workItem): ?SlaPolicy
    {
        $policy = $this->resolveForWorkItem($workItem);

        $workItem->sla_policy_id = $policy?->getKey();
        $workItem->save();

        return $policy;
    }

    public function resolveForWorkItem(WorkItem $workItem): ?SlaPolicy
    {
        $policies = SlaPolicy::query()
            ->where('tenant_id', $workItem->tenant_id)
            ->where('is_active', true)
            ->get();

        $typeValue = $workItem->type instanceof \BackedEnum
            ? $workItem->type->value
            : (string) ($workItem->type ?? '');

        $best = null;
        $bestScore = -1;
        $bestMatchPriority = PHP_INT_MIN;

        foreach ($policies as $policy) {
            $dimensions = 0;
            $matched = true;

            if ($policy->applies_to_priority !== null) {
                $dimensions++;
                if ((string) $workItem->priority !== (string) $policy->applies_to_priority) {
                    $matched = false;
                }
            }

            if ($policy->applies_to_organization_id !== null) {
                $dimensions++;
                if ((int) $workItem->organization_id !== (int) $policy->applies_to_organization_id) {
                    $matched = false;
                }
            }

            if ($policy->applies_to_category_id !== null) {
                $dimensions++;
                if ((int) $workItem->category_id !== (int) $policy->applies_to_category_id) {
                    $matched = false;
                }
            }

            if ($policy->applies_to_work_item_type !== null) {
                $dimensions++;
                if ($typeValue !== (string) $policy->applies_to_work_item_type) {
                    $matched = false;
                }
            }

            if (! $matched) {
                continue;
            }

            $matchPriority = (int) $policy->match_priority;

            if (
                $dimensions > $bestScore
                || ($dimensions === $bestScore && $matchPriority > $bestMatchPriority)
                || ($dimensions === $bestScore && $matchPriority === $bestMatchPriority && ($best === null || $policy->getKey() < $best->getKey()))
            ) {
                $best = $policy;
                $bestScore = $dimensions;
                $bestMatchPriority = $matchPriority;
            }
        }

        return $best;
    }
}

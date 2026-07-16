<?php

namespace App\Services\Mail;

use App\Models\WorkItem;
use RuntimeException;

class WorkItemPublicIdGenerator
{
    /**
     * Lowercase YouTube-like alphabet (no ambiguous 0/o/1/l). Lowercase-only so
     * plus-address tokens survive email address lowercasing.
     */
    public const DEFAULT_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    /**
     * @param  list<string>  $reservedInTenant  Optional in-memory IDs already claimed (e.g. during backfill).
     */
    public function generate(int $tenantId, array $reservedInTenant = []): string
    {
        $length = max(3, (int) config('helixdesk.mail.work_item_public_id_length', 5));
        $alphabet = (string) config('helixdesk.mail.work_item_public_id_alphabet', self::DEFAULT_ALPHABET);

        if ($alphabet === '') {
            $alphabet = self::DEFAULT_ALPHABET;
        }

        $alphabetLength = strlen($alphabet);
        $reserved = array_fill_keys($reservedInTenant, true);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $candidate = '';
            for ($i = 0; $i < $length; $i++) {
                $candidate .= $alphabet[random_int(0, $alphabetLength - 1)];
            }

            if (isset($reserved[$candidate])) {
                continue;
            }

            $exists = WorkItem::query()
                ->where('tenant_id', $tenantId)
                ->where('public_id', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique work item public_id for tenant '.$tenantId);
    }

    public function ensure(WorkItem $workItem): string
    {
        if (is_string($workItem->public_id) && trim($workItem->public_id) !== '') {
            return $workItem->public_id;
        }

        $publicId = $this->generate((int) $workItem->tenant_id);
        $workItem->public_id = $publicId;
        $workItem->save();

        return $publicId;
    }
}

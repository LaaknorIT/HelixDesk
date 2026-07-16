<?php

namespace App\Enums;

enum InboundMailIngestOutcome: string
{
    case Ingested = 'ingested';
    case Duplicate = 'duplicate';
    case SkippedEmptyFrom = 'skipped_empty_from';
    case SkippedNoQueue = 'skipped_no_queue';

    public function shouldAcknowledge(): bool
    {
        return match ($this) {
            self::Ingested, self::Duplicate, self::SkippedEmptyFrom => true,
            self::SkippedNoQueue => false,
        };
    }
}

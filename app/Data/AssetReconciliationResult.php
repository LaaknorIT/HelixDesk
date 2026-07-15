<?php

namespace App\Data;

use App\Models\Asset;
use App\Models\AssetIdentityAmbiguity;

final class AssetReconciliationResult
{
    public function __construct(
        public ?Asset $asset,
        public bool $created = false,
        public bool $matched = false,
        public bool $ambiguous = false,
        public ?AssetIdentityAmbiguity $ambiguity = null,
    ) {}
}

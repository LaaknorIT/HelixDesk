<?php

namespace App\Enums;

enum AssetIdentityResolutionStatus: string
{
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case Manual = 'manual';
    case Unlinked = 'unlinked';
}

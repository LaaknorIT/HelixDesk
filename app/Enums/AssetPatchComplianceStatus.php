<?php

namespace App\Enums;

enum AssetPatchComplianceStatus: string
{
    case Compliant = 'compliant';
    case CriticalMissing = 'critical_missing';
    case UpdatesMissing = 'updates_missing';
    case Unknown = 'unknown';
}

<?php

namespace App\Enums;

enum AssetIdentityAmbiguityStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}

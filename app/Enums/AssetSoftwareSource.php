<?php

namespace App\Enums;

enum AssetSoftwareSource: string
{
    case Discovered = 'discovered';
    case Deployed = 'deployed';
}

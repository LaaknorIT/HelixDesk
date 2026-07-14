<?php

namespace App\Enums;

enum WorkItemSource: string
{
    case Portal = 'portal';
    case Email = 'email';
    case Api = 'api';
    case Monitoring = 'monitoring';
}

<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Portal = 'portal';
    case Email = 'email';
    case Api = 'api';
    case Monitoring = 'monitoring';
}

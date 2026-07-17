<?php

namespace App\Enums;

enum SlaTargetType: string
{
    case FirstResponse = 'first_response';
    case Resolution = 'resolution';
    case Both = 'both';
}

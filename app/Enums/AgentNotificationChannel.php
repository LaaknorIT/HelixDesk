<?php

namespace App\Enums;

enum AgentNotificationChannel: string
{
    case Mail = 'mail';
    case Database = 'database';
}

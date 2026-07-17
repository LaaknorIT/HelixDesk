<?php

namespace App\Enums;

enum AutomationTrigger: string
{
    case CustomerReplied = 'customer_replied';
    case AgentReplied = 'agent_replied';
    case StatusChanged = 'status_changed';
    case Created = 'created';
    case SlaBreached = 'sla_breached';
}

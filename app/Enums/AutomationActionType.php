<?php

namespace App\Enums;

enum AutomationActionType: string
{
    case SetStatus = 'set_status';
    case CreateWorkItem = 'create_work_item';
    case SendCustomerEmail = 'send_customer_email';
}

<?php

namespace App\Enums;

enum WorkItemType: string
{
    case Incident = 'incident';
    case ServiceRequest = 'service_request';
    case Task = 'task';
    case Defect = 'defect';
    case FeatureRequest = 'feature_request';
}

<?php

namespace App\Enums;

enum MailOutboundProtocol: string
{
    case Smtp = 'smtp';
    case MicrosoftGraph = 'microsoft_graph';

    case GoogleGmailApi = 'google_gmail_api';
}

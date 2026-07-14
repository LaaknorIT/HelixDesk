<?php

namespace App\Enums;

enum MailInboundProtocol: string
{
    case Imap = 'imap';
    case Pop3 = 'pop3';
    case MicrosoftGraph = 'microsoft_graph';

    case GoogleGmailApi = 'google_gmail_api';
}

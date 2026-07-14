<?php

namespace App\Services\Mail;

final class ParsedInboundEmail
{
    /**
     * @param  list<string>  $toAddresses
     * @param  list<array{filename: string, mime_type: string|null, content: string}>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly ?string $references,
        public readonly string $fromAddress,
        public readonly ?string $fromName,
        public readonly array $toAddresses,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly array $attachments,
        public readonly ?string $transportUid = null,
    ) {}
}

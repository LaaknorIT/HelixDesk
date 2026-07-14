<?php

namespace App\Services\Mail;

use Illuminate\Support\Str;
use ZBateson\MailMimeParser\MailMimeParser;

final class InboundMimeParser
{
    public function __construct(private readonly MailMimeParser $parser = new MailMimeParser) {}

    public function parse(string $rawMime, ?string $transportUid = null): ParsedInboundEmail
    {
        $message = $this->parser->parse($rawMime, false);

        $messageId = $this->normalizeMessageId($message->getHeaderValue('Message-ID') ?? '');
        if ($messageId === '') {
            $messageId = '<generated-'.md5($rawMime).'@helixdesk.local>';
        }

        $from = $message->getHeader('From');
        $fromAddress = '';
        $fromName = null;
        if ($from !== null) {
            $fromAddress = Str::lower(trim((string) ($from->getEmail() ?? '')));
            $fromName = $from->getName();
        }

        $toAddresses = [];
        foreach ($message->getHeader('To')?->getAddresses() ?? [] as $address) {
            $email = Str::lower(trim((string) ($address->getEmail() ?? '')));
            if ($email !== '') {
                $toAddresses[] = $email;
            }
        }

        $bodyText = trim($message->getTextContent() ?? '');
        if ($bodyText === '') {
            $bodyText = trim(strip_tags($message->getHtmlContent() ?? ''));
        }

        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $part) {
            $filename = $part->getFilename() ?? 'attachment';
            $content = $part->getContent();
            if (! is_string($content) || $content === '') {
                continue;
            }

            $attachments[] = [
                'filename' => $filename,
                'mime_type' => $part->getContentType(),
                'content' => $content,
            ];
        }

        return new ParsedInboundEmail(
            messageId: $messageId,
            inReplyTo: $this->normalizeMessageIdOptional($message->getHeaderValue('In-Reply-To')),
            references: $this->normalizeReferences($message->getHeaderValue('References')),
            fromAddress: $fromAddress,
            fromName: $fromName,
            toAddresses: $toAddresses,
            subject: trim($message->getHeaderValue('Subject') ?? '(no subject)'),
            bodyText: $bodyText,
            attachments: $attachments,
            transportUid: $transportUid,
        );
    }

    private function normalizeMessageId(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (! str_starts_with($trimmed, '<')) {
            $trimmed = '<'.$trimmed.'>';
        }

        return $trimmed;
    }

    private function normalizeMessageIdOptional(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->normalizeMessageId($value);
    }

    private function normalizeReferences(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}

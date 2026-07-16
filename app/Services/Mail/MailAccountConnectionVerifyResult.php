<?php

namespace App\Services\Mail;

final readonly class MailAccountConnectionVerifyResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $ok,
        public string $message,
        public array $details = [],
    ) {}
}

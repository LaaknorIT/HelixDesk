<?php

namespace App\Services\Mail;

final readonly class MailAccountPollResult
{
    public function __construct(
        public int $fetched = 0,
        public int $ingested = 0,
        public int $skipped = 0,
        public int $duplicates = 0,
        public ?string $error = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    public function summary(): string
    {
        if ($this->error !== null) {
            return $this->error;
        }

        return sprintf(
            'Fetched %d message(s): %d ingested, %d duplicate(s), %d skipped.',
            $this->fetched,
            $this->ingested,
            $this->duplicates,
            $this->skipped,
        );
    }
}

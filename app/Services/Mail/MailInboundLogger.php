<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

final class MailInboundLogger
{
    public function logger(): LoggerInterface
    {
        return Log::channel('mail');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function debug(MailAccount $account, string $message, array $context = []): void
    {
        $this->logger()->debug($message, $this->withAccount($account, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(MailAccount $account, string $message, array $context = []): void
    {
        $this->logger()->info($message, $this->withAccount($account, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(MailAccount $account, string $message, array $context = []): void
    {
        $this->logger()->warning($message, $this->withAccount($account, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(MailAccount $account, string $message, array $context = []): void
    {
        $this->logger()->error($message, $this->withAccount($account, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function exception(MailAccount $account, string $message, Throwable $e, array $context = []): void
    {
        $this->logger()->error($message, $this->withAccount($account, [
            ...$context,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withAccount(MailAccount $account, array $context): array
    {
        return [
            'mail_account_id' => $account->getKey(),
            'tenant_id' => $account->tenant_id,
            'email_address' => $account->email_address,
            'protocol' => $account->protocol?->value,
            'outbound_protocol' => $account->outbound_protocol?->value,
            ...$context,
        ];
    }
}

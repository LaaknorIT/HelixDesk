<?php

namespace App\Filament\Agent\Resources\Queues\Schemas;

use App\Enums\ChatPlatform;
use App\Enums\WorkItemNotificationEvent;
use App\Models\MailAccount;
use App\Services\Notifications\ChatWebhookPlatform;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QueueForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->default(null)
                    ->rows(3)
                    ->columnSpanFull(),
                Section::make('Email')
                    ->schema([
                        CheckboxList::make('mail_account_ids')
                            ->label('Mail accounts')
                            ->options(function (): array {
                                $tenant = Filament::getTenant();
                                if ($tenant === null) {
                                    return [];
                                }

                                return MailAccount::query()
                                    ->where('tenant_id', $tenant->getKey())
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            })
                            ->columns(2)
                            ->live(),
                        Select::make('default_mail_account_id')
                            ->label('Default outbound address')
                            ->helperText('Used when agents reply on work_items not created from email (portal, API, etc.).')
                            ->options(fn (Get $get): array => MailAccount::query()
                                ->whereIn('id', $get('mail_account_ids') ?? [])
                                ->orderBy('name')
                                ->pluck('email_address', 'id')
                                ->all())
                            ->searchable()
                            ->nullable(),
                    ]),
                Section::make('Chat notifications')
                    ->description('Post ticket activity in this queue to a Microsoft Teams or Slack channel.')
                    ->schema([
                        self::webhookInput(ChatPlatform::Teams)
                            ->helperText('In Teams, add the "Post to a channel when a webhook request is received" workflow to the channel and paste its URL.'),
                        self::webhookInput(ChatPlatform::Slack)
                            ->helperText('Create an incoming webhook for the channel in your Slack app and paste its URL.'),
                        CheckboxList::make('chat_notification_events')
                            ->label('Post these events')
                            ->options(WorkItemNotificationEvent::options())
                            ->default(WorkItemNotificationEvent::defaultChatEvents())
                            ->columns(2),
                    ])
                    ->collapsible(),
            ]);
    }

    private static function webhookInput(ChatPlatform $platform): TextInput
    {
        return TextInput::make($platform->webhookAttribute())
            ->label($platform->label().' webhook URL')
            ->password()
            ->revealable()
            ->url()
            ->maxLength(2048)
            ->nullable()
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($platform): void {
                if (filled($value) && ! ChatWebhookPlatform::allowsUrl($platform, (string) $value)) {
                    $fail('This is not a '.$platform->label().' webhook URL.');
                }
            });
    }
}

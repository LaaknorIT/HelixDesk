<?php

namespace App\Notifications;

use App\Enums\AgentNotificationChannel;
use App\Enums\WorkItemNotificationEvent;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\Notifications\WorkItemNotificationContent;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkItemActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WorkItem $workItem,
        public WorkItemNotificationEvent $event,
        public ?string $detail = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = [];

        if (filled($notifiable->email) && $notifiable->wantsWorkItemNotification($this->event, AgentNotificationChannel::Mail)) {
            $channels[] = 'mail';
        }

        if ($notifiable->wantsWorkItemNotification($this->event, AgentNotificationChannel::Database)) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $content = $this->content();

        $mail = (new MailMessage)
            ->subject($content->subject())
            ->greeting($content->heading)
            ->line($content->ticketLabel);

        if ($content->detail !== null) {
            $mail->line($content->detail);
        }

        foreach ($content->facts as $label => $value) {
            $mail->line("{$label}: {$value}");
        }

        return $mail
            ->action('Open ticket', $content->url)
            ->salutation('You can change which notifications you get under Notification settings in HelixDesk.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $content = $this->content();

        return FilamentNotification::make()
            ->title($content->heading.': '.$content->ticketLabel)
            ->body($content->detail)
            ->icon($this->icon())
            ->actions([
                Action::make('open')
                    ->label('Open ticket')
                    ->url($content->url)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function content(): WorkItemNotificationContent
    {
        return WorkItemNotificationContent::for($this->workItem, $this->event, $this->detail);
    }

    private function icon(): string
    {
        return match ($this->event) {
            WorkItemNotificationEvent::Created => 'heroicon-o-inbox-arrow-down',
            WorkItemNotificationEvent::Assigned => 'heroicon-o-user-plus',
            WorkItemNotificationEvent::CustomerReplied => 'heroicon-o-chat-bubble-left-right',
            WorkItemNotificationEvent::SlaAtRisk => 'heroicon-o-clock',
        };
    }
}

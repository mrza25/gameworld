<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A notification in the user's in-app notification list.
 *
 * Every notification stores the same shape: `type`, a Persian `title` and
 * `message`, an optional frontend `link`, plus context fields such as
 * `order_id`. The content is built when the notification is created, because
 * queued models are re-fetched with their latest state when it is handled.
 */
abstract class UserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, mixed>
     */
    public array $content = [];

    /**
     * @param  array<string, mixed>  $context
     */
    protected function compose(string $type, string $title, string $message, ?string $link = null, array $context = []): void
    {
        $this->content = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
        ] + $context;

        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->content;
    }

    protected static function toman(float|int|string $amount): string
    {
        return number_format((float) $amount).' تومان';
    }
}

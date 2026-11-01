<?php

namespace App\Notifications;

use App\Models\Announcement;

class AdminAnnouncement extends UserNotification
{
    public function __construct(Announcement $announcement)
    {
        $this->compose(
            'announcement',
            $announcement->title,
            $announcement->message,
            $announcement->link,
            ['announcement_id' => $announcement->id]
        );
    }
}

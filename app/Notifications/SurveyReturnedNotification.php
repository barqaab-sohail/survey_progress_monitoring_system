<?php

namespace App\Notifications;

use App\Models\SurveyDailyEntryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SurveyReturnedNotification extends Notification
{
    use Queueable;

    public function __construct(public SurveyDailyEntryItem $item) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => "Survey for {$this->item->feeder->feeder_code} was returned for correction.", 'item_id' => $this->item->id, 'reason' => $this->item->return_reason];
    }
}

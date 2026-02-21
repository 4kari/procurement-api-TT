<?php

namespace App\Listeners;

use App\Events\RequestStatusChanged;
use App\Jobs\SendStatusNotificationJob;

class SendRequestStatusNotification
{
    public function handle(RequestStatusChanged $event): void
    {
        // Dispatch async job — non-blocking, retries 3x on failure
        SendStatusNotificationJob::dispatch(
            $event->request,
            $event->fromStatus,
            $event->toStatus,
            $event->actor,
            $event->reason,
        );
    }
}

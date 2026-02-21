<?php

namespace App\Providers;

use App\Events\RequestStatusChanged;
use App\Listeners\SendRequestStatusNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register event → listener binding
        Event::listen(
            RequestStatusChanged::class,
            SendRequestStatusNotification::class,
        );
    }
}

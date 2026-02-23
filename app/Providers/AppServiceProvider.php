<?php

namespace App\Providers;

use App\Events\RequestStatusChanged;
use App\Listeners\SendRequestStatusNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Models\Request as ProcurementRequest;
use App\Policies\RequestPolicy;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ── Policy ───────────────────────────────────────────────────────────
        Gate::policy(ProcurementRequest::class, RequestPolicy::class);

        // ── Events ───────────────────────────────────────────────────────────
        Event::listen(
            RequestStatusChanged::class,
            SendRequestStatusNotification::class,
        );
    }
}

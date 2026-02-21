<?php

namespace App\Events;

use App\Models\Request;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RequestStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Request $request,
        public readonly string  $fromStatus,
        public readonly string  $toStatus,
        public readonly User    $actor,
        public readonly ?string $reason = null,
    ) {}
}

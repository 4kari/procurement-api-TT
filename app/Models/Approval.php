<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasUuids;

    protected $keyType     = 'string';
    public    $incrementing = false;

    // Tidak ada updated_at — approval bersifat append-only
    const UPDATED_AT = null;
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'approver_id',
        'step',
        'action',
        'notes',
        'acted_at',
    ];

    protected $casts = [
        'step'       => 'integer',
        'acted_at'   => 'datetime',
        'created_at' => 'datetime',
    ];

    // ── Step constants ────────────────────────────────────────────────────────

    const STEP_VERIFY  = 1;  // Dikerjakan oleh PURCHASING
    const STEP_APPROVE = 2;  // Dikerjakan oleh PURCHASING_MANAGER

    // ── Action constants ──────────────────────────────────────────────────────

    const ACTION_VERIFY  = 'VERIFY';
    const ACTION_APPROVE = 'APPROVE';
    const ACTION_REJECT  = 'REJECT';

    // ── Relationships ─────────────────────────────────────────────────────────

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Request extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table      = 'requests';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'request_number',
        'requester_id',
        'department_id',
        'title',
        'status',
        'priority',
        'required_date',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'priority'      => 'integer',
        'version'       => 'integer',
        'required_date' => 'date',
        'submitted_at'  => 'datetime',
        'completed_at'  => 'datetime',
    ];

    // ── Status constants ──────────────────────────────────────────────────────

    const STATUS_DRAFT          = 'DRAFT';
    const STATUS_SUBMITTED      = 'SUBMITTED';
    const STATUS_VERIFIED       = 'VERIFIED';
    const STATUS_APPROVED       = 'APPROVED';
    const STATUS_REJECTED       = 'REJECTED';
    const STATUS_IN_PROCUREMENT = 'IN_PROCUREMENT';
    const STATUS_READY          = 'READY';
    const STATUS_COMPLETED      = 'COMPLETED';
    const STATUS_CANCELLED      = 'CANCELLED';

    const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REJECTED,
    ];

    /**
     * Peta transisi status yang valid.
     * Hanya perpindahan yang terdaftar di sini yang diizinkan oleh state machine.
     */
    const TRANSITIONS = [
        self::STATUS_DRAFT          => [self::STATUS_SUBMITTED,      self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED      => [self::STATUS_VERIFIED,       self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED       => [self::STATUS_APPROVED,       self::STATUS_REJECTED],
        self::STATUS_APPROVED       => [self::STATUS_IN_PROCUREMENT, self::STATUS_READY],
        self::STATUS_IN_PROCUREMENT => [self::STATUS_READY],
        self::STATUS_READY          => [self::STATUS_COMPLETED],
    ];

    // ── State machine helpers ─────────────────────────────────────────────────

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? [], strict: true);
    }

    public function isActive(): bool
    {
        return ! in_array($this->status, self::TERMINAL_STATUSES, strict: true);
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    /**
     * Employee hanya melihat request miliknya sendiri.
     * Role lain melihat semua.
     */
    public function scopeForUser($query, User $user): void
    {
        if ($user->isEmployee()) {
            $query->where('requester_id', $user->id);
        }
    }

    public function scopeByStatus($query, ?string $status): void
    {
        if ($status) {
            $query->where('status', strtoupper($status));
        }
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function procurementOrder(): HasOne
    {
        return $this->hasOne(ProcurementOrder::class);
    }

    /** Riwayat status — diurutkan terbaru di atas */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'entity_id')
                    ->where('entity_type', 'request')
                    ->latest('created_at');
    }
}

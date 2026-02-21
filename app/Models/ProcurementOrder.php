<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcurementOrder extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType     = 'string';
    public    $incrementing = false;

    // Hanya ada created_at, tidak ada updated_at — sesuai ERD
    const UPDATED_AT = null;
    public $timestamps = false;

    protected $fillable = [
        'po_number',
        'request_id',
        'vendor_id',
        'created_by',
        'status',
        'total_amount',
        'ordered_at',
        'expected_at',
        'received_at',
        // Tidak ada notes — sesuai ERD
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'ordered_at'   => 'datetime',
        'expected_at'  => 'datetime',
        'received_at'  => 'datetime',
        'created_at'   => 'datetime',
    ];

    // ── Status constants ──────────────────────────────────────────────────────

    const STATUS_PENDING            = 'PENDING';
    const STATUS_ORDERED            = 'ORDERED';
    const STATUS_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    const STATUS_COMPLETED          = 'COMPLETED';
    const STATUS_CANCELLED          = 'CANCELLED';

    // ── Relationships ─────────────────────────────────────────────────────────

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProcurementOrderItem::class, 'po_id');
    }
}

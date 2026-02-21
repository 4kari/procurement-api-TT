<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementOrderItem extends Model
{
    use HasUuids;

    protected $keyType     = 'string';
    public    $incrementing = false;
    public    $timestamps   = false;

    protected $fillable = [
        'po_id',
        'request_item_id',
        'item_name',
        'quantity',
        'unit_price',
        'received_qty',
        // total_price adalah GENERATED ALWAYS — tidak boleh di-set
    ];

    protected $casts = [
        'quantity'     => 'decimal:2',
        'unit_price'   => 'decimal:2',
        'total_price'  => 'decimal:2',  // read-only, diisi DB
        'received_qty' => 'decimal:2',
        'created_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function procurementOrder(): BelongsTo
    {
        return $this->belongsTo(ProcurementOrder::class, 'po_id');
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(RequestItem::class);
    }
}

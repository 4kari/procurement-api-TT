<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    use HasFactory, HasUuids;

    protected $keyType     = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'request_id',
        'stock_id',
        'item_name',
        'category',
        'quantity',
        'unit',
        'estimated_price',
        // Tidak ada kolom notes — sesuai ERD
    ];

    protected $casts = [
        'quantity'        => 'decimal:2',
        'estimated_price' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}

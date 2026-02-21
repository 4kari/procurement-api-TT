<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Stock extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table      = 'stock';
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit',
        'min_stock',
        // quantity dan reserved dikelola lewat fn_reserve_stock() — jangan di-set langsung
    ];

    protected $casts = [
        'quantity'  => 'decimal:2',
        'reserved'  => 'decimal:2',
        'min_stock' => 'decimal:2',
        'version'   => 'integer',
    ];

    // ── Computed helpers ──────────────────────────────────────────────────────

    /** Stok yang benar-benar tersedia = quantity - reserved */
    public function availableQty(): float
    {
        return max(0, (float) $this->quantity - (float) $this->reserved);
    }

    /** True jika stok sudah berada di atau di bawah batas minimum */
    public function isLow(): bool
    {
        return $this->quantity <= $this->min_stock;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function requestItems(): HasMany
    {
        return $this->hasMany(RequestItem::class);
    }
}

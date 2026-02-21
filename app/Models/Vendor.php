<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $keyType     = 'string';
    public    $incrementing = false;

    // Tidak ada created_at / updated_at sesuai ERD
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'contact_name',
        'contact_email',
        'npwp',
        'is_active',
        'rating',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'rating'    => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function procurementOrders(): HasMany
    {
        return $this->hasMany(ProcurementOrder::class);
    }
}

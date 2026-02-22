<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusHistory extends Model
{
    protected $table = 'status_history';
    use HasUuids;

    protected $keyType     = 'string';
    public    $incrementing = false;

    // IMMUTABLE — tidak ada updated_at, tidak ada soft delete
    public    $timestamps   = false;
    const UPDATED_AT        = null;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /** User yang melakukan perubahan status */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

<?php namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalAccessToken2 extends Model
{
    use HasUuids;
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'token_hash', 'name', 'abilities', 'expires_at'];
    protected $hidden   = ['token_hash'];
    protected $casts    = [
        'abilities'  => 'array',
        'last_used'  => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}

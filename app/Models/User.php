<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $keyType     = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'department_id',
        'name', 
        'employee_code',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'last_login_at' => 'datetime',
        'password'      => 'hashed',
    ];

    // ── Role constants ────────────────────────────────────────────────────────

    const ROLE_EMPLOYEE           = 'EMPLOYEE';
    const ROLE_PURCHASING         = 'PURCHASING';
    const ROLE_PURCHASING_MANAGER = 'PURCHASING_MANAGER';
    const ROLE_WAREHOUSE          = 'WAREHOUSE';
    const ROLE_ADMIN              = 'ADMIN';

    const ALL_ROLES = [
        self::ROLE_EMPLOYEE,
        self::ROLE_PURCHASING,
        self::ROLE_PURCHASING_MANAGER,
        self::ROLE_WAREHOUSE,
        self::ROLE_ADMIN,
    ];

    // ── Role helpers ──────────────────────────────────────────────────────────

    public function hasRole(string|array $roles): bool
    {
        return in_array($this->role, (array) $roles, strict: true);
    }

    public function isEmployee(): bool           { return $this->role === self::ROLE_EMPLOYEE; }
    public function isPurchasing(): bool         { return $this->role === self::ROLE_PURCHASING; }
    public function isPurchasingManager(): bool  { return $this->role === self::ROLE_PURCHASING_MANAGER; }
    public function isWarehouse(): bool          { return $this->role === self::ROLE_WAREHOUSE; }
    public function isAdmin(): bool              { return $this->role === self::ROLE_ADMIN; }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Request yang dibuat oleh user ini */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class, 'requester_id');
    }

    /** Approval yang ditugaskan ke user ini */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'approver_id');
    }

    /** Purchase Order yang dibuat oleh user ini */
    public function procurementOrders(): HasMany
    {
        return $this->hasMany(ProcurementOrder::class, 'created_by');
    }
}

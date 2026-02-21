<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── users ─────────────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('department_id')
                  ->nullable()
                  ->constrained('departments')
                  ->nullOnDelete();
            $table->string('employee_code', 20)->unique();
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();                   // created_at + updated_at
            $table->softDeletes();                  // deleted_at
        });

        // CHECK constraint untuk role — 5 nilai valid
        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT chk_users_role
            CHECK (role IN (
                'EMPLOYEE',
                'PURCHASING',
                'PURCHASING_MANAGER',
                'WAREHOUSE',
                'ADMIN'
            ))
        ");

        // ── Tutup circular FK: departments.manager_id → users.id ─────────────
        // Tidak bisa dibuat di migration departments karena tabel users belum ada
        DB::statement("
            ALTER TABLE departments
            ADD CONSTRAINT fk_departments_manager_id
            FOREIGN KEY (manager_id)
            REFERENCES users(id)
            ON DELETE SET NULL
        ");

        // ── Indexes ───────────────────────────────────────────────────────────
        DB::statement("
            CREATE INDEX idx_users_department_id
            ON users (department_id)
            WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_users_role
            ON users (role)
            WHERE deleted_at IS NULL
        ");

        // ── personal_access_tokens (Laravel Sanctum standard schema) ─────────
        // Sanctum menyimpan SHA-256 hash dari token di kolom `token`
        // Plaintext hanya dikembalikan sekali saat createToken() dipanggil
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();                            // BIGINT autoincrement — Sanctum default
            $table->morphs('tokenable');             // tokenable_type + tokenable_id (polymorphic → users)
            $table->string('name');
            $table->string('token', 64)->unique();   // SHA-256 hash (64 hex chars)
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        DB::statement("
            CREATE INDEX idx_pat_tokenable
            ON personal_access_tokens (tokenable_type, tokenable_id)
        ");
        DB::statement("
            CREATE INDEX idx_pat_expires_at
            ON personal_access_tokens (expires_at)
            WHERE expires_at IS NOT NULL
        ");

        DB::statement("COMMENT ON TABLE personal_access_tokens IS
            'Laravel Sanctum token table. SHA-256 hash tersimpan di kolom token.
             Plaintext token hanya dikirim ke client satu kali saat login.'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE departments DROP CONSTRAINT IF EXISTS fk_departments_manager_id');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
    }
};

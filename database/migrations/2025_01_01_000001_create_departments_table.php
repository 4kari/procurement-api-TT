<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── PostgreSQL Extension ──────────────────────────────────────────────
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // ── ENUM Types (dibuat sekali di migration pertama) ───────────────────
        DB::statement("CREATE TYPE request_status AS ENUM (
            'DRAFT', 'SUBMITTED', 'VERIFIED', 'APPROVED',
            'REJECTED', 'IN_PROCUREMENT', 'READY', 'COMPLETED', 'CANCELLED'
        )");

        DB::statement("CREATE TYPE approval_action AS ENUM (
            'VERIFY', 'APPROVE', 'REJECT'
        )");

        DB::statement("CREATE TYPE procurement_status AS ENUM (
            'PENDING', 'ORDERED', 'PARTIALLY_RECEIVED', 'COMPLETED', 'CANCELLED'
        )");

        DB::statement("CREATE TYPE item_category AS ENUM (
            'OFFICE_SUPPLY', 'ELECTRONIC', 'FURNITURE',
            'SERVICE', 'RAW_MATERIAL', 'OTHER'
        )");

        // ── departments ───────────────────────────────────────────────────────
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->uuid('manager_id')->nullable();   // FK ke users, ditutup setelah tabel users dibuat
            $table->boolean('is_active')->default(true);
            $table->timestamps();                     // created_at + updated_at
            $table->softDeletes();                    // deleted_at
        });

        DB::statement("COMMENT ON TABLE departments IS
            'Master departemen. Soft-delete agar relasi historis tetap valid.'");

        DB::statement("COMMENT ON COLUMN departments.manager_id IS
            'Circular FK ke users.id — ditambahkan setelah tabel users selesai dibuat.'");
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');

        foreach (['request_status', 'approval_action', 'procurement_status', 'item_category'] as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type}");
        }
    }
};

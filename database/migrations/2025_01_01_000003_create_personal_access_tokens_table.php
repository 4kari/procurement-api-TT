<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // SHA-256 hash of the actual token — plaintext never stored
            $table->string('token_hash', 64)->unique();
            $table->string('name', 100)->nullable();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement('CREATE INDEX idx_tokens_user   ON personal_access_tokens (user_id)');
        DB::statement('CREATE INDEX idx_tokens_expiry ON personal_access_tokens (expires_at) WHERE expires_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};

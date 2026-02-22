<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── requests ──────────────────────────────────────────────────────────
        Schema::create('requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('request_number', 30)->unique();   // Format: REQ-YYYY-XXXXXX
            $table->foreignUuid('requester_id')->constrained('users');
            $table->foreignUuid('department_id')->constrained('departments');
            $table->string('title', 200);
            $table->smallInteger('priority')->default(2);     // 1–5
            $table->date('required_date')->nullable();
            $table->integer('version')->default(1);           // Optimistic locking
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();                             // created_at + updated_at
            $table->softDeletes();                            // deleted_at
        });

        // ENUM status — tidak bisa pakai Blueprint karena custom PostgreSQL ENUM
        DB::statement("
            ALTER TABLE requests
            ADD COLUMN status request_status NOT NULL DEFAULT 'DRAFT'
        ");

        // CHECK constraints
        DB::statement("
            ALTER TABLE requests
            ADD CONSTRAINT chk_requests_priority
            CHECK (priority BETWEEN 1 AND 5)
        ");

        // Indexes
        DB::statement("
            CREATE INDEX idx_requests_requester_id
            ON requests (requester_id)
            WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_requests_department_id
            ON requests (department_id)
            WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_requests_status
            ON requests (status)
            WHERE deleted_at IS NULL
        ");
        // Partial index untuk request aktif (sering di-query dashboard)
        DB::statement("
            CREATE INDEX idx_requests_active
            ON requests (status, created_at DESC)
            WHERE deleted_at IS NULL
              AND status NOT IN ('COMPLETED', 'CANCELLED', 'REJECTED')
        ");

        // Trigger: auto-increment version setiap UPDATE pada tabel requests
        // Digunakan untuk optimistic locking — client wajib kirim version terkini
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_increment_request_version()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                NEW.version = OLD.version + 1;
                RETURN NEW;
            END;
            \$\$
        ");
        DB::statement("
            CREATE TRIGGER trg_requests_version
            BEFORE UPDATE ON requests
            FOR EACH ROW
            EXECUTE FUNCTION fn_increment_request_version()
        ");

        // ── status_history (IMMUTABLE — audit trail) ──────────────────────────
        // Tidak ada updated_at, tidak ada deleted_at
        // Isi tabel ini hanya boleh lewat trigger fn_log_request_status
        Schema::create('status_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('entity_type', 50);       // 'request' | 'procurement_order'
            $table->uuid('entity_id');               // Polymorphic — tidak pakai FK agar fleksibel
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignUuid('changed_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();   // IP, device, extra context
            $table->timestamp('created_at')->useCurrent();
            // SENGAJA tidak ada updated_at dan deleted_at — tabel ini immutable
        });

        DB::statement("
            CREATE INDEX idx_status_history_entity
            ON status_history (entity_type, entity_id)
        ");
        DB::statement("
            CREATE INDEX idx_status_history_changed_by
            ON status_history (changed_by)
        ");
        DB::statement("
            CREATE INDEX idx_status_history_created_at
            ON status_history (created_at DESC)
        ");

        DB::statement("COMMENT ON TABLE status_history IS
            'IMMUTABLE audit trail. Diisi otomatis oleh trigger fn_log_request_status.
             Tidak ada UPDATE atau DELETE yang diizinkan.'");

        // Trigger: catat setiap perubahan status di tabel requests ke status_history
        // App wajib SET LOCAL app.current_user_id = uuid sebelum UPDATE requests
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_log_request_status()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            DECLARE
                v_user_id UUID;
            BEGIN
                IF OLD.status IS DISTINCT FROM NEW.status THEN
                    v_user_id := NULLIF(current_setting('app.current_user_id', true), '')::UUID;

                    IF v_user_id IS NOT NULL THEN
                        INSERT INTO status_history
                            (entity_type, entity_id, from_status, to_status, changed_by)
                        VALUES
                            ('request', NEW.id, OLD.status::TEXT, NEW.status::TEXT, v_user_id);
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$
        ");
        DB::statement("
            CREATE TRIGGER trg_requests_status_log
            AFTER UPDATE OF status ON requests
            FOR EACH ROW
            EXECUTE FUNCTION fn_log_request_status()
        ");

        // ── approvals ─────────────────────────────────────────────────────────
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('request_id')->constrained('requests');
            $table->foreignUuid('approver_id')->constrained('users');
            $table->smallInteger('step');             // 1 = VERIFY (Purchasing), 2 = APPROVE (Manager)
            $table->text('notes')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Tidak ada updated_at — approval bersifat append-only
        });

        // ENUM action
        DB::statement("
            ALTER TABLE approvals
            ADD COLUMN action approval_action
        ");

        // CHECK step
        DB::statement("
            ALTER TABLE approvals
            ADD CONSTRAINT chk_approvals_step
            CHECK (step BETWEEN 1 AND 10)
        ");

        // UNIQUE constraints — mencegah double approval di level database
        // UQ 1: satu step hanya boleh ada satu kali per request
        DB::statement("
            ALTER TABLE approvals
            ADD CONSTRAINT uq_approvals_request_step
            UNIQUE (request_id, step)
        ");
        // UQ 2: satu approver hanya boleh muncul satu kali per request
        DB::statement("
            ALTER TABLE approvals
            ADD CONSTRAINT uq_approvals_request_approver
            UNIQUE (request_id, approver_id)
        ");

        DB::statement("
            CREATE INDEX idx_approvals_request_id
            ON approvals (request_id)
        ");
        DB::statement("
            CREATE INDEX idx_approvals_approver_id
            ON approvals (approver_id)
        ");
        // Partial index: approval yang belum dieksekusi (dashboard "My Pending Approvals")
        DB::statement("
            CREATE INDEX idx_approvals_pending
            ON approvals (approver_id, created_at)
            WHERE acted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_requests_status_log ON requests');
        DB::statement('DROP TRIGGER IF EXISTS trg_requests_version    ON requests');
        DB::statement('DROP FUNCTION IF EXISTS fn_log_request_status()');
        DB::statement('DROP FUNCTION IF EXISTS fn_increment_request_version()');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('status_history');
        Schema::dropIfExists('requests');
    }
};

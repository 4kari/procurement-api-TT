<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── stock ─────────────────────────────────────────────────────────────
        Schema::create('stock', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('sku', 50)->unique();
            $table->string('name', 200);
            $table->string('unit', 30);              // Pcs, Rim, Lusin, Box, Kg, dst.
            $table->integer('version')->default(1);  // Optimistic locking
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->timestamps();                    // created_at + updated_at
            $table->softDeletes();                   // deleted_at
        });

        // Kolom dengan tipe custom — harus lewat raw statement
        DB::statement("
            ALTER TABLE stock
            ADD COLUMN category item_category NOT NULL DEFAULT 'OTHER'
        ");
        DB::statement("
            ALTER TABLE stock
            ADD COLUMN quantity NUMERIC(12,2) NOT NULL DEFAULT 0
                CONSTRAINT chk_stock_quantity CHECK (quantity >= 0)
        ");
        DB::statement("
            ALTER TABLE stock
            ADD COLUMN reserved NUMERIC(12,2) NOT NULL DEFAULT 0
                CONSTRAINT chk_stock_reserved CHECK (reserved >= 0)
        ");
        // Invariant: reserved tidak boleh melebihi quantity
        DB::statement("
            ALTER TABLE stock
            ADD CONSTRAINT chk_stock_reserved_lte_quantity
            CHECK (reserved <= quantity)
        ");

        DB::statement("
            CREATE INDEX idx_stock_sku      ON stock (sku)      WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_stock_category ON stock (category) WHERE deleted_at IS NULL
        ");
        // Alert index: rows yang stoknya sudah di bawah batas minimum
        DB::statement("
            CREATE INDEX idx_stock_low_alert
            ON stock (sku, quantity)
            WHERE deleted_at IS NULL AND quantity <= min_stock
        ");

        // Trigger: auto-increment version setiap UPDATE stock
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_increment_stock_version()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                NEW.version = OLD.version + 1;
                RETURN NEW;
            END;
            \$\$
        ");
        DB::statement("
            CREATE TRIGGER trg_stock_version
            BEFORE UPDATE ON stock
            FOR EACH ROW
            EXECUTE FUNCTION fn_increment_stock_version()
        ");

        // Stored function: reservasi stok secara atomik dengan SELECT FOR UPDATE
        // Mencegah race condition ketika beberapa request disetujui bersamaan
        // Return TRUE  → stok berhasil direservasi
        // Return FALSE → stok tidak mencukupi
        // RAISE        → version mismatch (concurrent modification)
        DB::statement("
            CREATE OR REPLACE FUNCTION fn_reserve_stock(
                p_stock_id UUID,
                p_qty      NUMERIC,
                p_version  INTEGER
            )
            RETURNS BOOLEAN LANGUAGE plpgsql AS \$\$
            DECLARE
                v stock%ROWTYPE;
            BEGIN
                -- Row-level lock: transaksi lain akan BLOCK sampai ini commit/rollback
                SELECT * INTO v FROM stock WHERE id = p_stock_id FOR UPDATE;

                -- Cek version: tolak jika ada transaksi lain yang sudah mengubah row ini
                IF v.version != p_version THEN
                    RAISE EXCEPTION
                        'Concurrent modification detected on stock %. Expected version %, got %.',
                        p_stock_id, p_version, v.version
                    USING ERRCODE = '40001'; -- serialization_failure → Laravel akan retry
                END IF;

                -- Cek ketersediaan (quantity - reserved = available)
                IF (v.quantity - v.reserved) < p_qty THEN
                    RETURN FALSE;
                END IF;

                UPDATE stock
                SET reserved = reserved + p_qty
                WHERE id = p_stock_id;

                RETURN TRUE;
            END;
            \$\$
        ");

        // ── vendors ───────────────────────────────────────────────────────────
        // Sesuai ERD: tidak ada timestamps (created_at/updated_at)
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();                   // deleted_at
        });

        // rating NUMBER(3,2) — sesuai ERD (bukan DECIMAL tapi secara PostgreSQL equivalen)
        DB::statement("
            ALTER TABLE vendors
            ADD COLUMN rating NUMERIC(3,2)
                CONSTRAINT chk_vendors_rating CHECK (rating BETWEEN 0 AND 5)
        ");

        DB::statement("
            CREATE INDEX idx_vendors_is_active ON vendors (is_active) WHERE deleted_at IS NULL
        ");

        // ── procurement_orders ────────────────────────────────────────────────
        // Sesuai ERD: ada ordered_at, expected_at, received_at — tidak ada updated_at
        Schema::create('procurement_orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('po_number', 30)->unique();           // Format: PO-YYYY-XXXXXX
            $table->foreignUuid('request_id')->constrained('requests');
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->foreignUuid('created_by')->constrained('users');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // Tidak ada updated_at dan notes sesuai ERD
            $table->softDeletes();                              // deleted_at
        });

        DB::statement("
            ALTER TABLE procurement_orders
            ADD COLUMN status procurement_status NOT NULL DEFAULT 'PENDING'
        ");
        DB::statement("
            ALTER TABLE procurement_orders
            ADD CONSTRAINT chk_po_total_amount CHECK (total_amount >= 0)
        ");

        DB::statement("
            CREATE INDEX idx_po_request_id ON procurement_orders (request_id) WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_po_vendor_id  ON procurement_orders (vendor_id)  WHERE deleted_at IS NULL
        ");
        DB::statement("
            CREATE INDEX idx_po_status     ON procurement_orders (status)     WHERE deleted_at IS NULL
        ");

        // ── procurement_order_items ───────────────────────────────────────────
        // total_price adalah GENERATED ALWAYS (GEN di ERD) — quantity * unit_price
        Schema::create('procurement_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('po_id')
                  ->constrained('procurement_orders')
                  ->cascadeOnDelete();
            $table->uuid('request_item_id')->nullable(); // FK ke request_items (ditambah setelah tabel dibuat)
            $table->string('item_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        // Generated column — selalu akurat, tidak bisa di-set manual
        DB::statement("
            ALTER TABLE procurement_order_items
            ADD COLUMN total_price NUMERIC(15,2)
                GENERATED ALWAYS AS (quantity * unit_price) STORED
        ");

        DB::statement("
            ALTER TABLE procurement_order_items
            ADD CONSTRAINT chk_poi_quantity     CHECK (quantity > 0)
        ");
        DB::statement("
            ALTER TABLE procurement_order_items
            ADD CONSTRAINT chk_poi_unit_price   CHECK (unit_price >= 0)
        ");
        DB::statement("
            ALTER TABLE procurement_order_items
            ADD CONSTRAINT chk_poi_received_qty CHECK (received_qty >= 0)
        ");

        DB::statement("
            CREATE INDEX idx_poi_po_id ON procurement_order_items (po_id)
        ");

        // ── request_items ─────────────────────────────────────────────────────
        // Dibuat setelah stock agar FK bisa langsung dibuat
        // Sesuai ERD: tidak ada kolom notes
        Schema::create('request_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('request_id')
                  ->constrained('requests')
                  ->cascadeOnDelete();
            $table->uuid('stock_id')->nullable();     // Nullable: item mungkin belum ada di stok
            $table->string('item_name', 200);
            $table->string('unit', 30);
            $table->timestamps();                     // created_at + updated_at
        });

        DB::statement("
            ALTER TABLE request_items
            ADD COLUMN category        item_category NOT NULL DEFAULT 'OTHER'
        ");
        DB::statement("
            ALTER TABLE request_items
            ADD COLUMN quantity        NUMERIC(12,2) NOT NULL
                CONSTRAINT chk_ri_quantity CHECK (quantity > 0)
        ");
        DB::statement("
            ALTER TABLE request_items
            ADD COLUMN estimated_price NUMERIC(15,2)
                CONSTRAINT chk_ri_estimated_price CHECK (estimated_price >= 0)
        ");

        // FK ke stock — nullable, ON DELETE SET NULL
        DB::statement("
            ALTER TABLE request_items
            ADD CONSTRAINT fk_request_items_stock_id
            FOREIGN KEY (stock_id)
            REFERENCES stock(id)
            ON DELETE SET NULL
        ");

        // FK procurement_order_items.request_item_id → request_items.id
        // Ditutup di sini setelah request_items selesai dibuat
        DB::statement("
            ALTER TABLE procurement_order_items
            ADD CONSTRAINT fk_poi_request_item_id
            FOREIGN KEY (request_item_id)
            REFERENCES request_items(id)
            ON DELETE SET NULL
        ");

        DB::statement("
            CREATE INDEX idx_ri_request_id  ON request_items (request_id)
        ");
        DB::statement("
            CREATE INDEX idx_ri_stock_id    ON request_items (stock_id)    WHERE stock_id IS NOT NULL
        ");
        DB::statement("
            CREATE INDEX idx_ri_category    ON request_items (category)
        ");

        // ── Reporting View ────────────────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW v_request_summary AS
            SELECT
                r.id,
                r.request_number,
                u.name                                                          AS requester_name,
                d.name                                                          AS department_name,
                r.title,
                r.status,
                r.priority,
                r.submitted_at,
                r.completed_at,
                ROUND(
                    EXTRACT(EPOCH FROM (r.completed_at - r.submitted_at)) / 3600,
                    2
                )                                                               AS lead_time_hours,
                COUNT(ri.id)                                                    AS item_count
            FROM requests r
            JOIN users       u  ON u.id = r.requester_id
            JOIN departments d  ON d.id = r.department_id
            LEFT JOIN request_items ri ON ri.request_id = r.id
            WHERE r.deleted_at IS NULL
            GROUP BY r.id, u.name, d.name
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_request_summary');

        DB::statement('ALTER TABLE procurement_order_items DROP CONSTRAINT IF EXISTS fk_poi_request_item_id');

        Schema::dropIfExists('request_items');
        Schema::dropIfExists('procurement_order_items');
        Schema::dropIfExists('procurement_orders');
        Schema::dropIfExists('vendors');

        DB::statement('DROP TRIGGER  IF EXISTS trg_stock_version            ON stock');
        DB::statement('DROP FUNCTION IF EXISTS fn_increment_stock_version()');
        DB::statement('DROP FUNCTION IF EXISTS fn_reserve_stock(UUID, NUMERIC, INTEGER)');

        Schema::dropIfExists('stock');
    }
};

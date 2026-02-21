<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── stock ─────────────────────────────────────────────────
        Schema::create('stock', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('sku', 50)->unique();
            $table->string('name', 200);
            $table->string('unit', 30);
            $table->string('location', 100)->nullable();
            $table->integer('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE stock ADD COLUMN category  item_category NOT NULL DEFAULT 'OTHER'");
        DB::statement('ALTER TABLE stock ADD COLUMN quantity  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE stock ADD COLUMN reserved  NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stock ADD COLUMN min_stock NUMERIC(12,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE stock ADD CONSTRAINT chk_stock_reserved CHECK (reserved <= quantity)');
        DB::statement('CREATE INDEX idx_stock_sku  ON stock (sku) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_stock_low  ON stock (quantity, sku) WHERE deleted_at IS NULL AND quantity <= min_stock');

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_increment_stock_version()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN NEW.version = OLD.version + 1; RETURN NEW; END; \$\$
        ");
        DB::statement('CREATE TRIGGER trg_stock_version BEFORE UPDATE ON stock
            FOR EACH ROW EXECUTE FUNCTION fn_increment_stock_version()');

        DB::statement("
            CREATE OR REPLACE FUNCTION fn_reserve_stock(p_stock_id UUID, p_qty NUMERIC, p_version INTEGER)
            RETURNS BOOLEAN LANGUAGE plpgsql AS \$\$
            DECLARE v stock%ROWTYPE;
            BEGIN
                SELECT * INTO v FROM stock WHERE id = p_stock_id FOR UPDATE;
                IF v.version != p_version THEN
                    RAISE EXCEPTION 'Concurrent modification on stock %. Retry.', p_stock_id USING ERRCODE = '40001';
                END IF;
                IF (v.quantity - v.reserved) < p_qty THEN RETURN FALSE; END IF;
                UPDATE stock SET reserved = reserved + p_qty WHERE id = p_stock_id;
                RETURN TRUE;
            END; \$\$
        ");

        // ── vendors ───────────────────────────────────────────────
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('npwp', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('rating', 3, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('ALTER TABLE vendors ADD CONSTRAINT chk_vendors_rating CHECK (rating BETWEEN 0 AND 5)');

        // ── procurement_orders ────────────────────────────────────
        Schema::create('procurement_orders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('po_number', 30)->unique();
            $table->foreignUuid('request_id')->constrained('requests');
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->foreignUuid('created_by')->constrained('users');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE procurement_orders ADD COLUMN status procurement_status NOT NULL DEFAULT 'PENDING'");
        DB::statement('ALTER TABLE procurement_orders ADD CONSTRAINT chk_po_total CHECK (total_amount >= 0)');
        DB::statement('CREATE INDEX idx_po_request ON procurement_orders (request_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_po_vendor  ON procurement_orders (vendor_id)  WHERE deleted_at IS NULL');

        // ── procurement_order_items ───────────────────────────────
        Schema::create('procurement_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('po_id')->constrained('procurement_orders')->cascadeOnDelete();
            $table->uuid('request_item_id')->nullable();
            $table->string('item_name', 200);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE procurement_order_items
            ADD COLUMN total_price NUMERIC(18,2) GENERATED ALWAYS AS (quantity * unit_price) STORED');
        DB::statement('ALTER TABLE procurement_order_items ADD CONSTRAINT chk_poi_qty CHECK (quantity > 0)');
        DB::statement('ALTER TABLE procurement_order_items ADD CONSTRAINT chk_poi_price CHECK (unit_price >= 0)');

        // ── request_items ─────────────────────────────────────────
        Schema::create('request_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('request_id')->constrained('requests')->cascadeOnDelete();
            $table->uuid('stock_id')->nullable();
            $table->string('item_name', 200);
            $table->string('unit', 30);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE request_items ADD COLUMN category        item_category NOT NULL DEFAULT 'OTHER'");
        DB::statement('ALTER TABLE request_items ADD COLUMN quantity        NUMERIC(12,2) NOT NULL CHECK (quantity > 0)');
        DB::statement('ALTER TABLE request_items ADD COLUMN estimated_price NUMERIC(15,2) CHECK (estimated_price >= 0)');
        DB::statement('ALTER TABLE request_items ADD CONSTRAINT fk_request_items_stock
            FOREIGN KEY (stock_id) REFERENCES stock(id) ON DELETE SET NULL');
        DB::statement('CREATE INDEX idx_req_items_request ON request_items (request_id)');
        DB::statement('CREATE INDEX idx_req_items_stock   ON request_items (stock_id) WHERE stock_id IS NOT NULL');

        // ── reporting view ────────────────────────────────────────
        DB::statement("
            CREATE OR REPLACE VIEW v_request_summary AS
            SELECT r.id, r.request_number, u.name AS requester_name, d.name AS department_name,
                r.title, r.status, r.priority, r.submitted_at, r.completed_at,
                ROUND(EXTRACT(EPOCH FROM (r.completed_at - r.submitted_at)) / 3600, 2) AS lead_time_hours,
                COUNT(ri.id) AS item_count
            FROM requests r
            JOIN users u ON u.id = r.requester_id
            JOIN departments d ON d.id = r.department_id
            LEFT JOIN request_items ri ON ri.request_id = r.id
            WHERE r.deleted_at IS NULL
            GROUP BY r.id, u.name, d.name
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_request_summary');
        Schema::dropIfExists('request_items');
        Schema::dropIfExists('procurement_order_items');
        Schema::dropIfExists('procurement_orders');
        Schema::dropIfExists('vendors');
        DB::statement('DROP TRIGGER IF EXISTS trg_stock_version ON stock');
        DB::statement('DROP FUNCTION IF EXISTS fn_increment_stock_version');
        DB::statement('DROP FUNCTION IF EXISTS fn_reserve_stock');
        Schema::dropIfExists('stock');
    }
};

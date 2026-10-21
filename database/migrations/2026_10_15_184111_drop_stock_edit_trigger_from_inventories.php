<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `products.stock` lo mantiene ahora InventoryLedgerService como caché del
     * kardex; el trigger `stock_edit` solo cubría Compra/Venta, ignoraba saldos
     * negativos y no corría en SQLite — dos fuentes de verdad que divergían.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS stock_edit');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared("CREATE TRIGGER stock_edit AFTER INSERT ON inventories
            FOR EACH ROW
            BEGIN
                IF NEW.type = 'Compra' OR NEW.type = 'Devolución en venta'
                    THEN
                        UPDATE products
                        SET stock = stock + NEW.quantity
                        WHERE stock IS NOT NULL AND products.id = NEW.product_id;
                    END IF;

                IF NEW.type = 'Venta' OR NEW.type = 'Devolución en compra'
                    THEN
                        UPDATE products
                        SET stock = stock - NEW.quantity
                        WHERE stock IS NOT NULL AND stock - NEW.quantity >= 0 AND products.id = NEW.product_id;
                    END IF;
            END
        ");
    }
};

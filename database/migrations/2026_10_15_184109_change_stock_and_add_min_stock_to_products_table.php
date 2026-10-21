<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `stock` pasa a ser caché del kardex (suma del saldo de todas las bodegas,
     * la mantiene InventoryLedgerService); decimal porque el kardex maneja
     * cantidades fraccionarias. `min_stock` alimenta el reporte de bajo stock.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 14, 4)->nullable()->change();
            $table->decimal('min_stock', 14, 4)->nullable()->after('stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_stock');
            $table->integer('stock')->nullable()->change();
        });
    }
};

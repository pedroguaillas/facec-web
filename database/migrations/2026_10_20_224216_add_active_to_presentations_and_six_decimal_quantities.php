<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `active`: una presentación inactiva no se ofrece ni se acepta en ventas
     * y compras nuevas, pero sigue en las líneas que ya la usaron.
     *
     * Cantidades a 6 decimales: el `conversion_factor` de la presentación
     * (ej. 1/12 = 0.083333) y, para no perder esa precisión al multiplicar,
     * la cantidad y los saldos del kardex y el stock del producto. La copia
     * del factor en `order_items`/`shop_items` ya se crea con 6 decimales
     * (2026_10_16_181044) para no reconstruir `order_items` dos veces.
     */
    public function up(): void
    {
        Schema::table('product_presentations', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('code');
            $table->decimal('conversion_factor', 14, 6)->change();
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('quantity', 14, 6)->change();
            $table->decimal('previous_balance', 14, 6)->nullable()->change();
            $table->decimal('new_balance', 14, 6)->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 14, 6)->nullable()->change();
            $table->decimal('min_stock', 14, 6)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_presentations', function (Blueprint $table) {
            $table->dropColumn('active');
            $table->decimal('conversion_factor', 12, 4)->change();
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('quantity', 14, 4)->change();
            $table->decimal('previous_balance', 14, 4)->nullable()->change();
            $table->decimal('new_balance', 14, 4)->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock', 14, 4)->nullable()->change();
            $table->decimal('min_stock', 14, 4)->nullable()->change();
        });
    }
};

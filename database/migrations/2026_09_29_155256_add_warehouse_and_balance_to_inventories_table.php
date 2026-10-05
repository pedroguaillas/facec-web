<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convierte `inventories` en un kardex real con saldo consolidado (mismo
     * patrón previous_balance/new_balance que la cartera). `warehouse_id`
     * nullable: filas históricas anteriores a esta migración no tienen bodega
     * y quedan fuera del cálculo de saldo (dato histórico, no se retoca).
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('product_id');
            $table->decimal('previous_balance', 14, 4)->nullable()->after('price');
            $table->decimal('new_balance', 14, 4)->nullable()->after('previous_balance');

            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->index(['product_id', 'warehouse_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['product_id', 'warehouse_id', 'id']);
            $table->dropColumn(['warehouse_id', 'previous_balance', 'new_balance']);
        });
    }
};

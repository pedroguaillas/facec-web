<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Presentación con la que se vendió/compró la línea (ej. "Cubeta x30").
     * `quantity` y `price` de la línea quedan en esa presentación (es lo que
     * va al comprobante); el kardex mueve `quantity × conversion_factor`
     * unidades base. `conversion_factor` es una copia del factor al momento de
     * la venta/compra: editar o borrar la presentación después no altera la
     * historia. Sin presentación: factor 1 (unidad base).
     */
    public function up(): void
    {
        foreach (['order_items', 'shop_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('presentation_id')->nullable()->after('product_id');
                $table->decimal('conversion_factor', 14, 6)->default(1)->after('presentation_id');

                $table->foreign('presentation_id')->references('id')->on('product_presentations')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['order_items', 'shop_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['presentation_id']);
                $table->dropColumn(['presentation_id', 'conversion_factor']);
            });
        }
    }
};

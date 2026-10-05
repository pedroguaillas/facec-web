<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Variantes livianas: cada tamaño/presentación sigue siendo su propio
     * Product (con su propio code/stock), solo se agrupan bajo un producto
     * padre común para reportes/listado (ej. "Cemento" agrupa 25kg y 50kg).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_product_id')->nullable()->after('category_id');

            $table->foreign('parent_product_id')->references('id')->on('products');
            $table->index('parent_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_product_id']);
            $table->dropColumn('parent_product_id');
        });
    }
};

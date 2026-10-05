<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Vincula el código propio del proveedor (el que trae su XML/factura de
     * compra) con el Product interno — para autocompletar el producto al
     * registrar una compra en vez de buscarlo a mano cada vez.
     */
    public function up(): void
    {
        Schema::create('provider_product_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('product_id');
            $table->string('code', 50); // Código del proveedor, tal como viene en su XML.
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('provider_id')->references('id')->on('providers');
            $table->foreign('product_id')->references('id')->on('products');
            // Un código de este proveedor resuelve a un solo producto; un producto
            // puede tener varios códigos del mismo proveedor (distintas presentaciones).
            $table->unique(['provider_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_product_codes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Catálogo de formas de vender/comprar un mismo producto (ej. "caja x24",
     * "paquete x6") con su factor de conversión a la unidad base del producto.
     * Es solo catálogo — el frontend calcula la cantidad en unidad base y la
     * manda como siempre en products.*.quantity; no se guarda a nivel de
     * línea de venta/compra qué presentación se usó.
     */
    public function up(): void
    {
        Schema::create('product_presentations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('name'); // "Caja x24", "Paquete x6"
            $table->decimal('conversion_factor', 12, 4); // Cuántas unidades base equivale.
            $table->decimal('price', 14, 6)->nullable(); // Precio propio; null = price1 * factor.
            $table->string('code', 25)->nullable(); // Código/barra propio de esta presentación.
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_presentations');
    }
};

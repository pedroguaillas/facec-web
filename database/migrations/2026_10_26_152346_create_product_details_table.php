<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Valor de cada característica de un producto (sin límite de cantidad).
     */
    public function up(): void
    {
        Schema::create('product_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_template_field_id');
            $table->string('value', 300);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('product_template_field_id')->references('id')->on('product_template_fields');
            $table->unique(['product_id', 'product_template_field_id']);
            // Búsqueda por valor de campos únicos (chasis, motor) y control de duplicados.
            $table->index(['product_template_field_id', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_details');
    }
};

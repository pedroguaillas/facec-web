<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plantillas de características de producto que configura el propio
     * contribuyente (ej. "Moto": Marca, Año, Modelo, Chasis, Motor, RAMV).
     * Hasta 3 campos por plantilla van al XML como <detAdicional> (in_sri);
     * el RIDE muestra todos. Ver docs/caracteristicas-producto.md.
     */
    public function up(): void
    {
        Schema::create('product_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);
            // Prefijo del código de cada unidad (ej. "M" → M5452). Nullable: sin prefijo, el código es el valor del campo.
            $table->string('code_prefix', 5)->nullable();
            $table->unsignedBigInteger('code_field_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('product_template_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_template_id');
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('type', 10)->default('text');
            // modelo: lo comparte el producto padre (Marca, Año); unidad: propio de cada variante (Chasis, Motor).
            $table->string('level', 10);
            $table->boolean('required')->default(false);
            $table->boolean('unique')->default(false);
            $table->boolean('in_sri')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('product_template_id')->references('id')->on('product_templates')->cascadeOnDelete();
            $table->unique(['product_template_id', 'key']);
        });

        Schema::table('product_templates', function (Blueprint $table) {
            $table->foreign('code_field_id')->references('id')->on('product_template_fields')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_templates', function (Blueprint $table) {
            $table->dropForeign(['code_field_id']);
        });

        Schema::dropIfExists('product_template_fields');
        Schema::dropIfExists('product_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ubicaciones de stock dentro de la misma sucursal (no multi-sucursal:
     * eso sigue siendo un límite conocido del sistema, ver docs/productos.md §6.3).
     * Si una empresa no configura ninguna, se crea "Bodega principal" sola la
     * primera vez que se necesita (ver App\Services\Product\WarehouseResolver).
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->index('branch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};

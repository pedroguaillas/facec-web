<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `differentiated_iva`: el producto/servicio se factura con IVA
     * diferenciado (8%) cuando la venta cae en un feriado y la empresa tiene
     * `base8`. Se conservan los valores ya marcados en `tourism`.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('tourism', 'differentiated_iva');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('differentiated_iva', 'tourism');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 'PENDIENTE DE ANULAR' (estado devuelto por el WS ConsultaComprobante del SRI) tiene 20 caracteres,
        // no entraba en el char(15) original.
        Schema::table('orders', function (Blueprint $table) {
            $table->char('state', 20)->default('CREADO')->change();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->char('state', 20)->nullable()->change();
            $table->string('state_retencion', 20)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('state', 15)->default('CREADO')->change();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->char('state', 15)->nullable()->change();
            $table->string('state_retencion', 15)->nullable()->change();
        });
    }
};

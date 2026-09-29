<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Permite movimientos de cartera sin factura/compra real detrás — necesario
     * para registrar el saldo anterior (SALDO_INICIAL) de un cliente/proveedor
     * al migrar a facec.
     */
    public function up(): void
    {
        Schema::table('account_receivable_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });

        Schema::table('account_payable_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_receivable_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });

        Schema::table('account_payable_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formas de pago de una venta: una fila por cada <pago> del XML. Suman el
     * total de la venta. Las de contado con caja/banco apuntan a su Movement.
     */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedSmallInteger('pay_method'); // Código SRI Tabla 24 (method_of_payments.code)
            $table->string('payment_type', 10); // App\StaticClasses\PaymentTypes
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('movement_id')->references('id')->on('movements')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};

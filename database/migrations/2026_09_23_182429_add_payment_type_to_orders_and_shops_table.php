<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `paid`/`expiration_days` quedan en la tabla pero dejan de ser la fuente
     * de verdad: `payment_type` (App\StaticClasses\PaymentTypes) reemplaza la
     * inferencia de contado/crédito. `due_date` aplica solo con CREDITO;
     * `movement_id` (Treasury) aplica solo con CONTADO — enlaza la venta/compra
     * al ingreso/egreso real de caja o banco.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_type', 20)->nullable()->after('expiration_days');
            $table->date('due_date')->nullable()->after('payment_type');
            $table->unsignedBigInteger('movement_id')->nullable()->after('due_date');

            $table->foreign('movement_id')->references('id')->on('movements');
            $table->index('due_date');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->string('payment_type', 20)->nullable()->after('expiration_days');
            $table->date('due_date')->nullable()->after('payment_type');
            $table->unsignedBigInteger('movement_id')->nullable()->after('due_date');

            $table->foreign('movement_id')->references('id')->on('movements');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['movement_id']);
            $table->dropIndex(['due_date']);
            $table->dropColumn(['payment_type', 'due_date', 'movement_id']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign(['movement_id']);
            $table->dropIndex(['due_date']);
            $table->dropColumn(['payment_type', 'due_date', 'movement_id']);
        });
    }
};

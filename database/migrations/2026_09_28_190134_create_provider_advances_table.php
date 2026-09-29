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
        Schema::create('provider_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('movement_id'); // Treasury: egreso real de caja/banco.
            $table->decimal('amount', 10, 2);
            $table->decimal('applied_amount', 10, 2)->default(0); // Consumido contra compras (payment_type=ANTICIPO).
            $table->date('date');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('provider_id')->references('id')->on('providers');
            $table->foreign('movement_id')->references('id')->on('movements');
            $table->index('provider_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_advances');
    }
};

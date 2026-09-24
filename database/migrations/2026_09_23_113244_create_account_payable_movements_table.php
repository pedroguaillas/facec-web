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
        Schema::create('account_payable_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('shop_id'); // Factura relacionada; la RETENCION apunta al mismo shop_id que su factura.
            $table->string('type', 20); // App\StaticClasses\AccountsPayableMovementTypes
            $table->decimal('debe', 10, 2)->default(0);
            $table->decimal('haber', 10, 2)->default(0);
            $table->decimal('previous_balance', 10, 2);
            $table->decimal('new_balance', 10, 2);
            $table->string('description');
            $table->date('date');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('provider_id')->references('id')->on('providers');
            $table->foreign('shop_id')->references('id')->on('shops');
            $table->index(['provider_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_payable_movements');
    }
};

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
        Schema::create('account_receivable_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('order_id'); // Factura relacionada; una NC apunta a la factura ORIGINAL, no a si misma.
            $table->string('type', 20); // App\StaticClasses\AccountsReceivableMovementTypes
            $table->decimal('debe', 10, 2)->default(0);
            $table->decimal('haber', 10, 2)->default(0);
            $table->decimal('previous_balance', 10, 2);
            $table->decimal('new_balance', 10, 2);
            $table->string('description');
            $table->date('date');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('order_id')->references('id')->on('orders');
            $table->index(['customer_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_receivable_movements');
    }
};

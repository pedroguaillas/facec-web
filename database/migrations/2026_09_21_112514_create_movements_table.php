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
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->date('date');
            $table->string('type', 20); // INGRESO | EGRESO (App\StaticClasses\MovementTypes)
            $table->string('transaction_type', 30); // App\StaticClasses\MovementTransactionTypes
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('destination_type', 40)->nullable();
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('previous_balance', 12, 2)->nullable();
            $table->decimal('new_balance', 12, 2)->nullable();
            $table->string('status', 20)->default('PENDIENTE'); // App\StaticClasses\MovementStatuses
            $table->string('receipt_number', 49)->nullable();
            $table->string('payment_method', 20)->nullable(); // App\StaticClasses\PaymentMethods
            $table->string('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['source_type', 'source_id']);
            $table->index(['destination_type', 'destination_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};

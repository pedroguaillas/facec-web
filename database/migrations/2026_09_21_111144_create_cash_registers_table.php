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
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 100);
            $table->unsignedBigInteger('responsible_id');
            $table->string('type', 20); // GENERAL | CHICA | OTRO (App\StaticClasses\CashRegisterTypes)
            $table->string('status', 20)->default('CERRADA'); // App\StaticClasses\CashRegisterStatuses
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('responsible_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};

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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('bank_id');
            $table->string('account_number', 50);
            $table->string('type', 20); // AHORRO | CORRIENTE (App\StaticClasses\BankAccountTypes)
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('bank_id')->references('id')->on('banks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};

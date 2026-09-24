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
        Schema::create('cash_register_session_denominations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cash_register_session_id');
            $table->unsignedBigInteger('denomination_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->string('moment', 10); // APERTURA | CIERRE (App\StaticClasses\DenominationMoments)
            $table->timestamps();

            $table->foreign('cash_register_session_id', 'crsd_session_fk')
                ->references('id')->on('cash_register_sessions');
            $table->foreign('denomination_id')->references('id')->on('denominations');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_register_session_denominations');
    }
};

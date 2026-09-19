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
        Schema::create('emision_point_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('emision_point_id');
            $table->unsignedBigInteger('voucher_type_id');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->foreign('emision_point_id')->references('id')->on('emision_points')->cascadeOnDelete();
            $table->foreign('voucher_type_id')->references('id')->on('voucher_types')->cascadeOnDelete();
            $table->unique(['emision_point_id', 'voucher_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emision_point_sequences');
    }
};

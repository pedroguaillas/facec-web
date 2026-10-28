<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comprobantes (imagen) de cobros/pagos por banco. Se suben antes de
     * registrar el pago y se vinculan al Movement que lo genera (su
     * `receipt_path` toma el mismo archivo). Los no vinculados se purgan a las 24h.
     */
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('path');
            $table->string('mime_type', 50);
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('movement_id')->references('id')->on('movements')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['movement_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};

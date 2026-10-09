<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Feriados nacionales con IVA diferenciado (8%) para servicios turísticos.
     * Son globales (los decreta el gobierno para todo el país), los mantiene
     * el admin. Reemplazan a `companies.tourism_from/tourism_to`.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('starts_at');
            $table->date('ends_at');
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};

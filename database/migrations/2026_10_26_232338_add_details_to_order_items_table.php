<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de las características del producto al vender (ver
     * docs/caracteristicas-producto.md §7): [{label, value, in_sri, sort}].
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('details')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};

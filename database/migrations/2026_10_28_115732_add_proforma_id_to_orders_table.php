<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Factura generada desde una proforma (orders/{id}/convert-to-invoice):
     * evita convertir dos veces la misma proforma. Las conversiones previas a
     * esta columna quedan en null (no hay forma de saber cuáles fueron).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('proforma_id')->nullable()->after('voucher_type_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proforma_id');
        });
    }
};

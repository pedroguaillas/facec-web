<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código y descripción propios de la línea (null = los del producto).
     * Hoy los llena solo la factura recurrente (ej. "AC{mm}{año}" →
     * AC102026); el XML, el PDF y el detalle los usan en lugar de los del
     * producto. Límites del SRI: codigoPrincipal 25, descripcion 300.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('code', 25)->nullable()->after('product_id');
            $table->string('description', 300)->nullable()->after('code');
        });

        // En la plantilla son textos con marcadores; se validan ya renderizados.
        Schema::table('recurring_invoice_items', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('product_id');
            $table->string('description', 300)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['code', 'description']);
        });

        Schema::table('recurring_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['code', 'description']);
        });
    }
};

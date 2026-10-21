<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Los servicios (`type_product = 2`) no manejan inventario: se limpia el
     * `stock`/`min_stock` que pudieran tener tipeado. Desde ahora
     * Product::booted() lo mantiene en null.
     */
    public function up(): void
    {
        DB::table('products')
            ->where('type_product', 2)
            ->update(['stock' => null, 'min_stock' => null]);
    }

    /**
     * Reverse the migrations.
     *
     * Dato informativo descartado; no se restaura.
     */
    public function down(): void
    {
        //
    }
};

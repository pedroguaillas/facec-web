<?php

use Database\Seeders\VoucherTypeSeeder;
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
        Schema::create('voucher_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->enum('module', ['venta', 'compra', 'comun']);
            $table->char('code_sri', 2)->nullable(); // codDoc SRI; null = no es documento electrónico SRI
            $table->string('name', 60);
            $table->string('abbreviation', 6); // sigla corta para UI/impresos: FAC, N/C, N/D, RET, L/C, G/R...
            $table->boolean('requires_electronic')->default(false);
            $table->boolean('requires_sequence')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Puebla el catálogo en el mismo `migrate` — las migraciones siguientes
        // (add_voucher_type_id_to_orders/shops_table) hacen backfill leyendo estas
        // filas por slug, y en producción solo se corre `migrate`, no `db:seed`
        // (ver README §Producción). Sin este seed inline, esas migraciones
        // insertarían voucher_type_id NULL y fallarían al forzar NOT NULL.
        (new VoucherTypeSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_types');
    }
};

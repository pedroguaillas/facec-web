<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Completa el kardex (ver docs/inventario.md):
     * - `model_type` + `model_id`: documento de origen polimórfico (alias del
     *   morph map: `order`, `shop`). Antes `model_id` solo no distinguía si era
     *   una venta o una compra (la Order 5 y la Shop 5 chocaban).
     * - `direction` (`in`/`out`): separa el signo del movimiento de su etiqueta (`type`).
     * - `unit_cost`/`average_cost`/`balance_value`: costo promedio ponderado.
     * - `reverses_id`: el kardex nunca se edita ni se borra; anular/editar un
     *   documento genera movimientos de reversa que apuntan al original.
     * - `document_uuid`: agrupa las filas de una misma operación manual
     *   (inventario inicial, ajuste, transferencia).
     * - `code_provider` se elimina: nunca se llenó, lo cubre `provider_product_codes`.
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->string('model_type', 30)->nullable()->after('model_id');
            $table->string('direction', 3)->nullable()->after('type');
            $table->decimal('unit_cost', 14, 6)->nullable()->after('price');
            $table->decimal('average_cost', 14, 6)->nullable()->after('new_balance');
            $table->decimal('balance_value', 16, 4)->nullable()->after('average_cost');
            $table->unsignedBigInteger('reverses_id')->nullable()->after('balance_value');
            $table->uuid('document_uuid')->nullable()->after('reverses_id');
            $table->unsignedBigInteger('user_id')->nullable()->after('document_uuid');
            $table->string('note')->nullable()->after('user_id');

            $table->foreign('reverses_id')->references('id')->on('inventories');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['model_type', 'model_id']);
            $table->index('document_uuid');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('quantity', 14, 4)->change();
            $table->decimal('price', 14, 6)->nullable()->change();
            $table->dropColumn('code_provider');
        });

        // Filas históricas: el tipo de documento se deduce del `type`.
        DB::table('inventories')->whereIn('type', ['Venta', 'Devolución en venta'])->update(['model_type' => 'order']);
        DB::table('inventories')->whereIn('type', ['Compra', 'Devolución en compra'])->update(['model_type' => 'shop']);
        DB::table('inventories')->whereNull('model_id')->update(['model_type' => null]);
        DB::table('inventories')
            ->whereIn('type', ['Compra', 'Devolución en venta', 'Inventario inicial', 'Ajuste ingreso'])
            ->update(['direction' => 'in']);
        DB::table('inventories')
            ->whereIn('type', ['Venta', 'Devolución en compra', 'Ajuste salida'])
            ->update(['direction' => 'out']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['reverses_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['model_type', 'model_id']);
            $table->dropIndex(['document_uuid']);
            $table->dropColumn([
                'model_type', 'direction', 'unit_cost', 'average_cost', 'balance_value',
                'reverses_id', 'document_uuid', 'user_id', 'note',
            ]);
            $table->string('code_provider', 25)->nullable();
        });
    }
};

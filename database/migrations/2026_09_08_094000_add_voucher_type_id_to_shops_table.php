<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_type_id')->nullable()->after('voucher_type');
        });

        $facturaId = DB::table('voucher_types')->where('slug', 'compra_factura')->value('id');
        $notaVentaId = DB::table('voucher_types')->where('slug', 'compra_nota_venta')->value('id');
        $liquidacionId = DB::table('voucher_types')->where('slug', 'compra_liquidacion_compra')->value('id');
        $notaDebitoId = DB::table('voucher_types')->where('slug', 'compra_nota_debito')->value('id');

        DB::table('shops')->where('voucher_type', 1)->update(['voucher_type_id' => $facturaId]);
        DB::table('shops')->where('voucher_type', 2)->update(['voucher_type_id' => $notaVentaId]);
        DB::table('shops')->where('voucher_type', 3)->update(['voucher_type_id' => $liquidacionId]);
        DB::table('shops')->where('voucher_type', 5)->update(['voucher_type_id' => $notaDebitoId]);

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_type_id')->nullable(false)->change();
            $table->foreign('voucher_type_id')->references('id')->on('voucher_types');
            $table->dropColumn('voucher_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->smallInteger('voucher_type')->default(1)->after('voucher_type_id');
        });

        DB::table('shops as s')
            ->join('voucher_types as vt', 'vt.id', '=', 's.voucher_type_id')
            ->where('vt.slug', 'compra_factura')
            ->update(['s.voucher_type' => 1]);

        DB::table('shops as s')
            ->join('voucher_types as vt', 'vt.id', '=', 's.voucher_type_id')
            ->where('vt.slug', 'compra_nota_venta')
            ->update(['s.voucher_type' => 2]);

        DB::table('shops as s')
            ->join('voucher_types as vt', 'vt.id', '=', 's.voucher_type_id')
            ->where('vt.slug', 'compra_liquidacion_compra')
            ->update(['s.voucher_type' => 3]);

        DB::table('shops as s')
            ->join('voucher_types as vt', 'vt.id', '=', 's.voucher_type_id')
            ->where('vt.slug', 'compra_nota_debito')
            ->update(['s.voucher_type' => 5]);

        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign(['voucher_type_id']);
            $table->dropColumn('voucher_type_id');
        });
    }
};

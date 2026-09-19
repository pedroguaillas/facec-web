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
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('voucher_type_id')->nullable()->after('voucher_type');
        });

        $facturaId = DB::table('voucher_types')->where('slug', 'venta_factura')->value('id');
        $notaCreditoId = DB::table('voucher_types')->where('slug', 'venta_nota_credito')->value('id');

        DB::table('orders')->where('voucher_type', 1)->update(['voucher_type_id' => $facturaId]);
        DB::table('orders')->where('voucher_type', 4)->update(['voucher_type_id' => $notaCreditoId]);

        Schema::table('orders', function (Blueprint $table) {
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
        Schema::table('orders', function (Blueprint $table) {
            $table->smallInteger('voucher_type')->default(1)->after('voucher_type_id');
        });

        DB::table('orders as o')
            ->join('voucher_types as vt', 'vt.id', '=', 'o.voucher_type_id')
            ->where('vt.slug', 'venta_factura')
            ->update(['o.voucher_type' => 1]);

        DB::table('orders as o')
            ->join('voucher_types as vt', 'vt.id', '=', 'o.voucher_type_id')
            ->where('vt.slug', 'venta_nota_credito')
            ->update(['o.voucher_type' => 4]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['voucher_type_id']);
            $table->dropColumn('voucher_type_id');
        });
    }
};

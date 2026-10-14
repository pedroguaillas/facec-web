<?php

use App\StaticClasses\MovementCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `category` (App\StaticClasses\MovementCategories) y el documento de
     * origen (`origin_type` = 'order'|'shop'). Backfill: categoría automática
     * por transaction_type y origen desde orders/shops.movement_id (ventas y
     * compras de contado). Los abonos previos no guardaban su documento:
     * quedan sin origen.
     */
    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('transaction_type');
            $table->string('origin_type', 20)->nullable()->after('destination_id');
            $table->unsignedBigInteger('origin_id')->nullable()->after('origin_type');

            $table->index(['branch_id', 'date']);
            $table->index(['origin_type', 'origin_id']);
        });

        foreach (MovementCategories::AUTOMATIC as $transactionType => $category) {
            DB::table('movements')
                ->where('transaction_type', $transactionType)
                ->whereNull('category')
                ->update(['category' => $category]);
        }

        foreach (['order' => 'orders', 'shop' => 'shops'] as $originType => $table) {
            DB::table($table)
                ->whereNotNull('movement_id')
                ->select(['id', 'movement_id'])
                ->orderBy('id')
                ->each(fn ($document) => DB::table('movements')
                    ->where('id', $document->movement_id)
                    ->update(['origin_type' => $originType, 'origin_id' => $document->id]));
        }
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'date']);
            $table->dropIndex(['origin_type', 'origin_id']);
            $table->dropColumn(['category', 'origin_type', 'origin_id']);
        });
    }
};

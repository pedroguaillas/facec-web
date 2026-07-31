<?php

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
        Schema::table('lots', function (Blueprint $table) {
            $table->date('date')->nullable()->after('serie');
            // voucher_type defecto 1 para facturas
            $table->unsignedTinyInteger('voucher_type')->default(1)->after('date');
            $table->string('xml')->nullable()->after('voucher_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['date', 'voucher_type']);
        });
    }
};

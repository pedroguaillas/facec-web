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
     * `referral_guides` nunca tuvo una columna `date` (fecha de emisión del
     * documento) — solo `date_start`/`date_end` (fechaIniTransporte /
     * fechaFinTransporte, información del movimiento de mercadería) y
     * `date_invoice` (fecha del comprobante sustento). El código venía
     * reusando `date_start` como si fuera la fecha de emisión (listado,
     * clave de acceso), conflando dos conceptos distintos del SRI.
     */
    public function up(): void
    {
        Schema::table('referral_guides', function (Blueprint $table) {
            $table->date('date')->nullable()->after('serie');
        });

        // Backfill: mejor aproximación disponible para guías ya existentes.
        DB::table('referral_guides')->update(['date' => DB::raw('date_start')]);

        Schema::table('referral_guides', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_guides', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};

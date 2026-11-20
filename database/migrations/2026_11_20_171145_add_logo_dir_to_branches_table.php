<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logo propio de cada establecimiento (storage/app/public/logos). Null =
     * usa el logo de la empresa (`companies.logo_dir`).
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('logo_dir', 60)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('logo_dir');
        });
    }
};

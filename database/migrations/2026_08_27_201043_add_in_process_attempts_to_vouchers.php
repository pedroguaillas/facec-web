<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('in_process_attempts')->default(0)->after('state');
        });

        Schema::table('referral_guides', function (Blueprint $table) {
            $table->unsignedTinyInteger('in_process_attempts')->default(0)->after('state');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedTinyInteger('in_process_attempts')->default(0)->after('state');
            $table->unsignedTinyInteger('in_process_attempts_retention')->default(0)->after('state_retencion');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('in_process_attempts');
        });

        Schema::table('referral_guides', function (Blueprint $table) {
            $table->dropColumn('in_process_attempts');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['in_process_attempts', 'in_process_attempts_retention']);
        });
    }
};

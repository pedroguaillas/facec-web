<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices para la actividad de contribuyentes del admin
     * (CompanyService::activityStats): cubren la pasada agrupada por sucursal
     * (fecha, estado y tipo sin leer la tabla) y el último uso de token de
     * los usuarios de cada empresa (`level_id`).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['branch_id', 'date', 'state', 'voucher_type_id'], 'orders_activity_index');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->index(['branch_id', 'date', 'state'], 'shops_activity_index');
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->index(['level_id', 'user_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->index(['tokenable_type', 'tokenable_id', 'last_used_at'], 'personal_access_tokens_last_used_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_activity_index');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropIndex('shops_activity_index');
        });

        Schema::table('company_users', function (Blueprint $table) {
            $table->dropIndex(['level_id', 'user_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('personal_access_tokens_last_used_index');
        });
    }
};

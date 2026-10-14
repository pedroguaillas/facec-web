<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La factura recurrente se emite a una hora: `start_date`/`next_run_date`
     * (date) pasan a `start_at`/`next_run_at` (datetime). Las existentes
     * quedan a las 00:00.
     */
    public function up(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->renameColumn('start_date', 'start_at');
            $table->renameColumn('next_run_date', 'next_run_at');
        });

        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->dateTime('start_at')->change();
            $table->dateTime('next_run_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->date('start_at')->change();
            $table->date('next_run_at')->nullable()->change();
        });

        Schema::table('recurring_invoices', function (Blueprint $table) {
            $table->renameColumn('start_at', 'start_date');
            $table->renameColumn('next_run_at', 'next_run_date');
        });
    }
};

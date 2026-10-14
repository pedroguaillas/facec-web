<?php

use App\StaticClasses\CashRegisterStatuses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `uses_sessions`: la caja usa apertura/cierre (arqueo). Sin él, la caja
     * tiene una sola sesión siempre abierta. Backfill: true para las cajas que
     * ya cerraron alguna sesión (conservan su historial); a las demás que no
     * tengan sesión abierta se les abre una en 0 — el saldo de un cierre ya
     * regresó a la caja general, reabrir con él lo duplicaría.
     */
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->boolean('uses_sessions')->default(false)->after('type');
        });

        DB::table('cash_registers')
            ->whereExists(fn ($query) => $query->from('cash_register_sessions')
                ->whereColumn('cash_register_sessions.cash_register_id', 'cash_registers.id')
                ->where('cash_register_sessions.status', CashRegisterStatuses::CLOSED))
            ->update(['uses_sessions' => true]);

        DB::table('cash_registers')
            ->where('uses_sessions', false)
            ->whereNotExists(fn ($query) => $query->from('cash_register_sessions')
                ->whereColumn('cash_register_sessions.cash_register_id', 'cash_registers.id')
                ->where('cash_register_sessions.status', CashRegisterStatuses::OPEN))
            ->orderBy('id')
            ->each(function ($cashRegister) {
                DB::table('cash_register_sessions')->insert([
                    'branch_id' => $cashRegister->branch_id,
                    'cash_register_id' => $cashRegister->id,
                    'opened_by' => $cashRegister->responsible_id,
                    'opened_at' => now(),
                    'initial_balance' => 0,
                    'current_balance' => 0,
                    'status' => CashRegisterStatuses::OPEN,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('cash_registers')
                    ->where('id', $cashRegister->id)
                    ->update(['status' => CashRegisterStatuses::OPEN]);
            });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropColumn('uses_sessions');
        });
    }
};

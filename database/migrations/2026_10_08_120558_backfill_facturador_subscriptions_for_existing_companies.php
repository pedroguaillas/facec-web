<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Las empresas creadas antes de los planes no tienen ninguna suscripción y
     * el SubscriptionGate las trataría como vencidas. Se les asigna FACTURADOR
     * por 1 mes para que el admin renueve cada una (decisión de negocio,
     * 2026-10-08). Usa query builder y el
     * literal del plan para no depender de modelos/constantes que puedan cambiar.
     */
    public function up(): void
    {
        $today = now()->toDateString();
        $endsAt = now()->addMonth()->toDateString();
        $timestamp = now();

        $companyIds = DB::table('companies')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.company_id', 'companies.id');
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($companyIds->chunk(500) as $chunk) {
            DB::table('subscriptions')->insert($chunk->map(fn ($companyId) => [
                'company_id' => $companyId,
                'plan' => 'FACTURADOR',
                'started_at' => $today,
                'ended_at' => $endsAt,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->values()->all());
        }
    }

    /**
     * Reverse the migrations.
     *
     * No se revierte: no hay forma segura de distinguir las filas del backfill
     * de suscripciones FACTURADOR asignadas a mano después.
     */
    public function down(): void {}
};

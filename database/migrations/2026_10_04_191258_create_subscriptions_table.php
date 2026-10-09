<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Historial de planes por empresa (no un campo en `companies`): cada
     * cambio de plan cierra la fila activa (`ended_at`) y abre una nueva. El
     * plan vigente es la fila con started_at <= ended_at y hoy entre ambas.
     * Todo plan vence (también los pagos): al vencer sin renovar, la empresa
     * no puede emitir comprobantes.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('plan', 20); // App\StaticClasses\PlanTypes
            $table->date('started_at');
            $table->date('ended_at');
            $table->decimal('amount', 10, 2)->default(0); // monto pagado por este período
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

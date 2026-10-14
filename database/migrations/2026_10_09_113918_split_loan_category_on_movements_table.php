<?php

use App\StaticClasses\MovementTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * PRESTAMOS se divide por dirección: PRESTAMO_RECIBIDO (ingreso) y
     * PAGO_PRESTAMO (egreso).
     */
    public function up(): void
    {
        DB::table('movements')->where('category', 'PRESTAMOS')->where('type', MovementTypes::INCOME)
            ->update(['category' => 'PRESTAMO_RECIBIDO']);
        DB::table('movements')->where('category', 'PRESTAMOS')->where('type', MovementTypes::EXPENSE)
            ->update(['category' => 'PAGO_PRESTAMO']);
    }

    public function down(): void
    {
        DB::table('movements')->whereIn('category', ['PRESTAMO_RECIBIDO', 'PAGO_PRESTAMO'])
            ->update(['category' => 'PRESTAMOS']);
    }
};

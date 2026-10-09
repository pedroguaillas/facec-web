<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * El login exige correo verificado desde el registro público (prueba
     * gratuita). Los usuarios existentes los creó el admin, que responde por
     * ellos: se marcan como verificados para no bloquearlos.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('COALESCE(created_at, NOW())')]);
    }

    /**
     * Reverse the migrations.
     *
     * No se revierte: no hay forma de distinguir los verificados por este
     * backfill de los que verificaron su correo después.
     */
    public function down(): void {}
};

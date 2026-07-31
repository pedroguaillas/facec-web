<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Este timestamp coincide con la migración original de facec-api (Lumen/Laravel 12)
     * para que, al importar un dump de producción, Laravel reconozca la tabla como ya
     * creada en vez de intentar recrearla.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('user')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->unsignedBigInteger('user_type_id');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_type_id')->references('id')->on('user_types');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

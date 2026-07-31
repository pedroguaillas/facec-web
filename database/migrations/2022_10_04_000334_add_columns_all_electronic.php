<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddColumnsAllElectronic extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('send_mail')->default(false);
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('send_mail_set_purchase')->default(false);
            $table->boolean('send_mail_retention')->default(false);
        });
        Schema::table('referral_guides', function (Blueprint $table) {
            $table->boolean('send_mail')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}

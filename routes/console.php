<?php

use App\Models\Treasury\PaymentReceipt;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Facturas recurrentes: cada una se emite a su hora (`next_run_at`), por eso
// se revisa cada minuto. En producción lo corre el servicio `scheduler`
// (compose.prod.yaml, `schedule:work`).
Schedule::command('invoices:recurring')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Comprobantes de pago subidos y nunca vinculados a un movimiento (24h).
Schedule::command('model:prune', ['--model' => [PaymentReceipt::class]])
    ->hourly()
    ->onOneServer();

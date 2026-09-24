<?php

namespace Database\Seeders;

use App\Models\Denomination;
use App\StaticClasses\DenominationTypes;
use Illuminate\Database\Seeder;

class DenominationSeeder extends Seeder
{
    /**
     * Catálogo de monedas y billetes en circulación (USD Ecuador).
     */
    public function run(): void
    {
        $coins = [0.01, 0.05, 0.10, 0.25, 0.50, 1.00];
        $bills = [1.00, 5.00, 10.00, 20.00, 50.00, 100.00];

        foreach ($coins as $value) {
            Denomination::updateOrCreate(['value' => $value, 'type' => DenominationTypes::COIN]);
        }

        foreach ($bills as $value) {
            Denomination::updateOrCreate(['value' => $value, 'type' => DenominationTypes::BILL]);
        }
    }
}

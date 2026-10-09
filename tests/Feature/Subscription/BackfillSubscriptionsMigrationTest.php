<?php

use App\Models\Company;
use App\Models\Subscription;
use App\StaticClasses\PlanTypes;

function runBackfillSubscriptionsMigration(): void
{
    $migration = require database_path('migrations/2026_10_08_120558_backfill_facturador_subscriptions_for_existing_companies.php');
    $migration->up();
}

test('el backfill asigna FACTURADOR por un mes a empresas sin suscripción', function () {
    $companies = Company::factory()->withoutSubscription()->count(3)->create();

    runBackfillSubscriptionsMigration();

    foreach ($companies as $company) {
        $subscription = $company->currentSubscription();

        expect($subscription->plan)->toBe(PlanTypes::FACTURADOR)
            ->and($subscription->ended_at->toDateString())->toBe(now()->addMonth()->toDateString());
    }
});

test('el backfill no toca empresas que ya tienen suscripción', function () {
    $company = Company::factory()->create(); // arranca con FINANCIERO activo

    runBackfillSubscriptionsMigration();

    expect(Subscription::where('company_id', $company->id)->count())->toBe(1)
        ->and($company->currentSubscription()->plan)->toBe(PlanTypes::FINANCIERO);
});

test('el backfill es idempotente', function () {
    $company = Company::factory()->withoutSubscription()->create();

    runBackfillSubscriptionsMigration();
    runBackfillSubscriptionsMigration();

    expect(Subscription::where('company_id', $company->id)->count())->toBe(1);
});

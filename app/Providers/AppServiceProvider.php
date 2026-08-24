<?php

namespace App\Providers;

use App\Models\Company;
use App\Services\Order\OrderPdfService;
use App\Services\Shop\Retention\RetentionPdfService;
use App\Services\Shop\ShopLcPdfService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // OrderPdfService, RetentionPdfService y ShopLcPdfService type-hintean Company
        // esperando la company del usuario autenticado. Binding contextual: no debe
        // afectar el route-model-binding de Company (rompía admin.companies.update).
        foreach ([OrderPdfService::class, RetentionPdfService::class, ShopLcPdfService::class] as $service) {
            $this->app->when($service)
                ->needs(Company::class)
                ->give(fn () => Auth::user()?->company);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

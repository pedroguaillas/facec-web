<?php

use App\Http\Controllers\AccountsPayable\AccountPayableController;
use App\Http\Controllers\AccountsPayable\AccountPayablePaymentController;
use App\Http\Controllers\AccountsReceivable\AccountReceivableController;
use App\Http\Controllers\AccountsReceivable\AccountReceivablePaymentController;
use App\Http\Controllers\Auth\AuthenticationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Bank\BankAccountController;
use App\Http\Controllers\Bank\BankController;
use App\Http\Controllers\Carrier\CarrierController;
use App\Http\Controllers\CashRegister\CashRegisterController;
use App\Http\Controllers\CashRegister\CashRegisterSessionController;
use App\Http\Controllers\Company\AdminCompanyController;
use App\Http\Controllers\Customer\CustomerController;
use App\Http\Controllers\Customer\CustomerLookupController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Order\OrderLifecycleController;
use App\Http\Controllers\Product\ProductController;
use App\Http\Controllers\Product\ProductLookupController;
use App\Http\Controllers\Provider\ProviderController;
use App\Http\Controllers\Provider\ProviderLookupController;
use App\Http\Controllers\ReferralGuide\ReferralGuideController;
use App\Http\Controllers\ReferralGuide\ReferralGuideLifecycleController;
use App\Http\Controllers\Settings\BranchController as SettingsBranchController;
use App\Http\Controllers\Settings\CompanyController as SettingsCompanyController;
use App\Http\Controllers\Settings\EmisionPointController as SettingsEmisionPointController;
use App\Http\Controllers\Shop\RetentionController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\Shop\ShopLifecycleController;
use App\Http\Controllers\Treasury\DenominationController;
use App\Http\Controllers\Treasury\MovementController;
use App\Http\Controllers\User\UserLookupController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthenticationController::class, 'login']);

Route::middleware('throttle:6,1')->group(function () {
    Route::post('password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->name('password.reset');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthenticationController::class, 'logout']);
    Route::post('password/change', [PasswordController::class, 'update'])->name('password.change');
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth:sanctum', 'role:client'])->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::post('orders/lot', [OrderController::class, 'storeLot'])->name('orders.lot.store');
    Route::get('orders/{order}/convert-to-invoice', [OrderController::class, 'convertToInvoice'])->name('orders.convert-to-invoice');
    Route::get('orders/export/{yearMonth}', [OrderController::class, 'export'])
        ->where('yearMonth', '\d{4}-\d{2}')
        ->name('orders.export');
    Route::get('orders/{order}', [OrderController::class, 'edit'])->name('orders.edit');
    Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('orders/{order}/pdf', [OrderController::class, 'pdf'])->name('orders.pdf');
    Route::get('orders/{order}/printf', [OrderController::class, 'printf'])->name('orders.printf');
    Route::get('orders/{order}/process', [OrderLifecycleController::class, 'process'])->name('orders.process');
    Route::get('orders/{order}/cancel', [OrderLifecycleController::class, 'cancel'])->name('orders.cancel');
    Route::get('orders/{order}/mail', [OrderLifecycleController::class, 'mail'])->name('orders.mail');
    Route::get('orders/{order}/xml', [OrderLifecycleController::class, 'download'])->name('orders.xml');
    Route::post('orders/{order}/payments', [AccountReceivablePaymentController::class, 'store'])->name('orders.payments.store');

    Route::get('customers/lookup', [CustomerLookupController::class, 'index'])->name('customers.lookup');
    Route::get('products/lookup', [ProductLookupController::class, 'index'])->name('products.lookup');
    Route::get('providers/lookup', [ProviderLookupController::class, 'index'])->name('providers.lookup');
    Route::get('users/lookup', [UserLookupController::class, 'index'])->name('users.lookup');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
    Route::post('products/import', [ProductController::class, 'import'])->name('products.import');
    Route::get('products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
    Route::get('providers/create', [ProviderController::class, 'create'])->name('providers.create');
    Route::get('providers/resolve/{identification}', [ProviderController::class, 'resolve'])->name('providers.resolve');
    Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
    Route::get('providers/{provider}', [ProviderController::class, 'edit'])->name('providers.edit');
    Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
    Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
    Route::get('providers/{provider}/account-payable', [AccountPayableController::class, 'index'])->name('providers.account-payable');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::get('customers/resolve/{identification}', [CustomerController::class, 'resolve'])->name('customers.resolve');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::get('customers/{customer}/account-receivable', [AccountReceivableController::class, 'index'])->name('customers.account-receivable');

    Route::get('shops', [ShopController::class, 'index'])->name('shops.index');
    Route::get('shops/create', [ShopController::class, 'create'])->name('shops.create');
    Route::post('shops', [ShopController::class, 'store'])->name('shops.store');
    Route::get('shops/{shop}', [ShopController::class, 'edit'])->name('shops.edit');
    Route::put('shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
    Route::get('shops/{shop}/pdf', [ShopController::class, 'pdf'])->name('shops.pdf');
    Route::get('shops/{shop}/process', [ShopLifecycleController::class, 'process'])->name('shops.process');
    Route::get('shops/{shop}/cancel', [ShopLifecycleController::class, 'cancel'])->name('shops.cancel');
    Route::get('shops/{shop}/xml', [ShopLifecycleController::class, 'download'])->name('shops.xml');
    Route::post('shops/{shop}/payments', [AccountPayablePaymentController::class, 'store'])->name('shops.payments.store');
    Route::get('retentions/{id}/pdf', [RetentionController::class, 'pdf'])->name('retentions.pdf');
    Route::get('retentions/{shop}/process', [RetentionController::class, 'process'])->name('retentions.process');
    Route::get('retentions/{shop}/mail', [RetentionController::class, 'mail'])->name('retentions.mail');
    Route::get('retentions/{shop}/cancel', [RetentionController::class, 'cancel'])->name('retentions.cancel');
    Route::get('retentions/{shop}/xml', [RetentionController::class, 'download'])->name('retentions.xml');

    Route::get('referralguides', [ReferralGuideController::class, 'index'])->name('referralguides.index');
    Route::get('referralguides/create', [ReferralGuideController::class, 'create'])->name('referralguides.create');
    Route::post('referralguides', [ReferralGuideController::class, 'store'])->name('referralguides.store');
    Route::get('referralguides/{referralguide}', [ReferralGuideController::class, 'show'])->name('referralguides.show');
    Route::put('referralguides/{referralguide}', [ReferralGuideController::class, 'update'])->name('referralguides.update');
    Route::get('referralguides/{id}/pdf', [ReferralGuideController::class, 'pdf'])->name('referralguides.pdf');
    Route::get('referralguides/{referralguide}/process', [ReferralGuideLifecycleController::class, 'process'])->name('referralguides.process');
    Route::get('referralguides/{referralguide}/xml', [ReferralGuideLifecycleController::class, 'download'])->name('referralguides.xml');

    Route::get('carriers', [CarrierController::class, 'index'])->name('carriers.index');
    Route::get('carriers/create', [CarrierController::class, 'create'])->name('carriers.create');
    Route::get('carriers/resolve/{identification}', [CarrierController::class, 'resolve'])->name('carriers.resolve');
    Route::post('carriers', [CarrierController::class, 'store'])->name('carriers.store');
    Route::get('carriers/{carrier}', [CarrierController::class, 'edit'])->name('carriers.edit');
    Route::put('carriers/{carrier}', [CarrierController::class, 'update'])->name('carriers.update');
    Route::delete('carriers/{carrier}', [CarrierController::class, 'destroy'])->name('carriers.destroy');

    Route::get('companies', [SettingsCompanyController::class, 'edit'])->name('companies.edit');
    Route::put('companies', [SettingsCompanyController::class, 'update'])->name('companies.update');
    Route::get('companies/download-cert', [SettingsCompanyController::class, 'downloadCert'])->name('companies.download-cert');

    Route::get('settings', [SettingsCompanyController::class, 'edit'])->name('settings.company.edit');
    Route::put('settings', [SettingsCompanyController::class, 'update'])->name('settings.company.update');

    Route::get('branches', [SettingsBranchController::class, 'index'])->name('branches.index');
    Route::post('branches', [SettingsBranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [SettingsBranchController::class, 'update'])->name('branches.update');
    Route::get('branches/{branch}/points', [SettingsEmisionPointController::class, 'index'])->name('branches.points.index');

    Route::get('points/branch/{branch}', [SettingsEmisionPointController::class, 'index'])->name('points.branch');
    Route::post('points', [SettingsEmisionPointController::class, 'store'])->name('points.store');
    Route::post('points/store', [SettingsEmisionPointController::class, 'store'])->name('points.store.alias');
    Route::put('points/{emisionPoint}', [SettingsEmisionPointController::class, 'update'])->name('points.update');
    Route::put('points/update/{emisionPoint}', [SettingsEmisionPointController::class, 'update'])->name('points.update.alias');

    Route::get('settings/branches', [SettingsBranchController::class, 'index'])->name('settings.branches.index');
    Route::post('settings/branches', [SettingsBranchController::class, 'store'])->name('settings.branches.store');
    Route::put('settings/branches/{branch}', [SettingsBranchController::class, 'update'])->name('settings.branches.update');

    Route::get('settings/branches/{branch}/points', [SettingsEmisionPointController::class, 'index'])->name('settings.points.index');
    Route::post('settings/points', [SettingsEmisionPointController::class, 'store'])->name('settings.points.store');
    Route::put('settings/points/{emisionPoint}', [SettingsEmisionPointController::class, 'update'])->name('settings.points.update');

    Route::get('banks', [BankController::class, 'index'])->name('banks.index');
    Route::post('banks', [BankController::class, 'store'])->name('banks.store');
    Route::get('banks/{bank}', [BankController::class, 'edit'])->name('banks.edit');
    Route::put('banks/{bank}', [BankController::class, 'update'])->name('banks.update');
    Route::delete('banks/{bank}', [BankController::class, 'destroy'])->name('banks.destroy');

    Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::get('bank-accounts/{bankAccount}', [BankAccountController::class, 'edit'])->name('bank-accounts.edit');
    Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

    Route::get('cash-registers', [CashRegisterController::class, 'index'])->name('cash-registers.index');
    Route::post('cash-registers', [CashRegisterController::class, 'store'])->name('cash-registers.store');
    Route::get('cash-registers/{cashRegister}', [CashRegisterController::class, 'edit'])->name('cash-registers.edit');
    Route::put('cash-registers/{cashRegister}', [CashRegisterController::class, 'update'])->name('cash-registers.update');
    Route::get('cash-registers/{cashRegister}/sessions', [CashRegisterSessionController::class, 'index'])->name('cash-registers.sessions.index');
    Route::post('cash-registers/{cashRegister}/open', [CashRegisterSessionController::class, 'open'])->name('cash-registers.open');
    Route::post('cash-registers/{cashRegister}/close', [CashRegisterSessionController::class, 'close'])->name('cash-registers.close');
    Route::get('cash-register-sessions/{cashRegisterSession}', [CashRegisterSessionController::class, 'show'])->name('cash-register-sessions.show');

    Route::get('denominations', [DenominationController::class, 'index'])->name('denominations.index');

    Route::get('movements', [MovementController::class, 'index'])->name('movements.index');
    Route::get('movements/{movement}', [MovementController::class, 'show'])->name('movements.show');
    Route::post('movements/deposit', [MovementController::class, 'deposit'])->name('movements.deposit');
    Route::post('movements/withdraw', [MovementController::class, 'withdraw'])->name('movements.withdraw');
    Route::post('movements/transfer', [MovementController::class, 'transfer'])->name('movements.transfer');
    Route::post('movements/other', [MovementController::class, 'other'])->name('movements.other');
    Route::post('movements/{movement}/complete', [MovementController::class, 'complete'])->name('movements.complete');
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('companies', [AdminCompanyController::class, 'index'])->name('admin.companies.index');
    Route::post('companies', [AdminCompanyController::class, 'store'])->name('admin.companies.store');
    Route::get('companies/sri/{identification}', [AdminCompanyController::class, 'resolve'])->name('admin.companies.resolve');
    Route::get('users/check-availability', [AdminCompanyController::class, 'checkAvailability'])->name('admin.users.check-availability');
    Route::get('companies/{id}/edit', [AdminCompanyController::class, 'edit'])->name('admin.companies.edit');
    Route::put('companies/{company}', [AdminCompanyController::class, 'update'])->name('admin.companies.update');
});

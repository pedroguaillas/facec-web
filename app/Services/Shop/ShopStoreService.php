<?php

namespace App\Services\Shop;

use App\Jobs\ProcessVoucherJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmisionPoint;
use App\Models\Shop\Shop;
use App\Models\Treasury\Movement;
use App\Models\VoucherType;
use App\Services\AccountsPayable\AccountsPayableLedgerService;
use App\Services\Product\PresentationLineResolver;
use App\Services\Product\ProductUnitService;
use App\Services\Product\VoucherInventoryService;
use App\Services\Shop\Retention\RetentionTotalsCalculator;
use App\Services\SubscriptionGate;
use App\Services\Treasury\MovementService;
use App\Services\VoucherSequenceService;
use App\StaticClasses\MovementPartyRegistry;
use App\StaticClasses\MovementTransactionTypes;
use App\StaticClasses\MovementTypes;
use App\StaticClasses\PaymentTypes;
use App\StaticClasses\VoucherTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ShopStoreService
{
    private Company $company;

    private Branch $branch;

    public function __construct()
    {
        $this->company = Auth::user()->company;
        $this->branch = $this->company->branches()->orderBy('created_at')->first();
    }

    public function createShop(array $data): Shop
    {
        $shop = DB::transaction(function () use ($data) {
            $emisionPoint = EmisionPoint::findOrFail($data['point_id']);

            $inputs = $this->prepareShopData($data, $emisionPoint);
            $shop = $this->branch->shops()->create($inputs);

            if ($shop->movement_id) {
                Movement::whereKey($shop->movement_id)
                    ->update(['origin_type' => MovementPartyRegistry::SHOP, 'origin_id' => $shop->id]);
            }

            $this->createShopItems($shop, $data['products'] ?? []);
            $this->createRetentionItems($shop, $data);

            app(AccountsPayableLedgerService::class)->registerFromShop($shop);

            return $shop;
        });

        $this->sendVouchers($shop, $data);

        return $shop;
    }

    protected function prepareShopData(array $data, EmisionPoint $emisionPoint): array
    {
        $except = ['taxes', 'pay_methods', 'app_retention', 'send', 'point_id', 'source_type', 'source_id', 'receipt_id', 'receipt_number', 'without_source'];
        $inputs = collect($data)->except($except)->toArray();

        $voucherType = VoucherType::findOrFail($data['voucher_type_id']);

        // Serie para liquidación en compra: único tipo de Compras con secuencial propio.
        if ($voucherType->requires_sequence) {
            $inputs['serie'] = app(VoucherSequenceService::class)
                ->generateSerie($emisionPoint, $voucherType->slug);
            $inputs['state'] = 'CREADO';
        }

        // Serie para retención
        $taxes = $data['taxes'] ?? [];
        if (! empty($data['app_retention']) && is_array($taxes) && count($taxes) > 0) {
            $inputs['serie_retencion'] = app(VoucherSequenceService::class)
                ->generateSerie($emisionPoint, VoucherTypes::RETENCION);
            $inputs['state_retencion'] = 'CREADO';
        }

        if (in_array($inputs['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true)) {
            app(SubscriptionGate::class)->ensureFinancieroAccess($this->company);
        }

        if (in_array($inputs['payment_type'] ?? null, [PaymentTypes::CREDITO, PaymentTypes::ANTICIPO], true) && ! empty($inputs['due_date'])) {
            $shopDate = Carbon::parse($inputs['date']);
            $dueDate = Carbon::parse($inputs['due_date']);
            $inputs['expiration_days'] = $shopDate->diffInDays($dueDate);
            $inputs['due_date'] = $dueDate;
        }

        if (($inputs['payment_type'] ?? null) === PaymentTypes::CONTADO && ! empty($data['source_type']) && ! empty($data['source_id'])
            && app(SubscriptionGate::class)->hasFinancieroAccess($this->company)) {
            $retencion = 0.0;

            if (! empty($data['app_retention']) && ! empty($data['taxes'])) {
                $recalculated = (new RetentionTotalsCalculator)->recalculate($data['taxes']);
                $retencion = array_sum(array_column($recalculated, 'value'));
            }

            $movement = app(MovementService::class)->create([
                'branch_id' => $this->branch->id,
                'date' => $inputs['date'],
                'type' => MovementTypes::EXPENSE,
                'transaction_type' => MovementTransactionTypes::PURCHASE_PAYMENT,
                'source_type' => $data['source_type'],
                'source_id' => $data['source_id'],
                'receipt_id' => $data['receipt_id'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
                'amount' => (float) $inputs['total'] - $retencion,
                'description' => "Pago compra {$inputs['serie']}",
                'user_id' => Auth::id(),
            ]);

            $inputs['movement_id'] = $movement->id;
        }

        return $inputs;
    }

    protected function createShopItems(Shop $shop, array $products): void
    {
        if (empty($products)) {
            return;
        }

        // Líneas con `unit` registran la moto nueva y pasan a apuntar a ella.
        $products = app(ProductUnitService::class)->createFromPurchaseLines($products, (bool) $this->company->inventory);
        $presentations = app(PresentationLineResolver::class)->resolve($products);

        $items = array_map(fn ($p, $presentation) => [
            'product_id' => $p['product_id'],
            'presentation_id' => $presentation['presentation_id'],
            'conversion_factor' => $presentation['conversion_factor'],
            'quantity' => $p['quantity'],
            'price' => $p['price'],
            'discount' => $p['discount'],
            'iva' => $p['iva'],
        ], $products, $presentations);

        $shop->shopitems()->createMany($items);

        app(VoucherInventoryService::class)->syncShop($shop, isNew: true);
    }

    protected function createRetentionItems(Shop $shop, array $data): void
    {
        $taxes = $data['taxes'] ?? [];

        if (empty($data['app_retention']) || empty($taxes)) {
            return;
        }

        $items = array_map(fn ($t) => [
            'code' => $t['code'],
            'tax_code' => $t['tax_code'],
            'base' => $t['base'],
            'porcentage' => $t['porcentage'],
            'value' => $t['value'],
        ], $taxes);

        $items = (new RetentionTotalsCalculator)->recalculate($items, $shop->id);

        $shop->shopretentionitems()->createMany($items);
    }

    protected function sendVouchers(Shop $shop, array $data): void
    {
        $send = ! empty($data['send']);

        if ($send && $shop->voucherType->slug === VoucherTypes::COMPRA_LIQUIDACION_COMPRA) {
            ProcessVoucherJob::dispatch('shop', $shop->id, $this->company->id)->afterCommit();
        }

        if ($send && ! empty($data['app_retention']) && ! empty($data['taxes'])) {
            ProcessVoucherJob::dispatch('shop_retention', $shop->id, $this->company->id)->afterCommit();
        }
    }
}

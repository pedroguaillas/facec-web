<?php

namespace App\Services\Order;

use App\Models\AccountsReceivable\AccountReceivableMovement;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Models\Tax;
use App\Services\AccountsReceivable\AccountsReceivableLedgerService;
use App\StaticClasses\VoucherStates;
use App\StaticClasses\VoucherTypes;
use App\Xml\ReceivedRetentionParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Retención que el cliente emite sobre una factura de venta. Siempre se
 * registra después de la venta (a mano o importando el XML del cliente): no
 * cambia el XML de la factura, solo reduce lo que el cliente debe pagar. Si la
 * venta tiene cartera (crédito/anticipo) se abona como RETENCION.
 */
class OrderRetentionService
{
    private const TAX_TYPES = [
        ReceivedRetentionParser::RENTA => 'renta',
        ReceivedRetentionParser::IVA => 'iva',
    ];

    public function __construct(
        private AccountsReceivableLedgerService $ledgerService,
        private ReceivedRetentionParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {serie, date (Y-m-d), authorization, items: [{code, tax_code, base, porcentage, value}]}
     *
     * @throws RuntimeException
     */
    public function register(Order $order, array $data): Order
    {
        $this->ensureCanReceiveRetention($order);

        $items = $this->normalizeItems($data['items']);
        $this->ensureValidTaxCodes($items);

        if ($data['date'] < Carbon::parse($order->date)->format('Y-m-d')) {
            throw new RuntimeException('La fecha de la retención no puede ser anterior a la fecha de la factura.');
        }

        $total = round(array_sum(array_column($items, 'value')), 2);

        if ($total <= 0) {
            throw new RuntimeException('El valor retenido debe ser mayor a cero.');
        }

        if ($total > round((float) $order->total, 2)) {
            throw new RuntimeException('El valor retenido no puede ser mayor al total de la factura.');
        }

        return DB::transaction(function () use ($order, $data, $items, $total) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->serie_retencion !== null) {
                throw new RuntimeException("La factura ya tiene registrada la retención {$order->serie_retencion}.");
            }

            $hasReceivable = $this->hasReceivable($order);

            if ($hasReceivable && $total > $this->receivableBalance($order)) {
                throw new RuntimeException('El valor retenido es mayor al saldo pendiente de la factura.');
            }

            $order->retentionItems()->createMany($items);

            $order->update([
                'serie_retencion' => $data['serie'],
                'date_retention' => $data['date'],
                'authorization_retention' => $data['authorization'],
                'iva_retention' => $this->sumByCode($items, ReceivedRetentionParser::IVA),
                'rent_retention' => $this->sumByCode($items, ReceivedRetentionParser::RENTA),
            ]);

            if ($hasReceivable) {
                $this->ledgerService->registerRetention($order, $total, $data['date']);
            }

            return $order->fresh(['retentionItems']);
        });
    }

    /**
     * Lee el XML de la retención que envió el cliente y registra sus datos
     * (el archivo no se guarda). Valida que la
     * haya emitido el cliente de la venta, a nombre de la empresa, y que
     * sustente esta factura (en v2 se toman solo las líneas de esta factura).
     *
     * @throws RuntimeException
     */
    public function import(Order $order, string $xmlContent): Order
    {
        $parsed = $this->parser->parse($xmlContent);
        $company = $this->company();

        if ($parsed['subject_identification'] !== $company->ruc) {
            throw new RuntimeException("La retención está emitida a {$parsed['subject_identification']}, no al RUC de la empresa ({$company->ruc}).");
        }

        $customerIdentification = (string) Customer::whereKey($order->customer_id)->value('identication');

        if ($parsed['issuer_ruc'] !== $customerIdentification && substr($parsed['issuer_ruc'], 0, 10) !== $customerIdentification) {
            throw new RuntimeException("La retención la emitió {$parsed['issuer_name']} ({$parsed['issuer_ruc']}), que no es el cliente de la factura.");
        }

        $orderDocument = str_replace('-', '', (string) $order->serie);
        $items = array_values(array_filter(
            $parsed['items'],
            fn (array $item) => $item['doc_sustento'] === null || $item['doc_sustento'] === $orderDocument,
        ));

        if ($items === []) {
            throw new RuntimeException("La retención no corresponde a la factura {$order->serie}.");
        }

        return $this->register($order, [
            'serie' => $parsed['serie'],
            'date' => $parsed['date'],
            'authorization' => $parsed['authorization'],
            'items' => $items,
        ]);
    }

    /**
     * Elimina la retención registrada (p. ej. se cargó por error) y revierte
     * su abono en la cartera.
     *
     * @throws RuntimeException
     */
    public function remove(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->serie_retencion === null) {
                throw new RuntimeException('La factura no tiene una retención registrada.');
            }

            $total = $order->retentionTotal();

            if ($this->hasReceivable($order)) {
                $this->ledgerService->reverseRetention($order, $total);
            }

            $order->retentionItems()->delete();
            $order->update([
                'serie_retencion' => null,
                'date_retention' => null,
                'authorization_retention' => null,
                'iva_retention' => 0,
                'rent_retention' => 0,
            ]);

            return $order->fresh();
        });
    }

    private function ensureCanReceiveRetention(Order $order): void
    {
        if ($order->voucherType->slug !== VoucherTypes::VENTA_FACTURA) {
            throw new RuntimeException('Solo se puede registrar retención sobre una factura.');
        }

        if ($order->state === VoucherStates::CANCELED) {
            throw new RuntimeException('No se puede registrar retención sobre una factura anulada.');
        }

        if ($order->serie_retencion !== null) {
            throw new RuntimeException("La factura ya tiene registrada la retención {$order->serie_retencion}.");
        }
    }

    /**
     * @param  array<int, array{code: int|string, tax_code: string, base: float|string, porcentage: float|string, value: float|string}>  $items
     * @return array<int, array{code: int, tax_code: string, base: float, porcentage: float, value: float}>
     */
    private function normalizeItems(array $items): array
    {
        return array_map(fn (array $item) => [
            'code' => (int) $item['code'],
            'tax_code' => (string) $item['tax_code'],
            'base' => round((float) $item['base'], 2),
            'porcentage' => round((float) $item['porcentage'], 2),
            'value' => round((float) $item['value'], 2),
        ], array_values($items));
    }

    /**
     * @param  array<int, array{code: int, tax_code: string}>  $items
     */
    private function ensureValidTaxCodes(array $items): void
    {
        $taxTypes = Tax::whereIn('code', array_column($items, 'tax_code'))->pluck('type', 'code');

        foreach ($items as $item) {
            $expectedType = self::TAX_TYPES[$item['code']] ?? null;

            if ($expectedType === null) {
                throw new RuntimeException("Impuesto retenido no soportado (código {$item['code']}): solo renta (1) e IVA (2).");
            }

            if (($taxTypes[$item['tax_code']] ?? null) !== $expectedType) {
                throw new RuntimeException("El código de retención {$item['tax_code']} no existe para {$expectedType}.");
            }
        }
    }

    /**
     * @param  array<int, array{code: int, value: float}>  $items
     */
    private function sumByCode(array $items, int $code): float
    {
        return round(array_sum(array_column(array_filter($items, fn (array $item) => $item['code'] === $code), 'value')), 2);
    }

    private function hasReceivable(Order $order): bool
    {
        return AccountReceivableMovement::where('order_id', $order->id)->exists();
    }

    /**
     * Saldo de la factura en la cartera (cargo − abonos, NC y retenciones).
     */
    private function receivableBalance(Order $order): float
    {
        return round((float) AccountReceivableMovement::where('order_id', $order->id)->sum(DB::raw('debe - haber')), 2);
    }

    private function company(): Company
    {
        return Auth::user()->company;
    }
}

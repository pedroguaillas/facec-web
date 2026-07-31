<?php

namespace App\Services\Order;

use App\Models\Order\Lot;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Services\VoucherLifecycleService;
use App\StaticClasses\VoucherStates;
use App\Xml\CreditNoteBuilder;
use App\Xml\InvoiceBuilder;
use App\Xml\InvoiceLotBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class OrderLifecycleService
{
    public function __construct(
        private VoucherLifecycleService $lifecycle,
        private OrderSriService $sri,
    ) {}

    public function process(Order $order, bool $send = true): void
    {
        $company = Auth::user()->company;

        if (! $company->active_voucher) {
            return;
        }

        $state = $order->state;

        if (in_array($state, [VoucherStates::SAVED, VoucherStates::RETURNED, VoucherStates::REJECTED])) {

            if ($state === VoucherStates::RETURNED && $order->extra_detail === 'CLAVE ACCESO REGISTRADA.') {
                $this->sri->authorize($order);

                return;
            }

            $this->lifecycle->saveAndSign(
                company: $company,
                model: $order,
                xml: $this->buildXml($order->id, $company),
                authorizationField: 'authorization',
                onSigned: fn () => $send ? $this->sri->send($order) : null,
            );
        } elseif ($state === VoucherStates::SIGNED) {
            $this->sri->send($order);
        } elseif (in_array($state, [VoucherStates::SENDED, VoucherStates::RECEIVED, VoucherStates::IN_PROCESS])) {
            $this->sri->authorize($order);
        }
    }

    public function cancel(Order $order): mixed
    {
        return $this->sri->cancel($order);
    }

    private function buildXml($id, $company)
    {
        $order = Order::join('customers AS c', 'c.id', 'customer_id')
            ->select('c.identication', 'c.name', 'c.address', 'c.type_identification', 'orders.*')
            ->where('orders.id', $id)
            ->first();

        $tz = config('app.timezone');

        if (! Carbon::createFromFormat('Y-m-d', $order->date, $tz)->isToday()) {
            $order->update(['date' => Carbon::today($tz)->format('Y-m-d')]);
        }

        $order_items = OrderItem::join('products AS p', 'p.id', 'product_id')
            ->join('iva_taxes AS it', 'it.code', 'order_items.iva')
            ->selectRaw('quantity,price,discount,order_items.ice AS valice,p.code AS codeproduct,aux_cod,name,it.code AS iva,it.percentage,p.ice AS codice')
            ->where('order_id', $order->id)
            ->get();

        return match ($order->voucher_type) {
            1 => (new InvoiceBuilder($company, $order, $order_items))->build(),
            4 => (new CreditNoteBuilder($company, $order, $order_items))->build(),
        };
    }

    public function processLot(Lot $lot)
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $autorizacion = $dom->createElement('lote');
        $autorizacion->setAttribute('version', '1.0.0');
        $dom->appendChild($autorizacion);

        $company = Auth::user()->company;
        $lot->authorization = (new InvoiceLotBuilder($company, $lot, []))->getAccessKey();
        $ruc = substr($lot->authorization, 10, 13);
        $path = 'xmls'.DIRECTORY_SEPARATOR.$ruc.DIRECTORY_SEPARATOR.$lot->authorization.'.xml';
        $lot->xml = $path;
        $lot->save();

        $estado = $dom->createElement('claveAcceso', $lot->authorization);
        $autorizacion->appendChild($estado);
        $orders = Order::where('lot_id', $lot->id)->get();

        $auth = $dom->createElement('ruc', $ruc);
        $autorizacion->appendChild($auth);

        $elementocomprobantes = $dom->createElement('comprobantes');
        $autorizacion->appendChild($elementocomprobantes);

        foreach ($orders as $order) {
            $elementocomprobante = $dom->createElement('comprobante');
            $elementocomprobantes->appendChild($elementocomprobante);
            $elementocomprobante->appendChild($dom->createCDATASection(Storage::get($order->xml)));
        }

        Storage::put($path, $dom->saveXML());
    }
}

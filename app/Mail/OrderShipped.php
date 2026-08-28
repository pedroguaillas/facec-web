<?php

namespace App\Mail;

use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Services\Order\OrderPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderShipped extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The order instance.
     *
     * @var Order
     */
    protected $order;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // No se usa Auth::user() aquí: este mail se dispara tanto desde el request HTTP
        // (reenvío manual) como desde el Job de colas (sin usuario autenticado) — la
        // compañía y el remitente para "responder a" se resuelven desde el propio Order.
        $company = Branch::find($this->order->branch_id)?->company;
        $companyUser = $company
            ? CompanyUser::where('level_id', $company->id)->first()
            : null;
        $replyTo = $companyUser?->user?->email ?? config('mail.from.address');

        // Se instancia OrderPdfService directamente (en vez de app(OrderPdfService::class))
        // porque su binding contextual en AppServiceProvider resuelve Company vía
        // Auth::user()?->company — null en contexto de Job (sin usuario autenticado),
        // lo que rompería el constructor con Company no-nullable.
        (new OrderPdfService($company))->savePdf($this->order->id);

        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->replyTo($replyTo)
            ->subject(($this->order->voucher_type == 1 ? 'FACTURA ' : 'NOTA DE CRÉDITO ').$this->order->serie.' de '.$company->company)
            ->view('mail', ['title' => 'FACTURA '.$this->order->serie, 'customer' => Customer::find($this->order->customer_id)->name])
            ->attachFromStorage(
                str_replace('.xml', '.pdf', $this->order->xml),
                ($this->order->voucher_type == 1 ? 'FAC-' : 'NC-').$this->order->serie.'.pdf',
                [
                    'mime' => 'application/pdf',
                ]
            )
            ->attachFromStorage(
                $this->order->xml,
                ($this->order->voucher_type == 1 ? 'FAC-' : 'NC-').$this->order->serie.'.xml',
                [
                    'mime' => 'application/xml',
                ]
            );
    }
}

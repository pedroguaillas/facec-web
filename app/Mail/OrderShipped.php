<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order\Order;
use App\Services\Order\OrderPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

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
        $auth = Auth::user();
        $level = $auth->companyusers->first();
        $company = Company::find($level->level_id);

        // (new OrderController())->generatePdf($this->order->id);
        app(OrderPdfService::class)->savePdf($this->order->id);

        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->replyTo($auth->email)
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

<?php

namespace App\Mail;

use App\Models\Branch;
use App\Models\CompanyUser;
use App\Models\Provider;
use App\Models\Shop\Shop;
use App\Services\Shop\ShopLcPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ShopLcShipped extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(protected Shop $shop) {}

    public function build()
    {
        // No se usa Auth::user() aquí: este mail se dispara tanto desde el request HTTP
        // como desde el Job de colas (sin usuario autenticado) — la compañía y el
        // remitente para "responder a" se resuelven desde el propio Shop.
        $company = Branch::find($this->shop->branch_id)?->company;
        $companyUser = $company
            ? CompanyUser::where('level_id', $company->id)->first()
            : null;
        $replyTo = $companyUser?->user?->email ?? config('mail.from.address');

        // Se instancia ShopLcPdfService directamente (no app()) porque su binding
        // contextual resuelve Company vía Auth::user()?->company — null en contexto
        // de Job (sin usuario autenticado).
        (new ShopLcPdfService($company))->savePdf($this->shop->id);

        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->replyTo($replyTo)
            ->subject('LIQUIDACIÓN EN COMPRA '.$this->shop->serie.' de '.$company->company)
            ->view('mail', ['title' => 'LIQUIDACIÓN EN COMPRA '.$this->shop->serie, 'customer' => Provider::find($this->shop->provider_id)->name])
            ->attachFromStorage(
                str_replace('.xml', '.pdf', $this->shop->xml),
                'LC-'.$this->shop->serie.'.pdf',
                [
                    'mime' => 'application/pdf',
                ]
            )
            ->attachFromStorage(
                $this->shop->xml,
                'LC-'.$this->shop->serie.'.xml',
                [
                    'mime' => 'application/xml',
                ]
            );
    }
}

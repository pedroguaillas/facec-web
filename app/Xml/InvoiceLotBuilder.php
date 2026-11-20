<?php

namespace App\Xml;

use App\Xml\Concerns\HasItemTaxes;
use Carbon\Carbon;

class InvoiceLotBuilder extends BaseVoucherBuilder
{
    use HasItemTaxes;

    public function __construct(
        $company,
        private $lot,
        private $items,
    ) {
        parent::__construct($company);
    }

    protected function voucherTypeCode(): string
    {
        return '01';
    }

    protected function accessKeyDate(): \DateTime
    {
        return Carbon::now();
    }

    protected function serieRaw(): string
    {
        return $this->lot->serie;
    }

    public function build(): string
    {
        return 'Hola';
    }

    public function getAccessKey(): string
    {
        return AccessKey::generate($this->company, $this->accessKeyDate(), $this->voucherTypeCode(), $this->serieRaw());
    }
}

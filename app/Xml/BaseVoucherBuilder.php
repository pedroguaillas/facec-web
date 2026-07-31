<?php

namespace App\Xml;

use App\Models\Branch;
use App\Models\Company;

abstract class BaseVoucherBuilder
{
    public function __construct(protected readonly Company $company) {}

    /**
     * Genera el XML completo del comprobante.
     */
    abstract public function build(): string;

    /**
     * Código de tipo de comprobante con padding (ej: "01", "04", "06", "07").
     */
    abstract protected function voucherTypeCode(): string;

    /**
     * Fecha usada para construir la clave de acceso.
     */
    abstract protected function accessKeyDate(): \DateTime;

    /**
     * Serie del comprobante sin formatear (con guiones, tal como viene del modelo).
     */
    abstract protected function serieRaw(): string;

    /**
     * Genera el bloque <infoTributaria> común a todos los comprobantes.
     */
    protected function infoTributaria(): string
    {
        $branch = Branch::where('company_id', $this->company->id)->orderBy('created_at')->first();
        $voucherType = $this->voucherTypeCode();
        $serie = str_replace('-', '', $this->serieRaw());

        $keyaccess = $this->accessKeyDate()->format('dmY')
            .$voucherType
            .$this->company->ruc
            .$this->company->enviroment_type
            .$serie
            .'123456781';

        $checkDigit = $this->generaDigitoModulo11($keyaccess);

        $string = '<infoTributaria>';
        $string .= "<ambiente>{$this->company->enviroment_type}</ambiente>";
        $string .= '<tipoEmision>1</tipoEmision>';
        $string .= '<razonSocial>'.str_replace('&', '', $this->company->company).'</razonSocial>';
        $string .= $branch->name !== null
            ? '<nombreComercial>'.str_replace('&', '', $branch->name).'</nombreComercial>'
            : null;
        $string .= "<ruc>{$this->company->ruc}</ruc>";
        $string .= "<claveAcceso>{$keyaccess}{$checkDigit}</claveAcceso>";
        $string .= "<codDoc>{$voucherType}</codDoc>";
        $string .= '<estab>'.substr($serie, 0, 3).'</estab>';
        $string .= '<ptoEmi>'.substr($serie, 3, 3).'</ptoEmi>';
        $string .= '<secuencial>'.substr($serie, 6, 9).'</secuencial>';
        $string .= "<dirMatriz>{$branch->address}</dirMatriz>";
        $string .= (int) $this->company->retention_agent === 1 ? '<agenteRetencion>1</agenteRetencion>' : null;
        $string .= (int) $this->company->rimpe === 1 ? '<contribuyenteRimpe>CONTRIBUYENTE RÉGIMEN RIMPE</contribuyenteRimpe>' : null;
        $string .= (int) $this->company->rimpe === 2 ? '<contribuyenteRimpe>CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE</contribuyenteRimpe>' : null;
        $string .= '</infoTributaria>';

        return $string;
    }

    /**
     * Algoritmo módulo 11 para el dígito verificador de la clave de acceso SRI.
     */
    final protected function generaDigitoModulo11(string $cadena): int
    {
        $cadena = trim($cadena);
        $baseMultiplicador = 7;
        $aux = (new \SplFixedArray(strlen($cadena)))->toArray();
        $multiplicador = 2;
        $total = 0;

        for ($i = count($aux) - 1; $i >= 0; $i--) {
            $aux[$i] = (int) substr($cadena, $i, 1);
            $aux[$i] *= $multiplicador;
            if (++$multiplicador > $baseMultiplicador) {
                $multiplicador = 2;
            }
            $total += $aux[$i];
        }

        $mod = 11 - ($total % 11);
        $verificador = ($total === 0 || $total === 1) ? 0 : ($mod === 11 ? 0 : $mod);

        return $verificador === 10 ? 1 : $verificador;
    }
}

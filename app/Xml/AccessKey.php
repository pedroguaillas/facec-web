<?php

namespace App\Xml;

use App\Models\Company;
use DateTimeInterface;

/**
 * Clave de acceso SRI (49 dígitos): fecha(ddmmaaaa) + codDoc + RUC + ambiente
 * + serie(estab+ptoEmi+secuencial) + código numérico + tipoEmisión + dígito
 * verificador módulo 11.
 */
class AccessKey
{
    /** Código numérico (8) + tipo de emisión normal (1). */
    private const NUMERIC_CODE_AND_EMISSION = '123456781';

    public static function generate(Company $company, DateTimeInterface $date, string $voucherTypeCode, string $serie): string
    {
        $key = $date->format('dmY')
            .$voucherTypeCode
            .$company->ruc
            .$company->enviroment_type
            .str_replace('-', '', $serie)
            .self::NUMERIC_CODE_AND_EMISSION;

        return $key.self::checkDigit($key);
    }

    /**
     * Algoritmo módulo 11 para el dígito verificador de la clave de acceso SRI.
     */
    public static function checkDigit(string $cadena): int
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

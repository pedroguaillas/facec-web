<?php

namespace App\StaticClasses;

/**
 * Clasificación de un movimiento de tesorería para la vista "Movimientos"
 * (en qué entra/se va el dinero). Los movimientos internos (saldo inicial,
 * apertura/cierre de caja, depósito, retiro, transferencia) no llevan
 * categoría: no son ingreso ni gasto de la empresa.
 */
class MovementCategories
{
    const SALES = 'VENTAS';

    const OTHER_INCOME = 'OTROS_INGRESOS';

    const PURCHASES = 'COMPRAS';

    const SALARIES = 'SUELDOS';

    /** Aportes patronales/personales al IESS, aparte de sueldos. */
    const SOCIAL_SECURITY = 'IESS';

    const RENT = 'ARRIENDO';

    /** Luz, agua, internet, teléfono. */
    const UTILITIES = 'SERVICIOS_BASICOS';

    /** Combustible, fletes. */
    const TRANSPORT = 'TRANSPORTE';

    const ADVERTISING = 'PUBLICIDAD';

    /** Costos de transferencias, comisiones de cobro con tarjeta. */
    const BANK_FEES = 'COMISIONES_BANCARIAS';

    /** SRI, municipio, patentes. */
    const TAXES = 'IMPUESTOS';

    const OTHER_EXPENSES = 'OTROS_GASTOS';

    const OWNER_CONTRIBUTION = 'APORTE_DUENO';

    const OWNER_WITHDRAWAL = 'RETIRO_DUENO';

    const LOAN_RECEIVED = 'PRESTAMO_RECIBIDO';

    const LOAN_PAYMENT = 'PAGO_PRESTAMO';

    /** Etiqueta del resumen para movimientos sin categoría. */
    const UNCLASSIFIED = 'SIN_CLASIFICAR';

    /** Grupo: ingreso/gasto del negocio (cuenta para lo que dejó el negocio). */
    const GROUP_BUSINESS = 'NEGOCIO';

    /** Grupo: plata del dueño y préstamos — no es ganancia ni pérdida. */
    const GROUP_FINANCING = 'FINANCIAMIENTO';

    /**
     * Categorías válidas según el `type` del movimiento (MovementTypes).
     *
     * @var array<string, list<string>>
     */
    const BY_TYPE = [
        MovementTypes::INCOME => [
            self::SALES,
            self::OTHER_INCOME,
            self::OWNER_CONTRIBUTION,
            self::LOAN_RECEIVED,
        ],
        MovementTypes::EXPENSE => [
            self::PURCHASES,
            self::SALARIES,
            self::SOCIAL_SECURITY,
            self::RENT,
            self::UTILITIES,
            self::TRANSPORT,
            self::ADVERTISING,
            self::BANK_FEES,
            self::TAXES,
            self::OTHER_EXPENSES,
            self::OWNER_WITHDRAWAL,
            self::LOAN_PAYMENT,
        ],
    ];

    const ALL = [
        ...self::BY_TYPE[MovementTypes::INCOME],
        ...self::BY_TYPE[MovementTypes::EXPENSE],
    ];

    /** Categorías de financiamiento; el resto (y sin categoría) es del negocio. */
    const FINANCING = [
        self::OWNER_CONTRIBUTION,
        self::OWNER_WITHDRAWAL,
        self::LOAN_RECEIVED,
        self::LOAN_PAYMENT,
    ];

    /**
     * Categoría automática por `transaction_type` al crear el movimiento.
     *
     * @var array<string, string>
     */
    const AUTOMATIC = [
        MovementTransactionTypes::SALE_COLLECTION => self::SALES,
        MovementTransactionTypes::CUSTOMER_ADVANCE => self::SALES,
        MovementTransactionTypes::PURCHASE_PAYMENT => self::PURCHASES,
        MovementTransactionTypes::PROVIDER_ADVANCE => self::PURCHASES,
    ];

    /**
     * @return list<string>
     */
    public static function forType(?string $type): array
    {
        return self::BY_TYPE[$type] ?? [];
    }

    public static function automaticFor(string $transactionType): ?string
    {
        return self::AUTOMATIC[$transactionType] ?? null;
    }

    public static function groupOf(?string $category): string
    {
        return in_array($category, self::FINANCING, true) ? self::GROUP_FINANCING : self::GROUP_BUSINESS;
    }

    /**
     * Catálogo para el front: categoría, tipo de movimiento y grupo.
     *
     * @return list<array{category: string, type: string, group: string}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::BY_TYPE as $type => $categories) {
            foreach ($categories as $category) {
                $catalog[] = ['category' => $category, 'type' => $type, 'group' => self::groupOf($category)];
            }
        }

        return $catalog;
    }
}

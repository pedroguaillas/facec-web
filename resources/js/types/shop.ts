export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    meta: PaginationMeta;
};

export type ShopListProvider = {
    id: number;
    name: string;
};

export type ShopListItem = {
    id: number;
    serie: string;
    voucher_type: number;
    date: string;
    total: number;
    state: string;
    extra_detail: string | null;
    send_mail_set_purchase: boolean;
    provider: ShopListProvider;
};

export type ShopFilters = {
    search: string | null;
    date: string | null;
};

export type EmissionPoint = {
    branch_id: number;
    store: string;
    id: number;
    point: string;
    settlementonpurchase: number;
    recognition: string | null;
};

export type Shop = {
    id: number;
    branch_id: number;
    date: string;
    description: string | null;
    sub_total: number;
    serie: string;
    provider_id: number;
    voucher_type: number;
    state: string;
    total: number;
    base0: number;
    base5: number;
    base12: number;
    base15: number;
    iva: number;
    iva5: number;
    iva15: number;
    discount: number;
    ice: number;
    authorization: string | null;
    extra_detail: string | null;
    send_mail_set_purchase: boolean;
    expiration_days: number | null;
    doc_realeted: string | null;
    paid: number | null;
};

export type ShopItemProduct = {
    id: number;
    code: string;
    name: string;
};

export type ShopItem = {
    id: number;
    product_id: number;
    quantity: number;
    price: number;
    discount: number;
    iva: number;
    product: ShopItemProduct;
};

export type ProductOption = {
    id: number;
    code: string;
    name: string;
    price1: number;
    stock: number | null;
};

export type ProviderOption = {
    id: number;
    name: string;
    identication: string;
};

export type ShopProductLine = {
    key: string;
    id: number | null;
    product_id: number | null;
    code: string;
    name: string;
    quantity: number | string;
    price: number | string;
    discount: number | string;
    iva: number;
    stock: number | null;
};

export type ShopTotalsBreakdown = {
    base0: number;
    base5: number;
    base12: number;
    base15: number;
    iva: number;
    iva5: number;
    iva15: number;
    subTotal: number;
    total: number;
};

export const SHOP_VOUCHER_TYPE = {
    Invoice: 1,
    SalesNote: 2,
    Liquidation: 3,
    DebitNote: 5,
} as const;

export const SHOP_VOUCHER_PREFIX: Record<number, string> = {
    1: 'FAC',
    2: 'N/V',
    3: 'L/C',
    5: 'N/D',
};

export const SHOP_VOUCHER_LABEL: Record<number, string> = {
    1: 'Factura',
    2: 'Nota de Venta',
    3: 'Liquidación de Compra',
    5: 'Nota de Débito',
};

export const SHOP_IVA_PERCENTAGES = [0, 5, 12, 15] as const;

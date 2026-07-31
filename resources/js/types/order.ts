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

export type OrderListCustomer = {
    id: number;
    name: string;
    email: string | null;
};

export type OrderListItem = {
    id: number;
    serie: string;
    voucher_type: number;
    date: string;
    total: number;
    state: string;
    extra_detail: string | null;
    send_mail: boolean;
    customer: OrderListCustomer;
};

export type OrderFilters = {
    search: string | null;
    date: string | null;
};

export type EmissionPoint = {
    branch_id: number;
    store: string;
    id: number;
    point: string;
    invoice: number;
    creditnote: number;
    recognition: string | null;
};

export type MethodOfPayment = {
    id: number;
    code: string;
    description: string;
};

export type Order = {
    id: number;
    branch_id: number;
    date: string;
    description: string | null;
    sub_total: number;
    serie: string;
    customer_id: number;
    voucher_type: number;
    state: string;
    total: number;
    base0: number;
    base5: number;
    base8: number;
    base12: number;
    base15: number;
    iva: number;
    iva5: number;
    iva8: number;
    iva15: number;
    ice: number;
    discount: number;
    pay_method: number | null;
    guia: string | null;
    doc_realeted: string | null;
    expiration_days: number | null;
    date_order: string | null;
    serie_order: string | null;
    reason: string | null;
    authorization: string | null;
    extra_detail: string | null;
    send_mail: boolean;
};

export type OrderItemProduct = {
    id: number;
    code: string;
    name: string;
};

export type OrderItem = {
    id: number;
    product_id: number;
    quantity: number;
    price: number;
    discount: number;
    iva: number;
    ice: number;
    total_iva: number;
    product: OrderItemProduct;
};

export type OrderAdditional = {
    id: number;
    name: string;
    description: string;
};

export type ProductOption = {
    id: number;
    code: string;
    name: string;
    price: number;
    stock: number | null;
    iva: number;
    ice: string | null;
};

export type CustomerOption = {
    id: number;
    name: string;
    email: string | null;
    identication: string;
};

export const CONSUMIDOR_FINAL_ID = '9999999999999';
export const CONSUMIDOR_FINAL_LIMIT = 50;

export const VOUCHER_TYPE = {
    Invoice: 1,
    CreditNote: 4,
} as const;

export const VOUCHER_PREFIX: Record<number, string> = {
    1: 'FAC',
    4: 'N/C',
    5: 'N/D',
};

export type IvaTax = {
    code: number;
    percentage: number;
};

export type ProductLine = {
    key: string;
    id: number | null;
    product_id: number | null;
    code: string;
    name: string;
    quantity: number | string;
    price: number | string;
    discount: number | string;
    iva: number;
    ice: number | string;
    stock: number | null;
};

export type AdditionalLine = {
    key: string;
    id: number | null;
    name: string;
    description: string;
};

export type OrderTotalsBreakdown = {
    base0: number;
    base5: number;
    base8: number;
    base12: number;
    base15: number;
    iva5: number;
    iva8: number;
    iva12: number;
    iva15: number;
    ice: number;
    subTotal: number;
    total: number;
};

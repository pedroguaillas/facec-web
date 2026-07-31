export type { Paginated, PaginationLink, PaginationMeta } from './order';

export type ProductListItem = {
    id: number;
    code: string;
    type_product: number;
    name: string;
    price1: number;
    iva_code: number;
    percentage: number;
    stock: number | null;
};

export type ProductFilters = {
    search: string | null;
};

export type IvaTax = {
    code: number;
    percentage: number;
};

export type IceCatalog = {
    code: number;
    description: string;
};

export type SriCategory = {
    code: string;
    description: string;
    type: string;
};

export type Product = {
    id: number;
    code: string;
    type_product: number;
    name: string;
    price1: number;
    iva: number;
    ice: number | null;
    aux_cod: string | null;
    stock: number | null;
};

export const PRODUCT_TYPE_OPTIONS = [
    { value: 1, label: 'Producto' },
    { value: 2, label: 'Servicio' },
] as const;

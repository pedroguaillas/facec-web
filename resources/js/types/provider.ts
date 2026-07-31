export type { Paginated, PaginationLink, PaginationMeta } from './order';

export type ProviderListItem = {
    id: number;
    type_identification: string;
    identication: string;
    name: string;
    address: string;
    phone: string | null;
    email: string | null;
};

export type ProviderFilters = {
    search: string | null;
};

export type Provider = {
    id: number;
    type_identification: string;
    identication: string;
    name: string;
    address: string;
    phone: string | null;
    email: string | null;
};

export const IDENTIFICATION_TYPE_OPTIONS = [
    { value: 'cédula', label: 'Cédula' },
    { value: 'ruc', label: 'RUC' },
    { value: 'pasaporte', label: 'Pasaporte' },
] as const;

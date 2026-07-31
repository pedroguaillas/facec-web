export type { Paginated, PaginationLink, PaginationMeta } from './order';

export type CarrierListItem = {
    id: number;
    type_identification: string;
    identication: string;
    name: string;
    license_plate: string | null;
    email: string | null;
};

export type CarrierFilters = {
    search: string | null;
};

export type Carrier = {
    id: number;
    type_identification: string;
    identication: string;
    name: string;
    license_plate: string | null;
    email: string | null;
};

export const CARRIER_IDENTIFICATION_TYPE_OPTIONS = [
    { value: 'cédula', label: 'Cédula' },
    { value: 'ruc', label: 'RUC' },
] as const;

export type MonthlyAmount = {
    name: string;
    period: string;
    total: number;
};

export type DashboardCounts = {
    orders: number;
    shops: number;
    customers: number;
    providers: number;
};

export type RecentOrder = {
    id: number;
    serie: string;
    voucher_type: number;
    date: string;
    total: number;
    state: string;
    customer: { name: string };
};

export type DashboardProps = {
    active: boolean;
    expired: string | null;
    certExpiration: string | null;
    income: MonthlyAmount[];
    expenses: MonthlyAmount[];
    counts: DashboardCounts;
    newThisMonth: {
        customers: number;
        providers: number;
    };
    recentOrders: RecentOrder[];
};

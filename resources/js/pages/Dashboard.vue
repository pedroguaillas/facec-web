<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ShoppingCart, Truck, Users, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import BalanceHero from '@/components/dashboard/BalanceHero.vue';
import IncomeExpenseChart from '@/components/dashboard/IncomeExpenseChart.vue';
import RecentOrdersList from '@/components/dashboard/RecentOrdersList.vue';
import StatCard from '@/components/dashboard/StatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { DashboardProps } from '@/types/dashboard';

const props = defineProps<DashboardProps>();

const certExpirationSoon = computed(() => {
    if (!props.certExpiration) {
        return false;
    }

    const days =
        (new Date(props.certExpiration).getTime() - Date.now()) / 86_400_000;

    return days >= 0 && days <= 30;
});

function trendPercent(current: number, previous: number): number | null {
    if (previous === 0) {
        return null;
    }

    return ((current - previous) / Math.abs(previous)) * 100;
}

const ordersTrend = computed(() =>
    trendPercent(
        props.income.at(-1)?.total ?? 0,
        props.income.at(-2)?.total ?? 0,
    ),
);
const shopsTrend = computed(() =>
    trendPercent(
        props.expenses.at(-1)?.total ?? 0,
        props.expenses.at(-2)?.total ?? 0,
    ),
);
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="flex flex-col gap-6">
            <div
                v-if="!active"
                class="rounded-lg border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger"
            >
                La emisión de comprobantes está desactivada para esta compañía.
            </div>
            <div
                v-else-if="certExpirationSoon"
                class="rounded-lg border border-warning/20 bg-warning/10 px-4 py-3 text-sm text-warning"
            >
                El certificado de firma vence el
                {{ new Date(certExpiration!).toLocaleDateString('es-EC') }}.
            </div>

            <BalanceHero :income="income" :expenses="expenses" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Ventas (histórico)"
                    :value="counts.orders"
                    :icon="ShoppingCart"
                    :trend-percent="ordersTrend"
                />
                <StatCard
                    label="Compras (histórico)"
                    :value="counts.shops"
                    :icon="Wallet"
                    :trend-percent="shopsTrend"
                    trend-invert
                />
                <StatCard
                    label="Clientes"
                    :value="counts.customers"
                    :icon="Users"
                    :trend-label="
                        newThisMonth.customers > 0
                            ? `+${newThisMonth.customers} este mes`
                            : undefined
                    "
                />
                <StatCard
                    label="Proveedores"
                    :value="counts.providers"
                    :icon="Truck"
                    :trend-label="
                        newThisMonth.providers > 0
                            ? `+${newThisMonth.providers} este mes`
                            : undefined
                    "
                />
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <IncomeExpenseChart
                    class="lg:col-span-2"
                    :income="income"
                    :expenses="expenses"
                />
                <RecentOrdersList :orders="recentOrders" />
            </div>
        </div>
    </AppLayout>
</template>

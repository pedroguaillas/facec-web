<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Receipt } from '@lucide/vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import type { RecentOrder } from '@/types/dashboard';
import { VOUCHER_PREFIX } from '@/types/order';

defineProps<{
    orders: RecentOrder[];
}>();

function formatMoney(value: number): string {
    return new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('es-EC', {
        day: '2-digit',
        month: 'short',
    });
}
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4">
        <h2 class="mb-3 text-sm font-medium text-foreground">Últimas ventas</h2>

        <div
            v-if="orders.length === 0"
            class="py-8 text-center text-sm text-muted-foreground"
        >
            No hay ventas registradas todavía.
        </div>

        <ul v-else class="flex flex-col divide-y divide-border">
            <li v-for="order in orders" :key="order.id">
                <Link
                    :href="`/orders/${order.id}`"
                    class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0 hover:opacity-75"
                >
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Receipt class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-foreground">
                            {{ order.customer.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ VOUCHER_PREFIX[order.voucher_type] ?? 'FAC' }}-{{
                                order.serie
                            }}
                            · {{ formatDate(order.date) }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p
                            class="text-sm font-medium text-foreground tabular-nums"
                        >
                            {{ formatMoney(order.total) }}
                        </p>
                        <OrderStatusBadge :state="order.state" />
                    </div>
                </Link>
            </li>
        </ul>
    </div>
</template>

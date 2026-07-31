<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Mail, Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import OrderActionsMenu from '@/components/orders/OrderActionsMenu.vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrderFilters, OrderListItem, Paginated } from '@/types/order';
import { VOUCHER_PREFIX } from '@/types/order';

const props = defineProps<{
    orders: Paginated<OrderListItem>;
    filters: OrderFilters;
}>();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/orders',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

function formatMoney(value: number): string {
    return `$${Number(value).toFixed(2)}`;
}
</script>

<template>
    <Head title="Ventas" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-foreground">
                        Ventas
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Lista de todas las ventas
                    </p>
                </div>
                <Button as-child>
                    <Link href="/orders/create">
                        <Plus />
                        Nuevo
                    </Link>
                </Button>
            </div>

            <div class="flex justify-end">
                <div class="relative w-full max-w-xs">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Buscar ventas ..."
                        class="pl-8"
                    />
                </div>
            </div>

            <div
                class="overflow-x-auto rounded-lg border border-border bg-card"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>F. Emisión</TableHead>
                            <TableHead>Documento</TableHead>
                            <TableHead>Cliente</TableHead>
                            <TableHead class="text-center">Estado</TableHead>
                            <TableHead class="text-right">Total</TableHead>
                            <TableHead class="text-center">
                                <Mail class="mx-auto size-4" />
                            </TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="order in orders.data" :key="order.id">
                            <TableCell>{{ order.date }}</TableCell>
                            <TableCell>
                                <Link
                                    :href="`/orders/${order.id}`"
                                    class="text-primary hover:underline"
                                >
                                    {{ VOUCHER_PREFIX[order.voucher_type] }}
                                    {{ order.serie }}
                                </Link>
                            </TableCell>
                            <TableCell class="uppercase">{{
                                order.customer?.name
                            }}</TableCell>
                            <TableCell class="text-center">
                                <OrderStatusBadge
                                    :state="order.state"
                                    :extra-detail="order.extra_detail"
                                />
                            </TableCell>
                            <TableCell class="text-right">{{
                                formatMoney(order.total)
                            }}</TableCell>
                            <TableCell class="text-center">
                                <CheckCircle2
                                    v-if="order.send_mail"
                                    class="mx-auto size-4 text-success"
                                />
                            </TableCell>
                            <TableCell class="text-right">
                                <OrderActionsMenu :order="order" />
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="orders.data.length === 0">
                            <TableCell
                                colspan="7"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No hay ventas registradas.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="orders.meta.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Mostrando {{ orders.meta.from ?? 0 }}–{{
                        orders.meta.to ?? 0
                    }}
                    de
                    {{ orders.meta.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="(link, index) in orders.links"
                        :key="index"
                    >
                        <Button
                            v-if="link.url"
                            :variant="link.active ? 'default' : 'outline'"
                            size="sm"
                            as-child
                        >
                            <Link :href="link.url" preserve-scroll>
                                <span v-html="link.label" />
                            </Link>
                        </Button>
                        <Button v-else variant="outline" size="sm" disabled>
                            <span v-html="link.label" />
                        </Button>
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

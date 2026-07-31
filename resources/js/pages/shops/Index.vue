<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, Mail, Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import OrderStatusBadge from '@/components/orders/OrderStatusBadge.vue';
import ShopActionsMenu from '@/components/shops/ShopActionsMenu.vue';
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
import type { Paginated, ShopFilters, ShopListItem } from '@/types/shop';
import { SHOP_VOUCHER_PREFIX } from '@/types/shop';

const props = defineProps<{
    shops: Paginated<ShopListItem>;
    filters: ShopFilters;
}>();

const search = ref(props.filters.search ?? '');
const date = ref(props.filters.date ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch([search, date], () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/shops',
            {
                search: search.value || undefined,
                date: date.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

function formatMoney(value: number): string {
    return `$${Number(value).toFixed(2)}`;
}
</script>

<template>
    <Head title="Compras" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-foreground">
                        Compras
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Lista de todas las compras
                    </p>
                </div>
                <Button as-child>
                    <Link href="/shops/create">
                        <Plus />
                        Nuevo
                    </Link>
                </Button>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <Input v-model="date" type="date" class="w-auto" />
                <div class="relative w-full max-w-xs">
                    <Search
                        class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Buscar compras ..."
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
                            <TableHead>Razón Social</TableHead>
                            <TableHead class="text-center">Estado</TableHead>
                            <TableHead class="text-right">Total</TableHead>
                            <TableHead class="text-center">
                                <Mail class="mx-auto size-4" />
                            </TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="shop in shops.data" :key="shop.id">
                            <TableCell>{{ shop.date }}</TableCell>
                            <TableCell>
                                <Link
                                    :href="`/shops/${shop.id}`"
                                    class="text-primary hover:underline"
                                >
                                    {{ SHOP_VOUCHER_PREFIX[shop.voucher_type] }}
                                    {{ shop.serie }}
                                </Link>
                            </TableCell>
                            <TableCell class="uppercase">{{
                                shop.provider?.name
                            }}</TableCell>
                            <TableCell class="text-center">
                                <OrderStatusBadge
                                    :state="shop.state"
                                    :extra-detail="shop.extra_detail"
                                />
                            </TableCell>
                            <TableCell class="text-right">{{
                                formatMoney(shop.total)
                            }}</TableCell>
                            <TableCell class="text-center">
                                <CheckCircle2
                                    v-if="shop.send_mail_set_purchase"
                                    class="mx-auto size-4 text-success"
                                />
                            </TableCell>
                            <TableCell class="text-right">
                                <ShopActionsMenu :shop="shop" />
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="shops.data.length === 0">
                            <TableCell
                                colspan="7"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No hay compras registradas.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="shops.meta.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Mostrando {{ shops.meta.from ?? 0 }}–{{
                        shops.meta.to ?? 0
                    }}
                    de
                    {{ shops.meta.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="(link, index) in shops.links"
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

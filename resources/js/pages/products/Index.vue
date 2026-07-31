<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import type {
    Paginated,
    ProductFilters,
    ProductListItem,
} from '@/types/product';

const props = defineProps<{
    products: Paginated<ProductListItem>;
    filters: ProductFilters;
}>();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/products',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const deleteTarget = ref<ProductListItem | null>(null);
const processing = ref(false);

function confirmDelete() {
    if (!deleteTarget.value) {
        return;
    }

    processing.value = true;
    router.delete(`/products/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            processing.value = false;
            deleteTarget.value = null;
        },
    });
}

function formatMoney(value: number): string {
    return `$${Number(value).toFixed(2)}`;
}
</script>

<template>
    <Head title="Productos o servicios" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-foreground">
                        Productos o servicios
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Lista de todos los productos o servicios
                    </p>
                </div>
                <Button as-child>
                    <Link href="/products/create">
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
                        placeholder="Buscar productos ..."
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
                            <TableHead>Código</TableHead>
                            <TableHead>Producto / Servicio</TableHead>
                            <TableHead class="text-right">Precio</TableHead>
                            <TableHead class="text-right">IVA</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="product in products.data"
                            :key="product.id"
                        >
                            <TableCell>{{ product.code }}</TableCell>
                            <TableCell>{{ product.name }}</TableCell>
                            <TableCell class="text-right tabular-nums">
                                {{ formatMoney(product.price1) }}
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                {{ product.percentage }}%
                            </TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Editar"
                                        as-child
                                    >
                                        <Link :href="`/products/${product.id}`">
                                            <Pencil />
                                        </Link>
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive"
                                        aria-label="Eliminar"
                                        @click="deleteTarget = product"
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="products.data.length === 0">
                            <TableCell
                                colspan="5"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No hay productos registrados.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="products.meta.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Mostrando {{ products.meta.from ?? 0 }}–{{
                        products.meta.to ?? 0
                    }}
                    de
                    {{ products.meta.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="(link, index) in products.links"
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

        <Dialog
            :open="deleteTarget !== null"
            @update:open="
                (value) => {
                    if (!value) deleteTarget = null;
                }
            "
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >¿Estás seguro de querer eliminar este
                        producto?</DialogTitle
                    >
                    <DialogDescription>
                        Esta acción no se puede deshacer y eliminará el producto
                        de forma permanente.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="processing"
                        @click="deleteTarget = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        @click="confirmDelete"
                    >
                        Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>

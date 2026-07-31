<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
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
import type {
    Paginated,
    ProviderFilters,
    ProviderListItem,
} from '@/types/provider';

const props = defineProps<{
    providers: Paginated<ProviderListItem>;
    filters: ProviderFilters;
}>();

const search = ref(props.filters.search ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        router.get(
            '/providers',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});
</script>

<template>
    <Head title="Proveedores" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold text-foreground">
                        Proveedores
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Lista de todos los proveedores
                    </p>
                </div>
                <Button as-child>
                    <Link href="/providers/create">
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
                        placeholder="Buscar proveedores ..."
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
                            <TableHead>Identificación</TableHead>
                            <TableHead>Nombre</TableHead>
                            <TableHead>Dirección</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="provider in providers.data"
                            :key="provider.id"
                        >
                            <TableCell>{{ provider.identication }}</TableCell>
                            <TableCell>{{ provider.name }}</TableCell>
                            <TableCell>{{ provider.address }}</TableCell>
                            <TableCell class="text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label="Editar"
                                        as-child
                                    >
                                        <Link
                                            :href="`/providers/${provider.id}`"
                                        >
                                            <Pencil />
                                        </Link>
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="providers.data.length === 0">
                            <TableCell
                                colspan="4"
                                class="py-8 text-center text-muted-foreground"
                            >
                                No hay proveedores registrados.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="providers.meta.last_page > 1"
                class="flex flex-wrap items-center justify-between gap-2"
            >
                <p class="text-sm text-muted-foreground">
                    Mostrando {{ providers.meta.from ?? 0 }}–{{
                        providers.meta.to ?? 0
                    }}
                    de
                    {{ providers.meta.total }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <template
                        v-for="(link, index) in providers.links"
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

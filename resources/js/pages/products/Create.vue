<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { IceCatalog, IvaTax, SriCategory } from '@/types/product';

defineProps<{
    ivaTaxes: IvaTax[];
    iceCataloges: IceCatalog[];
    sriCategories: SriCategory[];
    transport: boolean;
}>();
</script>

<template>
    <Head title="Nuevo producto" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Producto</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar un nuevo producto
                </p>
            </div>

            <ProductForm
                mode="create"
                :iva-taxes="ivaTaxes"
                :ice-cataloges="iceCataloges"
                :sri-categories="sriCategories"
                :transport="transport"
            />
        </div>
    </AppLayout>
</template>

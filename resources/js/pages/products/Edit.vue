<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProductForm from '@/components/products/ProductForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { IceCatalog, IvaTax, Product, SriCategory } from '@/types/product';

defineProps<{
    product: Product;
    ivaTaxes: IvaTax[];
    iceCataloges: IceCatalog[];
    sriCategories: SriCategory[];
    transport: boolean;
}>();
</script>

<template>
    <Head title="Editar producto" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Producto {{ product.code }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar producto</p>
            </div>

            <ProductForm
                mode="edit"
                :product="product"
                :iva-taxes="ivaTaxes"
                :ice-cataloges="iceCataloges"
                :sri-categories="sriCategories"
                :transport="transport"
            />
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import ShopForm from '@/components/shops/ShopForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    EmissionPoint,
    ProviderOption,
    Shop,
    ShopItem,
} from '@/types/shop';
import { SHOP_VOUCHER_PREFIX } from '@/types/shop';

const props = defineProps<{
    shop: Shop;
    shop_items: ShopItem[];
    provider: ProviderOption;
    points: EmissionPoint[];
}>();

const title = computed(
    () => `${SHOP_VOUCHER_PREFIX[props.shop.voucher_type]} ${props.shop.serie}`,
);
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Compra {{ title }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar compra</p>
            </div>

            <ShopForm
                mode="edit"
                :shop="shop"
                :shop-items="shop_items"
                :provider="provider"
                :points="points"
            />
        </div>
    </AppLayout>
</template>

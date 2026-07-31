<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ShopForm from '@/components/shops/ShopForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { EmissionPoint } from '@/types/shop';

defineProps<{
    points: EmissionPoint[];
}>();
</script>

<template>
    <Head title="Nueva compra" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Compra</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar una nueva compra
                </p>
            </div>

            <ShopForm mode="create" :points="points" />
        </div>
    </AppLayout>
</template>

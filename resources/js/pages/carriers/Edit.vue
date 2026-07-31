<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CarrierForm from '@/components/carriers/CarrierForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Carrier } from '@/types/carrier';

defineProps<{
    carrier: Carrier;
}>();
</script>

<template>
    <Head title="Editar transportista" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Transportista {{ carrier.name }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar transportista</p>
            </div>

            <CarrierForm mode="edit" :carrier="carrier" />
        </div>
    </AppLayout>
</template>

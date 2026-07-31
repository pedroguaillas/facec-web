<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProviderForm from '@/components/providers/ProviderForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Provider } from '@/types/provider';

defineProps<{
    provider: Provider;
}>();
</script>

<template>
    <Head title="Editar proveedor" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Proveedor {{ provider.name }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar proveedor</p>
            </div>

            <ProviderForm mode="edit" :provider="provider" />
        </div>
    </AppLayout>
</template>

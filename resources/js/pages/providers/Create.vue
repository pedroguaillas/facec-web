<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProviderForm from '@/components/providers/ProviderForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="Nuevo proveedor" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Proveedor</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar un nuevo proveedor
                </p>
            </div>

            <ProviderForm mode="create" />
        </div>
    </AppLayout>
</template>

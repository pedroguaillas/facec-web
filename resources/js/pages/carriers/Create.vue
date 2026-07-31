<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CarrierForm from '@/components/carriers/CarrierForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="Nuevo transportista" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Transportista</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar un nuevo transportista
                </p>
            </div>

            <CarrierForm mode="create" />
        </div>
    </AppLayout>
</template>

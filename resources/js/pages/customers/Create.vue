<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerForm from '@/components/customers/CustomerForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head title="Nuevo cliente" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Cliente</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar un nuevo cliente
                </p>
            </div>

            <CustomerForm mode="create" />
        </div>
    </AppLayout>
</template>

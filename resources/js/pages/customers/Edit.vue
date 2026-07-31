<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CustomerForm from '@/components/customers/CustomerForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Customer } from '@/types/customer';

const props = defineProps<{
    customer: Customer;
}>();
</script>

<template>
    <Head title="Editar cliente" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Cliente {{ props.customer.name }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar cliente</p>
            </div>

            <CustomerForm mode="edit" :customer="props.customer" />
        </div>
    </AppLayout>
</template>

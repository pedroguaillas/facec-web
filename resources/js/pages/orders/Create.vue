<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import OrderForm from '@/components/orders/OrderForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { EmissionPoint, IvaTax, MethodOfPayment } from '@/types/order';

defineProps<{
    points: EmissionPoint[];
    methodOfPayments: MethodOfPayment[];
    ivaTaxes: IvaTax[];
    tourism: boolean;
}>();
</script>

<template>
    <Head title="Nuevo documento" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Documento</h1>
                <p class="text-sm text-muted-foreground">
                    Registrar un nuevo documento
                </p>
            </div>

            <OrderForm
                mode="create"
                :points="points"
                :method-of-payments="methodOfPayments"
                :iva-taxes="ivaTaxes"
            />
        </div>
    </AppLayout>
</template>

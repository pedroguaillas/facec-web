<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import OrderForm from '@/components/orders/OrderForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    CustomerOption,
    EmissionPoint,
    IvaTax,
    MethodOfPayment,
    Order,
    OrderAdditional,
    OrderItem,
} from '@/types/order';
import { VOUCHER_PREFIX } from '@/types/order';

const props = defineProps<{
    order: Order;
    order_items: OrderItem[];
    order_aditionals: OrderAdditional[];
    customers: CustomerOption[];
    points: EmissionPoint[];
    methodOfPayments: MethodOfPayment[];
    ivaTaxes: IvaTax[];
}>();

const title = computed(
    () => `${VOUCHER_PREFIX[props.order.voucher_type]} ${props.order.serie}`,
);
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <div class="flex flex-col gap-4">
            <div>
                <h1 class="text-xl font-semibold text-foreground">
                    Documento {{ title }}
                </h1>
                <p class="text-sm text-muted-foreground">Editar documento</p>
            </div>

            <OrderForm
                mode="edit"
                :order="order"
                :order-items="order_items"
                :order-additionals="order_aditionals"
                :customer="customers[0] ?? null"
                :points="points"
                :method-of-payments="methodOfPayments"
                :iva-taxes="ivaTaxes"
            />
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import AdditionalInfo from '@/components/orders/AdditionalInfo.vue';
import GeneralInformation from '@/components/orders/GeneralInformation.vue';
import OrderTotals from '@/components/orders/OrderTotals.vue';
import ProductLines from '@/components/orders/ProductLines.vue';
import { Separator } from '@/components/ui/separator';
import type {
    AdditionalLine,
    CustomerOption,
    EmissionPoint,
    IvaTax,
    MethodOfPayment,
    Order,
    OrderAdditional,
    OrderItem,
    ProductLine,
    ProductOption,
} from '@/types/order';
import {
    CONSUMIDOR_FINAL_ID,
    CONSUMIDOR_FINAL_LIMIT,
    VOUCHER_TYPE,
} from '@/types/order';

const props = defineProps<{
    mode: 'create' | 'edit';
    points: EmissionPoint[];
    methodOfPayments: MethodOfPayment[];
    ivaTaxes: IvaTax[];
    order?: Order;
    orderItems?: OrderItem[];
    orderAdditionals?: OrderAdditional[];
    customer?: CustomerOption | null;
}>();

const ivaPercentByCode = new Map(
    props.ivaTaxes.map((tax) => [tax.code, tax.percentage]),
);
const defaultIvaCode =
    props.ivaTaxes.find((tax) => tax.percentage === 15)?.code ??
    props.ivaTaxes[0]?.code ??
    0;

function percentageForCode(code: number): number {
    return ivaPercentByCode.get(code) ?? 0;
}

let keyCounter = 0;
function nextKey(): string {
    keyCounter += 1;

    return `line-${keyCounter}`;
}

function emptyProductLine(): ProductLine {
    return {
        key: nextKey(),
        id: null,
        product_id: null,
        code: '',
        name: '',
        quantity: 1,
        price: 0,
        discount: 0,
        iva: defaultIvaCode,
        ice: 0,
        stock: null,
    };
}

function normalizeIvaCode(code: number): number {
    return ivaPercentByCode.has(code) ? code : defaultIvaCode;
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function serieForPoint(
    point: EmissionPoint | undefined,
    voucherType: number,
): string {
    if (!point) {
        return 'Seleccione un punto de emisión';
    }

    const seq =
        voucherType === VOUCHER_TYPE.CreditNote
            ? point.creditnote
            : point.invoice;

    return `${point.store}-${point.point}-${String(seq).padStart(9, '0')}`;
}

const defaultPoint = computed<EmissionPoint | undefined>(() => {
    if (props.mode === 'edit') {
        return undefined;
    }

    return props.points[0];
});

const selectedPointId = ref<number | null>(defaultPoint.value?.id ?? null);
const selectedCustomer = ref<CustomerOption | null>(props.customer ?? null);
const taxBreakdown = ref(false);
const showIce = ref(
    (props.orderItems ?? []).some((item) => Number(item.ice) > 0),
);

const initialProducts: ProductLine[] = props.orderItems
    ? props.orderItems.map((item) => ({
          key: nextKey(),
          id: item.id,
          product_id: item.product_id,
          code: item.product?.code ?? '',
          name: item.product?.name ?? '',
          quantity: item.quantity,
          price: item.price,
          discount: item.discount,
          iva: normalizeIvaCode(Number(item.iva)),
          ice: item.ice,
          stock: null,
      }))
    : [emptyProductLine()];

const initialAdditionals: AdditionalLine[] = (props.orderAdditionals ?? []).map(
    (item) => ({
        key: nextKey(),
        id: item.id,
        name: item.name,
        description: item.description,
    }),
);

const form = useForm({
    voucher_type: props.order?.voucher_type ?? VOUCHER_TYPE.Invoice,
    customer_id: props.order?.customer_id ?? props.customer?.id ?? null,
    guia: props.order?.guia ?? '',
    date_order: props.order?.date_order ?? '',
    serie_order: props.order?.serie_order ?? '',
    reason: props.order?.reason ?? '',
    pay_method:
        props.order?.pay_method ??
        Number(props.methodOfPayments[0]?.code ?? 20),
    description: props.order?.description ?? '',
    discount: props.order?.discount ?? 0,
    products: initialProducts,
    aditionals: initialAdditionals,
    send: false,
});

const state = reactive({
    date: props.order?.date ?? today(),
    serie:
        props.order?.serie ??
        serieForPoint(defaultPoint.value, form.voucher_type),
});

watch(
    () => [selectedPointId.value, form.voucher_type],
    () => {
        if (props.mode !== 'create') {
            return;
        }

        const point = props.points.find(
            (item) => item.id === selectedPointId.value,
        );
        state.serie = serieForPoint(point, form.voucher_type);
    },
);

const isCreditNote = computed(
    () => Number(form.voucher_type) === VOUCHER_TYPE.CreditNote,
);

const totals = computed(() => {
    const buckets = { 0: 0, 5: 0, 8: 0, 12: 0, 15: 0 } as Record<
        number,
        number
    >;
    let ice = 0;

    for (const line of form.products) {
        const quantity = Number(line.quantity) || 0;
        const price = Number(line.price) || 0;
        const lineDiscount = Number(line.discount) || 0;
        const base = Math.max(quantity * price - lineDiscount, 0);
        const percentage = percentageForCode(line.iva);
        buckets[percentage] = (buckets[percentage] ?? 0) + base;
        ice += Number(line.ice) || 0;
    }

    const round = (value: number) => Number(value.toFixed(2));
    const base0 = round(buckets[0]);
    const base5 = round(buckets[5]);
    const base8 = round(buckets[8]);
    const base12 = round(buckets[12]);
    const base15 = round(buckets[15]);
    const iva5 = round(base5 * 0.05);
    const iva8 = round(base8 * 0.08);
    const iva12 = round(base12 * 0.12);
    const iva15 = round(base15 * 0.15);
    const subTotal = round(base0 + base5 + base8 + base12 + base15);
    const orderDiscount = Math.min(Number(form.discount) || 0, subTotal);
    const total = round(
        subTotal - orderDiscount + iva5 + iva8 + iva12 + iva15 + ice,
    );

    return {
        base0,
        base5,
        base8,
        base12,
        base15,
        iva5,
        iva8,
        iva12,
        iva15,
        ice: round(ice),
        subTotal,
        total,
    };
});

const showConsumidorWarning = computed(
    () =>
        selectedCustomer.value?.identication === CONSUMIDOR_FINAL_ID &&
        totals.value.total > CONSUMIDOR_FINAL_LIMIT,
);

function onSelectCustomer(customer: CustomerOption) {
    selectedCustomer.value = customer;
    form.customer_id = customer.id;
}

function onSelectProduct(index: number, product: ProductOption) {
    const line = form.products[index];
    line.product_id = product.id;
    line.code = product.code;
    line.name = product.name;
    line.price = product.price;
    line.quantity = Number(line.quantity) || 1;
    line.stock = product.stock;
    line.iva = normalizeIvaCode(Number(product.iva));

    if (product.ice !== null) {
        showIce.value = true;
    }
}

function addProductLine() {
    form.products.push(emptyProductLine());
}

function removeProductLine(index: number) {
    form.products.splice(index, 1);
}

function addAdditional() {
    if (form.aditionals.length >= 15) {
        return;
    }

    form.aditionals.push({
        key: nextKey(),
        id: null,
        name: '',
        description: '',
    });
}

function removeAdditional(index: number) {
    form.aditionals.splice(index, 1);
}

function submit(send: boolean) {
    form.send = send;
    form.transform((data) => ({
        point_id: selectedPointId.value,
        voucher_type: Number(data.voucher_type),
        customer_id: data.customer_id,
        date: state.date,
        serie: state.serie,
        guia: isCreditNote.value ? null : data.guia || null,
        date_order: isCreditNote.value ? data.date_order || null : null,
        serie_order: isCreditNote.value ? data.serie_order || null : null,
        reason: isCreditNote.value ? data.reason || null : null,
        pay_method: data.pay_method,
        description: data.description || null,
        discount: Math.min(Number(data.discount) || 0, totals.value.subTotal),
        send: data.send,
        no_iva: 0,
        base0: totals.value.base0,
        base5: totals.value.base5,
        base8: totals.value.base8,
        base12: totals.value.base12,
        base15: totals.value.base15,
        iva5: totals.value.iva5,
        iva8: totals.value.iva8,
        iva: totals.value.iva12,
        iva15: totals.value.iva15,
        ice: totals.value.ice,
        sub_total: totals.value.subTotal,
        total: totals.value.total,
        products: data.products.map((line) => ({
            product_id: line.product_id,
            quantity: Number(line.quantity) || 0,
            price: Number(line.price) || 0,
            discount: Number(line.discount) || 0,
            iva: line.iva,
            ice: Number(line.ice) || 0,
        })),
        aditionals: data.aditionals.map((item) => ({
            name: item.name.trim(),
            description: item.description.trim(),
        })),
    }));

    if (props.mode === 'create') {
        form.post('/orders');
    } else if (props.order) {
        form.put(`/orders/${props.order.id}`);
    }
}

const formErrors = computed(() => form.errors as Record<string, string>);
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4 shadow-sm lg:p-8">
        <p class="mb-2 text-xs text-muted-foreground">
            <span class="text-destructive">*</span> Campos obligatorios
        </p>
        <Separator class="mb-4" />

        <GeneralInformation
            v-model:voucher-type="form.voucher_type"
            v-model:guia="form.guia"
            v-model:date-order="form.date_order"
            v-model:serie-order="form.serie_order"
            v-model:reason="form.reason"
            v-model:pay-method="form.pay_method"
            v-model:point-id="selectedPointId"
            :date="state.date"
            :serie="state.serie"
            :mode="mode"
            :points="points"
            :method-of-payments="methodOfPayments"
            :selected-customer="selectedCustomer"
            :errors="formErrors"
            @update:selected-customer="onSelectCustomer"
        />

        <Separator class="my-4" />

        <ProductLines
            v-model:tax-breakdown="taxBreakdown"
            :lines="form.products"
            :iva-taxes="ivaTaxes"
            :show-ice="showIce"
            :errors="formErrors"
            @add="addProductLine"
            @remove="removeProductLine"
            @select-product="onSelectProduct"
        />

        <Separator class="my-4" />

        <div class="flex flex-col gap-6 lg:flex-row lg:justify-between">
            <div class="lg:max-w-md">
                <AdditionalInfo
                    :lines="form.aditionals"
                    @add="addAdditional"
                    @remove="removeAdditional"
                />
            </div>
            <div class="lg:w-96">
                <OrderTotals
                    v-model:discount="form.discount"
                    :totals="totals"
                    :processing="form.processing"
                    :show-consumidor-warning="showConsumidorWarning"
                    :mode="mode"
                    @submit="submit"
                />
            </div>
        </div>
    </div>
</template>

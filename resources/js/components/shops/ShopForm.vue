<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import ManualTaxBases from '@/components/shops/ManualTaxBases.vue';
import ProviderCombobox from '@/components/shops/ProviderCombobox.vue';
import ShopProductLines from '@/components/shops/ShopProductLines.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import type { ProductOption } from '@/types/order';
import {
    SHOP_VOUCHER_LABEL,
    SHOP_VOUCHER_TYPE,
} from '@/types/shop';
import type {
    EmissionPoint,
    ProviderOption,
    Shop,
    ShopItem,
    ShopProductLine,
    ShopTotalsBreakdown,
} from '@/types/shop';

const props = defineProps<{
    mode: 'create' | 'edit';
    points: EmissionPoint[];
    shop?: Shop;
    shopItems?: ShopItem[];
    provider?: ProviderOption | null;
}>();

let keyCounter = 0;
function nextKey(): string {
    keyCounter += 1;

    return `line-${keyCounter}`;
}

function emptyProductLine(): ShopProductLine {
    return {
        key: nextKey(),
        id: null,
        product_id: null,
        code: '',
        name: '',
        quantity: 1,
        price: 0,
        discount: 0,
        iva: 12,
        stock: null,
    };
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function serieForPoint(point: EmissionPoint | undefined): string {
    if (!point) {
        return 'Seleccione un punto de emisión';
    }

    return `${point.store}-${point.point}-${String(point.settlementonpurchase).padStart(9, '0')}`;
}

const defaultPoint = computed<EmissionPoint | undefined>(() => props.points[0]);

const selectedPointId = ref<number | null>(defaultPoint.value?.id ?? null);
const selectedProvider = ref<ProviderOption | null>(props.provider ?? null);

const initialProducts: ShopProductLine[] = (props.shopItems ?? []).map(
    (item) => ({
        key: nextKey(),
        id: item.id,
        product_id: item.product_id,
        code: item.product?.code ?? '',
        name: item.product?.name ?? '',
        quantity: item.quantity,
        price: item.price,
        discount: item.discount,
        iva: Number(item.iva),
        stock: null,
    }),
);

const form = useForm({
    voucher_type: props.shop?.voucher_type ?? SHOP_VOUCHER_TYPE.Invoice,
    provider_id: props.shop?.provider_id ?? props.provider?.id ?? null,
    authorization: props.shop?.authorization ?? '',
    description: props.shop?.description ?? '',
    base0: props.shop?.base0 ?? 0,
    base5: props.shop?.base5 ?? 0,
    base12: props.shop?.base12 ?? 0,
    base15: props.shop?.base15 ?? 0,
    products: initialProducts.length > 0 ? initialProducts : [emptyProductLine()],
});

const state = reactive({
    date: props.shop?.date ?? today(),
    serie: props.shop?.serie ?? serieForPoint(defaultPoint.value),
});

const isLiquidation = computed(
    () => Number(form.voucher_type) === SHOP_VOUCHER_TYPE.Liquidation,
);

const showPointSelector = computed(
    () => props.mode === 'create' && props.points.length > 1,
);

watch(
    () => [selectedPointId.value, form.voucher_type],
    () => {
        if (props.mode !== 'create' || !isLiquidation.value) {
            return;
        }

        const point = props.points.find(
            (item) => item.id === selectedPointId.value,
        );
        state.serie = serieForPoint(point);
    },
);

const round = (value: number) => Number(value.toFixed(2));

const totals = computed<ShopTotalsBreakdown>(() => {
    let base0: number;
    let base5: number;
    let base12: number;
    let base15: number;

    if (isLiquidation.value) {
        const buckets = { 0: 0, 5: 0, 12: 0, 15: 0 } as Record<number, number>;

        for (const line of form.products) {
            const quantity = Number(line.quantity) || 0;
            const price = Number(line.price) || 0;
            const lineDiscount = Number(line.discount) || 0;
            const base = Math.max(quantity * price - lineDiscount, 0);
            const percentage = Number(line.iva);
            buckets[percentage] = (buckets[percentage] ?? 0) + base;
        }

        base0 = round(buckets[0]);
        base5 = round(buckets[5]);
        base12 = round(buckets[12]);
        base15 = round(buckets[15]);
    } else {
        base0 = round(Number(form.base0) || 0);
        base5 = round(Number(form.base5) || 0);
        base12 = round(Number(form.base12) || 0);
        base15 = round(Number(form.base15) || 0);
    }

    const iva = round(base12 * 0.12);
    const iva5 = round(base5 * 0.05);
    const iva15 = round(base15 * 0.15);
    const subTotal = round(base0 + base5 + base12 + base15);
    const total = round(subTotal + iva + iva5 + iva15);

    return { base0, base5, base12, base15, iva, iva5, iva15, subTotal, total };
});

function onSelectProvider(provider: ProviderOption) {
    selectedProvider.value = provider;
    form.provider_id = provider.id;
}

function onSelectProduct(index: number, product: ProductOption) {
    const line = form.products[index];
    line.product_id = product.id;
    line.code = product.code;
    line.name = product.name;
    line.price = product.price;
    line.quantity = Number(line.quantity) || 1;
    line.stock = product.stock;
}

function addProductLine() {
    form.products.push(emptyProductLine());
}

function removeProductLine(index: number) {
    form.products.splice(index, 1);
}

function submit() {
    form.transform((data) => ({
        point_id: selectedPointId.value,
        voucher_type: Number(data.voucher_type),
        provider_id: data.provider_id,
        date: state.date,
        serie: state.serie,
        authorization: isLiquidation.value
            ? null
            : data.authorization || null,
        description: data.description || null,
        base0: totals.value.base0,
        base5: totals.value.base5,
        base12: totals.value.base12,
        base15: totals.value.base15,
        iva: totals.value.iva,
        iva5: totals.value.iva5,
        iva15: totals.value.iva15,
        sub_total: totals.value.subTotal,
        total: totals.value.total,
        products: isLiquidation.value
            ? data.products.map((line) => ({
                  product_id: line.product_id,
                  quantity: Number(line.quantity) || 0,
                  price: Number(line.price) || 0,
                  discount: Number(line.discount) || 0,
                  iva: Number(line.iva),
              }))
            : [],
    }));

    if (props.mode === 'create') {
        form.post('/shops');
    } else if (props.shop) {
        form.put(`/shops/${props.shop.id}`);
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

        <section class="flex flex-col gap-3">
            <strong class="font-semibold text-foreground"
                >Datos generales</strong
            >

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <Label
                            >Fecha emisión
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            v-model="state.date"
                            type="date"
                            :class="formErrors.date ? 'border-destructive' : ''"
                        />
                        <p v-if="formErrors.date" class="text-xs text-destructive">
                            {{ formErrors.date }}
                        </p>
                    </div>

                    <div v-if="showPointSelector" class="flex flex-col gap-1">
                        <Label
                            >Punto de emisión
                            <span class="text-destructive">*</span></Label
                        >
                        <Select
                            :model-value="
                                selectedPointId != null
                                    ? String(selectedPointId)
                                    : undefined
                            "
                            @update:model-value="
                                selectedPointId = Number($event)
                            "
                        >
                            <SelectTrigger
                                :class="
                                    formErrors.point_id
                                        ? 'border-destructive'
                                        : ''
                                "
                            >
                                <SelectValue placeholder="Seleccione punto" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="point in points"
                                    :key="point.id"
                                    :value="String(point.id)"
                                >
                                    {{ point.store }} - {{ point.point
                                    }}{{
                                        point.recognition
                                            ? ` - ${point.recognition}`
                                            : ''
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="formErrors.point_id"
                            class="text-xs text-destructive"
                        >
                            {{ formErrors.point_id }}
                        </p>
                    </div>

                    <div v-if="isLiquidation" class="text-sm">
                        <span class="font-semibold">N° de serie: </span
                        >{{ state.serie }}
                    </div>

                    <template v-if="!isLiquidation">
                        <div class="flex flex-col gap-1">
                            <Label
                                >N° de serie
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                v-model="state.serie"
                                maxlength="17"
                                :class="
                                    formErrors.serie ? 'border-destructive' : ''
                                "
                            />
                            <p
                                v-if="formErrors.serie"
                                class="text-xs text-destructive"
                            >
                                {{ formErrors.serie }}
                            </p>
                        </div>

                        <div class="flex flex-col gap-1">
                            <Label
                                >Autorización
                                <span class="text-destructive">*</span></Label
                            >
                            <Input
                                v-model="form.authorization"
                                maxlength="49"
                                :class="
                                    formErrors.authorization
                                        ? 'border-destructive'
                                        : ''
                                "
                            />
                            <p
                                v-if="formErrors.authorization"
                                class="text-xs text-destructive"
                            >
                                {{ formErrors.authorization }}
                            </p>
                        </div>
                    </template>

                    <div class="flex flex-col gap-1">
                        <Label
                            >Proveedor
                            <span class="text-destructive">*</span></Label
                        >
                        <ProviderCombobox
                            :model-value="selectedProvider"
                            :error="formErrors.provider_id"
                            @update:model-value="onSelectProvider"
                        />
                        <p
                            v-if="formErrors.provider_id"
                            class="text-xs text-destructive"
                        >
                            {{ formErrors.provider_id }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <Label>Tipo de comprobante</Label>
                        <Select
                            :model-value="String(form.voucher_type)"
                            @update:model-value="
                                form.voucher_type = Number($event)
                            "
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(label, value) in SHOP_VOUCHER_LABEL"
                                    :key="value"
                                    :value="String(value)"
                                >
                                    {{ label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex flex-col gap-1">
                        <Label>Descripción</Label>
                        <Textarea v-model="form.description" rows="3" />
                    </div>
                </div>
            </div>
        </section>

        <Separator class="my-4" />

        <ShopProductLines
            v-if="isLiquidation"
            :lines="form.products"
            :errors="formErrors"
            @add="addProductLine"
            @remove="removeProductLine"
            @select-product="onSelectProduct"
        />

        <ManualTaxBases
            v-else
            v-model:base0="form.base0"
            v-model:base5="form.base5"
            v-model:base12="form.base12"
            v-model:base15="form.base15"
            :totals="totals"
            :errors="formErrors"
        />

        <Separator class="my-4" />

        <div class="flex flex-col gap-4 lg:flex-row lg:justify-end">
            <div class="lg:w-96">
                <table
                    v-if="isLiquidation"
                    class="mb-4 w-full border-collapse text-sm"
                >
                    <tbody
                        class="[&>tr>td]:border [&>tr>td]:border-border [&>tr>td]:p-2"
                    >
                        <tr v-if="totals.base0 > 0">
                            <td>Subtotal 0%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.base0.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.base5 > 0">
                            <td>Subtotal 5%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.base5.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.base12 > 0">
                            <td>Subtotal 12%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.base12.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.base15 > 0">
                            <td>Subtotal 15%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.base15.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.iva5 > 0">
                            <td>IVA 5%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.iva5.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.iva > 0">
                            <td>IVA 12%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.iva.toFixed(2) }}
                            </td>
                        </tr>
                        <tr v-if="totals.iva15 > 0">
                            <td>IVA 15%</td>
                            <td class="text-right tabular-nums">
                                {{ totals.iva15.toFixed(2) }}
                            </td>
                        </tr>
                        <tr class="font-semibold">
                            <td>TOTAL</td>
                            <td class="text-right tabular-nums">
                                {{ totals.total.toFixed(2) }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="flex justify-end">
                    <Button :disabled="form.processing" @click="submit">
                        <Save />
                        Guardar
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>

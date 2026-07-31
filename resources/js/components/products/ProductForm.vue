<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
import type { IceCatalog, IvaTax, Product, SriCategory } from '@/types/product';
import { PRODUCT_TYPE_OPTIONS } from '@/types/product';

const props = defineProps<{
    mode: 'create' | 'edit';
    ivaTaxes: IvaTax[];
    iceCataloges: IceCatalog[];
    sriCategories: SriCategory[];
    transport: boolean;
    product?: Product;
}>();

const defaultIvaCode =
    props.ivaTaxes.find((tax) => tax.percentage === 15)?.code ??
    props.ivaTaxes[0]?.code ??
    0;

function percentageForCode(code: number): number {
    return props.ivaTaxes.find((tax) => tax.code === code)?.percentage ?? 0;
}

const form = useForm<{
    code: string;
    type_product: number;
    name: string;
    price1: number | string;
    iva: number;
    ice: number | null;
    aux_cod: string | null;
}>({
    code: props.product?.code ?? '',
    type_product: props.product?.type_product ?? 1,
    name: props.product?.name ?? '',
    price1: props.product?.price1 ?? '',
    iva: props.product?.iva ?? defaultIvaCode,
    ice: props.product?.ice ?? null,
    aux_cod: props.product?.aux_cod ?? null,
});

const breakdown = ref(false);
const total = ref('');

const selectedPercentage = computed(() => percentageForCode(form.iva));
const showSriCategory = computed(
    () => props.transport || selectedPercentage.value === 5,
);

function recalcPriceFromTotal() {
    const parsed = parseFloat(total.value);

    if (Number.isNaN(parsed)) {
        return;
    }

    const rate = 1 + selectedPercentage.value / 100;
    form.price1 = parseFloat((parsed / rate).toFixed(6));
}

function onChangeTotal(value: string | number) {
    const next = String(value);

    if (!/^[0-9]*\.?[0-9]*$/.test(next)) {
        return;
    }

    total.value = next;
    recalcPriceFromTotal();
}

watch(
    () => form.iva,
    () => {
        if (breakdown.value && total.value !== '') {
            recalcPriceFromTotal();
        }
    },
);

function submit() {
    form.transform((data) => ({
        ...data,
        type_product: Number(data.type_product),
        price1: Number(data.price1) || 0,
        iva: Number(data.iva),
        ice: data.ice !== null ? Number(data.ice) : null,
        aux_cod: showSriCategory.value ? data.aux_cod : null,
    }));

    if (props.mode === 'create') {
        form.post('/products');
    } else if (props.product) {
        form.put(`/products/${props.product.id}`);
    }
}
</script>

<template>
    <form
        class="rounded-lg border border-border bg-card p-4 shadow-sm lg:p-8"
        @submit.prevent="submit"
    >
        <p class="mb-2 text-xs text-muted-foreground">
            <span class="text-destructive">*</span> Campos obligatorios
        </p>
        <Separator class="mb-4" />

        <strong class="font-semibold text-foreground">Datos generales</strong>

        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <Label for="code"
                        >Código <span class="text-destructive">*</span></Label
                    >
                    <Input
                        id="code"
                        v-model="form.code"
                        type="text"
                        maxlength="25"
                        required
                        :class="form.errors.code ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.code" class="text-xs text-destructive">
                        {{ form.errors.code }}
                    </p>
                </div>

                <div v-if="showSriCategory" class="flex flex-col gap-1">
                    <Label>Categoría SRI</Label>
                    <Select
                        :model-value="form.aux_cod ?? undefined"
                        @update:model-value="form.aux_cod = String($event)"
                    >
                        <SelectTrigger
                            :class="
                                form.errors.aux_cod ? 'border-destructive' : ''
                            "
                        >
                            <SelectValue placeholder="Seleccione categoría" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="category in sriCategories"
                                :key="category.code"
                                :value="category.code"
                            >
                                {{ category.description }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.aux_cod"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.aux_cod }}
                    </p>
                </div>

                <div class="flex flex-col gap-1">
                    <Label>Tipo</Label>
                    <Select
                        :model-value="String(form.type_product)"
                        @update:model-value="form.type_product = Number($event)"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in PRODUCT_TYPE_OPTIONS"
                                :key="option.value"
                                :value="String(option.value)"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="flex flex-col gap-1">
                    <Label for="name"
                        >Nombre <span class="text-destructive">*</span></Label
                    >
                    <Input
                        id="name"
                        v-model="form.name"
                        type="text"
                        maxlength="300"
                        required
                        :class="form.errors.name ? 'border-destructive' : ''"
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input v-model="breakdown" type="checkbox" />
                    ¿Necesitas desglosar el IVA?
                </label>

                <div class="flex flex-col gap-1">
                    <Label
                        >Precio <span class="text-destructive">*</span></Label
                    >
                    <div class="flex flex-row">
                        <Input
                            v-if="breakdown"
                            :model-value="total"
                            type="text"
                            maxlength="15"
                            placeholder="Total"
                            class="rounded-r-none"
                            @update:model-value="onChangeTotal"
                        />
                        <Input
                            v-model="form.price1"
                            type="number"
                            min="0"
                            step="0.000001"
                            :class="[
                                breakdown ? 'rounded-l-none border-l-0' : '',
                                form.errors.price1 ? 'border-destructive' : '',
                            ]"
                        />
                    </div>
                    <p
                        v-if="form.errors.price1"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.price1 }}
                    </p>
                </div>
            </div>
        </div>

        <Separator class="my-4" />

        <strong class="font-semibold text-foreground">Impuestos</strong>

        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-1">
                <Label>Imp. al IVA</Label>
                <Select
                    :model-value="String(form.iva)"
                    @update:model-value="form.iva = Number($event)"
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="tax in ivaTaxes"
                            :key="tax.code"
                            :value="String(tax.code)"
                        >
                            {{ tax.percentage }}%
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="iceCataloges.length > 0" class="flex flex-col gap-1">
                <Label>Imp. al ICE</Label>
                <Select
                    :model-value="
                        form.ice != null ? String(form.ice) : undefined
                    "
                    @update:model-value="form.ice = Number($event)"
                >
                    <SelectTrigger>
                        <SelectValue placeholder="Seleccione ICE" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="ice in iceCataloges"
                            :key="ice.code"
                            :value="String(ice.code)"
                        >
                            {{ ice.description }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <Separator class="my-4" />

        <div class="flex justify-end">
            <Button type="submit" :disabled="form.processing">
                {{ mode === 'create' ? 'Guardar' : 'Actualizar' }}
            </Button>
        </div>
    </form>
</template>

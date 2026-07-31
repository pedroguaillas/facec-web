<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import ProductCombobox from '@/components/orders/ProductCombobox.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { IvaTax, ProductLine, ProductOption } from '@/types/order';

const props = defineProps<{
    lines: ProductLine[];
    ivaTaxes: IvaTax[];
    taxBreakdown: boolean;
    showIce: boolean;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:taxBreakdown': [value: boolean];
    add: [];
    remove: [index: number];
    'select-product': [index: number, product: ProductOption];
}>();

function percentageForCode(code: number): number {
    return props.ivaTaxes.find((tax) => tax.code === code)?.percentage ?? 0;
}

function lineIva(line: ProductLine): number {
    const q = Number(line.quantity) || 0;
    const p = Number(line.price) || 0;
    const d = Number(line.discount) || 0;
    const base = Math.max(q * p - d, 0);

    return (base * percentageForCode(line.iva)) / 100;
}

function lineSubtotal(line: ProductLine): number {
    const q = Number(line.quantity) || 0;
    const p = Number(line.price) || 0;
    const d = Number(line.discount) || 0;

    return Math.max(q * p - d, 0);
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-center gap-6">
            <span class="font-semibold text-foreground"
                >Productos / Servicios</span
            >
            <label class="inline-flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    :checked="taxBreakdown"
                    @change="
                        emit(
                            'update:taxBreakdown',
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                Desglose IVA
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr
                        class="[&>th]:border [&>th]:border-border [&>th]:p-2 [&>th]:text-xs [&>th]:font-medium"
                    >
                        <th class="w-20">Cant</th>
                        <th class="min-w-48 text-left">Producto / Servicio</th>
                        <th class="w-24">Precio</th>
                        <th class="w-24">Descuento</th>
                        <th class="w-24">IVA %</th>
                        <th v-if="taxBreakdown" class="w-24 text-right">IVA</th>
                        <th class="w-24 text-right">Subtotal</th>
                        <th v-if="showIce" class="w-24">ICE</th>
                        <th class="w-10" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, index) in lines"
                        :key="line.key"
                        class="[&>td]:border [&>td]:border-border [&>td]:p-1.5 [&>td]:align-top"
                    >
                        <td>
                            <Input
                                v-model="line.quantity"
                                type="number"
                                min="0"
                                :class="
                                    errors[`products.${index}.quantity`]
                                        ? 'border-destructive'
                                        : ''
                                "
                            />
                        </td>
                        <td>
                            <ProductCombobox
                                :label="line.name"
                                :error="errors[`products.${index}.product_id`]"
                                @select="emit('select-product', index, $event)"
                            />
                        </td>
                        <td>
                            <Input
                                v-model="line.price"
                                type="number"
                                min="0"
                                step="0.000001"
                                :class="
                                    errors[`products.${index}.price`]
                                        ? 'border-destructive'
                                        : ''
                                "
                            />
                        </td>
                        <td>
                            <Input
                                v-model="line.discount"
                                type="number"
                                min="0"
                                step="0.01"
                                :class="
                                    errors[`products.${index}.discount`]
                                        ? 'border-destructive'
                                        : ''
                                "
                            />
                        </td>
                        <td>
                            <Select
                                :model-value="String(line.iva)"
                                @update:model-value="line.iva = Number($event)"
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
                        </td>
                        <td v-if="taxBreakdown" class="text-right tabular-nums">
                            {{ lineIva(line).toFixed(2) }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{ lineSubtotal(line).toFixed(2) }}
                        </td>
                        <td v-if="showIce">
                            <Input
                                v-model="line.ice"
                                type="number"
                                min="0"
                                step="0.01"
                            />
                        </td>
                        <td class="text-center">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive"
                                aria-label="Eliminar línea"
                                @click="emit('remove', index)"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="lines.length === 0">
                        <td
                            :colspan="taxBreakdown ? 8 : 7"
                            class="border border-border p-4 text-center text-muted-foreground"
                        >
                            Añada al menos una línea.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <Button variant="outline" size="sm" @click="emit('add')">
                <Plus />
                Añadir línea
            </Button>
        </div>
    </section>
</template>

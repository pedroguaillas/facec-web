<script setup lang="ts">
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ShopTotalsBreakdown } from '@/types/shop';

const props = defineProps<{
    base0: number | string;
    base5: number | string;
    base12: number | string;
    base15: number | string;
    totals: ShopTotalsBreakdown;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:base0': [value: string];
    'update:base5': [value: string];
    'update:base12': [value: string];
    'update:base15': [value: string];
}>();

type BaseKey = 'base0' | 'base5' | 'base12' | 'base15';

const baseFields: { key: BaseKey; label: string }[] = [
    { key: 'base0', label: 'Base 0%' },
    { key: 'base5', label: 'Base 5%' },
    { key: 'base12', label: 'Base 12%' },
    { key: 'base15', label: 'Base 15%' },
];

function valueFor(key: BaseKey) {
    return props[key];
}

function updateBase(key: BaseKey, value: string) {
    switch (key) {
        case 'base0':
            emit('update:base0', value);
            break;
        case 'base5':
            emit('update:base5', value);
            break;
        case 'base12':
            emit('update:base12', value);
            break;
        case 'base15':
            emit('update:base15', value);
            break;
    }
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <strong class="font-semibold text-foreground">Bases imponibles</strong>

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="field in baseFields"
                :key="field.key"
                class="flex flex-col gap-1"
            >
                <Label>{{ field.label }}</Label>
                <Input
                    :model-value="valueFor(field.key)"
                    type="number"
                    min="0"
                    step="0.01"
                    :class="errors[field.key] ? 'border-destructive' : ''"
                    @update:model-value="updateBase(field.key, String($event))"
                />
                <p v-if="errors[field.key]" class="text-xs text-destructive">
                    {{ errors[field.key] }}
                </p>
            </div>
        </div>

        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="[&>th]:border [&>th]:border-border [&>th]:p-2">
                    <th class="text-left">Resultados</th>
                    <th class="w-32 text-right">Monto</th>
                </tr>
            </thead>
            <tbody
                class="[&>tr>td]:border [&>tr>td]:border-border [&>tr>td]:p-2"
            >
                <tr>
                    <td>IVA 5%</td>
                    <td class="text-right tabular-nums">
                        {{ totals.iva5.toFixed(2) }}
                    </td>
                </tr>
                <tr>
                    <td>IVA 12%</td>
                    <td class="text-right tabular-nums">
                        {{ totals.iva.toFixed(2) }}
                    </td>
                </tr>
                <tr>
                    <td>IVA 15%</td>
                    <td class="text-right tabular-nums">
                        {{ totals.iva15.toFixed(2) }}
                    </td>
                </tr>
                <tr>
                    <td>Subtotal</td>
                    <td class="text-right tabular-nums">
                        {{ totals.subTotal.toFixed(2) }}
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="[&>th]:border [&>th]:border-border [&>th]:p-2">
                    <th class="text-left">TOTAL</th>
                    <th class="text-right tabular-nums">
                        {{ totals.total.toFixed(2) }}
                    </th>
                </tr>
            </tfoot>
        </table>
    </section>
</template>

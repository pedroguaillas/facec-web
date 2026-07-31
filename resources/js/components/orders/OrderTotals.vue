<script setup lang="ts">
import { Save } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { OrderTotalsBreakdown } from '@/types/order';

const props = defineProps<{
    totals: OrderTotalsBreakdown;
    discount: number | string;
    processing: boolean;
    showConsumidorWarning: boolean;
    mode: 'create' | 'edit';
}>();

const emit = defineEmits<{
    'update:discount': [value: string];
    submit: [send: boolean];
}>();

const subtotalRows = computed(() =>
    [
        { label: 'Subtotal 0%', value: props.totals.base0 },
        { label: 'Subtotal 5%', value: props.totals.base5 },
        { label: 'Subtotal 8%', value: props.totals.base8 },
        { label: 'Subtotal 12%', value: props.totals.base12 },
        { label: 'Subtotal 15%', value: props.totals.base15 },
    ].filter((row) => row.value > 0),
);

const ivaRows = computed(() =>
    [
        { label: 'IVA 5%', value: props.totals.iva5 },
        { label: 'IVA 8%', value: props.totals.iva8 },
        { label: 'IVA 12%', value: props.totals.iva12 },
        { label: 'IVA 15%', value: props.totals.iva15 },
    ].filter((row) => row.value > 0),
);
</script>

<template>
    <div class="flex flex-col gap-3">
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
                <tr v-if="totals.subTotal === 0">
                    <td>Subtotal</td>
                    <td class="text-right tabular-nums">0.00</td>
                </tr>
                <tr v-for="row in subtotalRows" :key="row.label">
                    <td>{{ row.label }}</td>
                    <td class="text-right tabular-nums">
                        {{ row.value.toFixed(2) }}
                    </td>
                </tr>
                <tr>
                    <td>Descuento</td>
                    <td class="text-right">
                        <Input
                            :model-value="discount"
                            type="number"
                            min="0"
                            :max="totals.subTotal"
                            step="0.01"
                            class="ml-auto h-8 w-24 text-right"
                            @update:model-value="
                                emit('update:discount', String($event))
                            "
                        />
                    </td>
                </tr>
                <tr v-if="totals.ice > 0">
                    <td>Monto de ICE</td>
                    <td class="text-right tabular-nums">
                        {{ totals.ice.toFixed(2) }}
                    </td>
                </tr>
                <tr v-for="row in ivaRows" :key="row.label">
                    <td>{{ row.label }}</td>
                    <td class="text-right tabular-nums">
                        {{ row.value.toFixed(2) }}
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

        <p
            v-if="showConsumidorWarning"
            class="text-right text-sm text-destructive"
        >
            Límite $50 si es Consumidor Final
        </p>

        <div class="flex justify-end gap-2">
            <Button
                variant="outline"
                :disabled="processing"
                @click="emit('submit', false)"
            >
                <Save />
                Guardar
            </Button>
            <Button :disabled="processing" @click="emit('submit', true)">
                <Save />
                Guardar y procesar
            </Button>
        </div>
    </div>
</template>

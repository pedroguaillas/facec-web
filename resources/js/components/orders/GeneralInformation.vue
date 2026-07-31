<script setup lang="ts">
import { computed } from 'vue';
import CustomerCombobox from '@/components/orders/CustomerCombobox.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { VOUCHER_TYPE } from '@/types/order';
import type {
    CustomerOption,
    EmissionPoint,
    MethodOfPayment,
} from '@/types/order';

const props = defineProps<{
    date: string;
    serie: string;
    voucherType: number;
    guia: string;
    dateOrder: string;
    serieOrder: string;
    reason: string;
    payMethod: number | null;
    pointId: number | null;
    mode: 'create' | 'edit';
    points: EmissionPoint[];
    methodOfPayments: MethodOfPayment[];
    selectedCustomer: CustomerOption | null;
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    'update:voucherType': [value: number];
    'update:guia': [value: string];
    'update:dateOrder': [value: string];
    'update:serieOrder': [value: string];
    'update:reason': [value: string];
    'update:payMethod': [value: number];
    'update:pointId': [value: number];
    'update:selectedCustomer': [value: CustomerOption];
}>();

const isCreditNote = computed(
    () => Number(props.voucherType) === VOUCHER_TYPE.CreditNote,
);
const showPointSelector = computed(
    () => props.mode === 'create' && props.points.length > 1,
);
</script>

<template>
    <section class="flex flex-col gap-3">
        <strong class="font-semibold text-foreground">Datos generales</strong>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-3">
                <div class="text-sm">
                    <span class="font-semibold">Fecha emisión: </span>{{ date }}
                </div>

                <div v-if="showPointSelector" class="flex flex-col gap-1">
                    <Label
                        >Punto de emisión
                        <span class="text-destructive">*</span></Label
                    >
                    <Select
                        :model-value="
                            pointId != null ? String(pointId) : undefined
                        "
                        @update:model-value="
                            emit('update:pointId', Number($event))
                        "
                    >
                        <SelectTrigger
                            :class="errors.serie ? 'border-destructive' : ''"
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
                    <p v-if="errors.serie" class="text-xs text-destructive">
                        {{ errors.serie }}
                    </p>
                </div>

                <div class="text-sm">
                    <span class="font-semibold">N° de serie: </span>{{ serie }}
                </div>

                <div class="flex flex-col gap-1">
                    <Label
                        >Cliente <span class="text-destructive">*</span></Label
                    >
                    <CustomerCombobox
                        :model-value="selectedCustomer"
                        :error="errors.customer_id"
                        @update:model-value="
                            emit('update:selectedCustomer', $event)
                        "
                    />
                    <p
                        v-if="errors.customer_id"
                        class="text-xs text-destructive"
                    >
                        {{ errors.customer_id }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                    <Label>Tipo de comprobante</Label>
                    <Select
                        :model-value="String(voucherType)"
                        @update:model-value="
                            emit('update:voucherType', Number($event))
                        "
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="String(VOUCHER_TYPE.Invoice)"
                                >Factura</SelectItem
                            >
                            <SelectItem
                                :value="String(VOUCHER_TYPE.CreditNote)"
                            >
                                Nota de Crédito
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div v-if="!isCreditNote" class="flex flex-col gap-1">
                    <Label>Guía de Remisión</Label>
                    <Input
                        :model-value="guia"
                        maxlength="17"
                        @update:model-value="
                            emit('update:guia', String($event))
                        "
                    />
                </div>

                <template v-else>
                    <div class="flex flex-col gap-1">
                        <Label
                            >Emisión factura
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            type="date"
                            :model-value="dateOrder"
                            :class="
                                errors.date_order ? 'border-destructive' : ''
                            "
                            @update:model-value="
                                emit('update:dateOrder', String($event))
                            "
                        />
                        <p
                            v-if="errors.date_order"
                            class="text-xs text-destructive"
                        >
                            {{ errors.date_order }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <Label
                            >Serie factura
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            :model-value="serieOrder"
                            maxlength="17"
                            :class="
                                errors.serie_order ? 'border-destructive' : ''
                            "
                            @update:model-value="
                                emit('update:serieOrder', String($event))
                            "
                        />
                        <p
                            v-if="errors.serie_order"
                            class="text-xs text-destructive"
                        >
                            {{ errors.serie_order }}
                        </p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <Label
                            >Motivo
                            <span class="text-destructive">*</span></Label
                        >
                        <Input
                            :model-value="reason"
                            :class="errors.reason ? 'border-destructive' : ''"
                            @update:model-value="
                                emit('update:reason', String($event))
                            "
                        />
                        <p
                            v-if="errors.reason"
                            class="text-xs text-destructive"
                        >
                            {{ errors.reason }}
                        </p>
                    </div>
                </template>

                <div v-if="!isCreditNote" class="flex flex-col gap-1">
                    <Label>Forma de pago</Label>
                    <Select
                        :model-value="
                            payMethod != null ? String(payMethod) : undefined
                        "
                        @update:model-value="
                            emit('update:payMethod', Number($event))
                        "
                    >
                        <SelectTrigger>
                            <SelectValue
                                placeholder="Seleccione forma de pago"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="method in methodOfPayments"
                                :key="method.id"
                                :value="method.code"
                            >
                                {{ method.description }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </div>
    </section>
</template>

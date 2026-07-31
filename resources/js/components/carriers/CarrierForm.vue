<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import type { Carrier } from '@/types/carrier';
import { CARRIER_IDENTIFICATION_TYPE_OPTIONS } from '@/types/carrier';

const props = defineProps<{
    mode: 'create' | 'edit';
    carrier?: Carrier;
}>();

const form = useForm<{
    type_identification: string;
    identication: string;
    name: string;
    license_plate: string;
    email: string;
}>({
    type_identification: props.carrier?.type_identification ?? 'cédula',
    identication: props.carrier?.identication ?? '',
    name: props.carrier?.name ?? '',
    license_plate: props.carrier?.license_plate ?? '',
    email: props.carrier?.email ?? '',
});

function submit() {
    form.transform((data) => ({
        ...data,
        email: data.email || null,
    }));

    if (props.mode === 'create') {
        form.post('/carriers');
    } else if (props.carrier) {
        form.put(`/carriers/${props.carrier.id}`);
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
            <div class="flex flex-col gap-1">
                <Label
                    >Tipo de identificación
                    <span class="text-destructive">*</span></Label
                >
                <Select
                    :model-value="form.type_identification"
                    @update:model-value="
                        form.type_identification = String($event)
                    "
                >
                    <SelectTrigger
                        :class="
                            form.errors.type_identification
                                ? 'border-destructive'
                                : ''
                        "
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in CARRIER_IDENTIFICATION_TYPE_OPTIONS"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    v-if="form.errors.type_identification"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.type_identification }}
                </p>
            </div>

            <div class="flex flex-col gap-1">
                <Label for="identication"
                    >Identificación
                    <span class="text-destructive">*</span></Label
                >
                <Input
                    id="identication"
                    v-model="form.identication"
                    type="text"
                    maxlength="13"
                    required
                    :class="
                        form.errors.identication ? 'border-destructive' : ''
                    "
                />
                <p
                    v-if="form.errors.identication"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.identication }}
                </p>
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

            <div class="flex flex-col gap-1">
                <Label for="license_plate"
                    >Placa <span class="text-destructive">*</span></Label
                >
                <Input
                    id="license_plate"
                    v-model="form.license_plate"
                    type="text"
                    maxlength="20"
                    required
                    :class="
                        form.errors.license_plate ? 'border-destructive' : ''
                    "
                />
                <p
                    v-if="form.errors.license_plate"
                    class="text-xs text-destructive"
                >
                    {{ form.errors.license_plate }}
                </p>
            </div>

            <div class="flex flex-col gap-1">
                <Label for="email">Correo</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    maxlength="300"
                    :class="form.errors.email ? 'border-destructive' : ''"
                />
                <p v-if="form.errors.email" class="text-xs text-destructive">
                    {{ form.errors.email }}
                </p>
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

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
import type { Provider } from '@/types/provider';
import { IDENTIFICATION_TYPE_OPTIONS } from '@/types/provider';

const props = defineProps<{
    mode: 'create' | 'edit';
    provider?: Provider;
}>();

const form = useForm<{
    type_identification: string;
    identication: string;
    name: string;
    address: string;
    phone: string;
    email: string;
}>({
    type_identification: props.provider?.type_identification ?? 'cédula',
    identication: props.provider?.identication ?? '',
    name: props.provider?.name ?? '',
    address: props.provider?.address ?? '',
    phone: props.provider?.phone ?? '',
    email: props.provider?.email ?? '',
});

function submit() {
    form.transform((data) => ({
        ...data,
        phone: data.phone || null,
        email: data.email || null,
    }));

    if (props.mode === 'create') {
        form.post('/providers');
    } else if (props.provider) {
        form.put(`/providers/${props.provider.id}`);
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
                            v-for="option in IDENTIFICATION_TYPE_OPTIONS"
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
                <Label for="address"
                    >Dirección <span class="text-destructive">*</span></Label
                >
                <Input
                    id="address"
                    v-model="form.address"
                    type="text"
                    maxlength="300"
                    required
                    :class="form.errors.address ? 'border-destructive' : ''"
                />
                <p v-if="form.errors.address" class="text-xs text-destructive">
                    {{ form.errors.address }}
                </p>
            </div>

            <div class="flex flex-col gap-1">
                <Label for="phone">Teléfono</Label>
                <Input
                    id="phone"
                    v-model="form.phone"
                    type="text"
                    maxlength="20"
                    :class="form.errors.phone ? 'border-destructive' : ''"
                />
                <p v-if="form.errors.phone" class="text-xs text-destructive">
                    {{ form.errors.phone }}
                </p>
            </div>

            <div class="flex flex-col gap-1">
                <Label for="email">Correo</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    maxlength="50"
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

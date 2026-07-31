<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AdditionalLine } from '@/types/order';

defineProps<{
    lines: AdditionalLine[];
}>();

const emit = defineEmits<{
    add: [];
    remove: [index: number];
}>();

const MAX_LINES = 15;
</script>

<template>
    <section class="flex w-full flex-col gap-2">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr>
                        <th
                            colspan="3"
                            class="border border-border p-2 text-xs font-medium"
                        >
                            Información Adicional
                        </th>
                    </tr>
                    <tr
                        class="[&>th]:border [&>th]:border-border [&>th]:p-2 [&>th]:text-xs [&>th]:font-medium"
                    >
                        <th class="text-left">Nombre</th>
                        <th class="text-left">Descripción</th>
                        <th class="w-10" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(line, index) in lines"
                        :key="line.key"
                        class="[&>td]:border [&>td]:border-border [&>td]:p-1.5"
                    >
                        <td><Input v-model="line.name" /></td>
                        <td><Input v-model="line.description" /></td>
                        <td class="text-center">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive"
                                aria-label="Eliminar"
                                @click="emit('remove', index)"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex justify-end">
            <Button
                variant="outline"
                size="sm"
                :disabled="lines.length >= MAX_LINES"
                @click="emit('add')"
            >
                <Plus />
                Añadir
            </Button>
        </div>
        <p
            v-if="lines.length >= MAX_LINES"
            class="text-right text-xs text-muted-foreground"
        >
            Máximo {{ MAX_LINES }} filas de información adicional.
        </p>
    </section>
</template>

<script setup lang="ts">
import { ChevronsUpDown, Loader2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { ProductOption } from '@/types/order';

defineProps<{
    label: string;
    error?: string;
}>();

const emit = defineEmits<{
    select: [product: ProductOption];
}>();

const open = ref(false);
const query = ref('');
const results = ref<ProductOption[]>([]);
const loading = ref(false);
let debounce: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

watch(query, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => search(value), 300);
});

watch(open, (isOpen) => {
    if (isOpen && results.value.length === 0) {
        search(query.value);
    }
});

async function search(value: string) {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;

    try {
        const response = await fetch(
            `/products/lookup?q=${encodeURIComponent(value)}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        results.value = response.ok ? await response.json() : [];
    } catch (error) {
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
            results.value = [];
        }
    } finally {
        loading.value = false;
    }
}

function select(product: ProductOption) {
    emit('select', product);
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between font-normal"
                :class="error ? 'border-destructive' : ''"
            >
                <span class="truncate">
                    {{ label || 'Seleccione un producto' }}
                </span>
                <ChevronsUpDown class="size-4 opacity-50" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-[var(--reka-popover-trigger-width)] min-w-64 p-0"
            align="start"
        >
            <div class="p-1">
                <Input
                    v-model="query"
                    placeholder="Buscar producto ..."
                    autofocus
                    class="h-8"
                />
            </div>
            <div class="max-h-64 overflow-y-auto p-1">
                <div
                    v-if="loading"
                    class="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Buscando...
                </div>
                <p
                    v-else-if="results.length === 0"
                    class="py-4 text-center text-sm text-muted-foreground"
                >
                    Sin resultados.
                </p>
                <button
                    v-for="product in results"
                    v-else
                    :key="product.id"
                    type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-muted"
                    @click="select(product)"
                >
                    <span class="flex flex-col">
                        <span class="font-medium">{{ product.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            product.code
                        }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground"
                        >${{ Number(product.price).toFixed(2) }}</span
                    >
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>

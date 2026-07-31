<script setup lang="ts">
import { Info } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const props = defineProps<{
    state: string | null;
    extraDetail?: string | null;
}>();

const WARNING_STATES = ['NO AUTORIZADO', 'EN PROCESO', 'DEVUELTA'];

const label = computed(() => props.state || 'CREADO');
const isAuthorized = computed(() => props.state === 'AUTORIZADO');
const isWarning = computed(() => WARNING_STATES.includes(props.state ?? ''));
</script>

<template>
    <div class="inline-flex items-center gap-1">
        <Badge
            :class="
                isAuthorized
                    ? 'bg-success text-white'
                    : isWarning
                      ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-300 dark:text-yellow-900'
                      : 'bg-muted text-muted-foreground'
            "
        >
            {{ label }}
        </Badge>
        <TooltipProvider v-if="isWarning && extraDetail">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Info class="size-4 cursor-pointer text-muted-foreground" />
                </TooltipTrigger>
                <TooltipContent>
                    <p class="max-w-xs">{{ extraDetail }}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>

<script setup lang="ts">
import { Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    /** Percent change, e.g. 12.4 or -8.1. Omit if using `label` instead. */
    percent?: number | null;
    /** Free-form label, e.g. "+3 este mes". Takes precedence over `percent`. */
    label?: string;
    /** Whether a positive value is good (default) or bad (e.g. expenses). */
    invert?: boolean;
}>();

const direction = computed<'up' | 'down' | 'flat'>(() => {
    if (props.label) {
        return 'flat';
    }

    if (
        props.percent === null ||
        props.percent === undefined ||
        Number.isNaN(props.percent)
    ) {
        return 'flat';
    }

    if (Math.abs(props.percent) < 0.5) {
        return 'flat';
    }

    return props.percent > 0 ? 'up' : 'down';
});

const isGood = computed(() => {
    if (direction.value === 'flat') {
        return null;
    }

    const positive = direction.value === 'up';

    return props.invert ? !positive : positive;
});

const text = computed(() => {
    if (props.label) {
        return props.label;
    }

    if (
        props.percent === null ||
        props.percent === undefined ||
        Number.isNaN(props.percent)
    ) {
        return '—';
    }

    const sign = props.percent > 0 ? '+' : '';

    return `${sign}${props.percent.toFixed(1)}%`;
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="{
            'bg-success/10 text-success': isGood === true,
            'bg-danger/10 text-danger': isGood === false,
            'bg-muted text-muted-foreground': isGood === null,
        }"
    >
        <TrendingUp v-if="direction === 'up'" class="size-3" />
        <TrendingDown v-else-if="direction === 'down'" class="size-3" />
        <Minus v-else class="size-3" />
        {{ text }}
    </span>
</template>

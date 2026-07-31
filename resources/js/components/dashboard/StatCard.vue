<script setup lang="ts">
import type { Component } from 'vue';
import TrendBadge from '@/components/dashboard/TrendBadge.vue';

withDefaults(
    defineProps<{
        label: string;
        value: number | string;
        icon: Component;
        trendPercent?: number | null;
        trendLabel?: string;
        trendInvert?: boolean;
    }>(),
    {
        trendPercent: null,
        trendLabel: undefined,
        trendInvert: false,
    },
);
</script>

<template>
    <div
        class="flex items-center gap-4 rounded-lg border border-border bg-card p-4"
    >
        <div
            class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
        >
            <component :is="icon" class="size-5" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-2xl font-semibold text-foreground tabular-nums">
                    {{ value }}
                </p>
                <TrendBadge
                    v-if="trendPercent !== null || trendLabel"
                    :percent="trendPercent"
                    :label="trendLabel"
                    :invert="trendInvert"
                />
            </div>
            <p class="truncate text-sm text-muted-foreground">{{ label }}</p>
        </div>
    </div>
</template>

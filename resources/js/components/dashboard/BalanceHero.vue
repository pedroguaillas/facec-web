<script setup lang="ts">
import {
    ArrowDownRight,
    ArrowUpRight,
    Minus,
    TrendingDown,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import type { MonthlyAmount } from '@/types/dashboard';

const props = defineProps<{
    income: MonthlyAmount[];
    expenses: MonthlyAmount[];
}>();

function amountFor(list: MonthlyAmount[], index: number): number {
    return list.at(index)?.total ?? 0;
}

const currentIncome = computed(() => amountFor(props.income, -1));
const currentExpense = computed(() => amountFor(props.expenses, -1));
const previousIncome = computed(() => amountFor(props.income, -2));
const previousExpense = computed(() => amountFor(props.expenses, -2));

const currentBalance = computed(
    () => currentIncome.value - currentExpense.value,
);
const previousBalance = computed(
    () => previousIncome.value - previousExpense.value,
);

const trendPercent = computed(() => {
    if (previousBalance.value === 0) {
        return null;
    }

    return (
        ((currentBalance.value - previousBalance.value) /
            Math.abs(previousBalance.value)) *
        100
    );
});

const currentMonthLabel = computed(
    () => props.income.at(-1)?.name ?? props.expenses.at(-1)?.name ?? '',
);

const trendDirection = computed<'up' | 'down' | 'flat'>(() => {
    if (trendPercent.value === null || Math.abs(trendPercent.value) < 0.5) {
        return 'flat';
    }

    return trendPercent.value > 0 ? 'up' : 'down';
});

const trendText = computed(() => {
    if (trendPercent.value === null) {
        return 'Sin datos del mes anterior';
    }

    const sign = trendPercent.value > 0 ? '+' : '';

    return `${sign}${trendPercent.value.toFixed(1)}% vs mes anterior`;
});

function formatMoney(value: number): string {
    return new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD',
    }).format(value);
}
</script>

<template>
    <div
        class="relative overflow-hidden rounded-xl bg-gradient-to-br from-primary to-primaryhover p-6 text-white shadow-lg"
    >
        <div
            class="pointer-events-none absolute -top-10 -right-10 size-48 rounded-full bg-white/10"
        />
        <div
            class="pointer-events-none absolute -right-24 -bottom-16 size-64 rounded-full bg-white/5"
        />

        <div
            class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <div class="flex items-center gap-2 text-sm text-white/80">
                    <Wallet class="size-4" />
                    Balance neto · {{ currentMonthLabel }}
                </div>
                <p
                    class="mt-1 text-4xl font-semibold tracking-tight tabular-nums"
                >
                    {{ formatMoney(currentBalance) }}
                </p>
                <div class="mt-2">
                    <span
                        class="inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-xs font-medium text-white"
                    >
                        <TrendingUp
                            v-if="trendDirection === 'up'"
                            class="size-3"
                        />
                        <TrendingDown
                            v-else-if="trendDirection === 'down'"
                            class="size-3"
                        />
                        <Minus v-else class="size-3" />
                        {{ trendText }}
                    </span>
                </div>
            </div>

            <div class="flex gap-6">
                <div>
                    <div
                        class="flex items-center gap-1.5 text-xs text-white/70"
                    >
                        <ArrowUpRight class="size-3.5" />
                        Ingresos
                    </div>
                    <p class="mt-0.5 text-lg font-medium tabular-nums">
                        {{ formatMoney(currentIncome) }}
                    </p>
                </div>
                <div>
                    <div
                        class="flex items-center gap-1.5 text-xs text-white/70"
                    >
                        <ArrowDownRight class="size-3.5" />
                        Egresos
                    </div>
                    <p class="mt-0.5 text-lg font-medium tabular-nums">
                        {{ formatMoney(currentExpense) }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

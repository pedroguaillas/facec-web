<script setup lang="ts">
import { Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { MonthlyAmount } from '@/types/dashboard';

const props = defineProps<{
    income: MonthlyAmount[];
    expenses: MonthlyAmount[];
}>();

const showTable = ref(false);

const months = computed(() => {
    const periods = new Set([
        ...props.income.map((i) => i.period),
        ...props.expenses.map((e) => e.period),
    ]);

    return [...periods].sort().map((period) => {
        const income = props.income.find((i) => i.period === period);
        const expense = props.expenses.find((e) => e.period === period);

        return {
            period,
            name: income?.name ?? expense?.name ?? period,
            income: income?.total ?? 0,
            expense: expense?.total ?? 0,
        };
    });
});

const maxValue = computed(() => {
    const values = months.value.flatMap((m) => [m.income, m.expense]);

    return Math.max(1, ...values) * 1.15;
});

const width = 600;
const height = 200;

function xFor(index: number): number {
    const count = months.value.length;

    return count <= 1 ? width / 2 : (index / (count - 1)) * width;
}

function yFor(value: number): number {
    return height - (value / maxValue.value) * height;
}

/** Smooth path through points using horizontal-tangent bezier segments. */
function smoothPath(values: number[]): string {
    if (values.length === 0) {
        return '';
    }

    const points = values.map((v, i) => [xFor(i), yFor(v)]);

    if (points.length === 1) {
        const [x, y] = points[0];

        return `M ${x} ${y}`;
    }

    let path = `M ${points[0][0]} ${points[0][1]}`;

    for (let i = 0; i < points.length - 1; i++) {
        const [x0, y0] = points[i];
        const [x1, y1] = points[i + 1];
        const midX = (x0 + x1) / 2;
        path += ` C ${midX} ${y0}, ${midX} ${y1}, ${x1} ${y1}`;
    }

    return path;
}

const incomeLine = computed(() =>
    smoothPath(months.value.map((m) => m.income)),
);
const expenseLine = computed(() =>
    smoothPath(months.value.map((m) => m.expense)),
);

const incomeArea = computed(() => {
    if (months.value.length === 0) {
        return '';
    }

    const lastX = xFor(months.value.length - 1);

    return `${incomeLine.value} L ${lastX} ${height} L 0 ${height} Z`;
});

const expenseArea = computed(() => {
    if (months.value.length === 0) {
        return '';
    }

    const lastX = xFor(months.value.length - 1);

    return `${expenseLine.value} L ${lastX} ${height} L 0 ${height} Z`;
});

const gridLines = computed(() => {
    const steps = 4;

    return Array.from({ length: steps + 1 }, (_, i) => {
        const value = (maxValue.value / steps) * i;

        return { value, y: yFor(value) };
    });
});

function formatMoney(value: number): string {
    return new Intl.NumberFormat('es-EC', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    }).format(value);
}

const hoverIndex = ref<number | null>(null);

function onMouseMove(event: MouseEvent) {
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const fraction = (event.clientX - rect.left) / rect.width;
    const count = months.value.length;

    if (count === 0) {
        return;
    }

    hoverIndex.value = Math.min(
        count - 1,
        Math.max(0, Math.round(fraction * (count - 1))),
    );
}

const hoveredMonth = computed(() =>
    hoverIndex.value !== null ? months.value[hoverIndex.value] : null,
);
const hoverXPercent = computed(() =>
    hoverIndex.value !== null && months.value.length > 1
        ? (hoverIndex.value / (months.value.length - 1)) * 100
        : 0,
);
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-4 text-sm">
                <span class="flex items-center gap-1.5 text-foreground">
                    <span class="size-2.5 rounded-full bg-success" />
                    Ingresos
                </span>
                <span class="flex items-center gap-1.5 text-foreground">
                    <span class="size-2.5 rounded-full bg-danger" />
                    Egresos
                </span>
            </div>
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                @click="showTable = !showTable"
            >
                <Table2 class="size-3.5" />
                {{ showTable ? 'Ver gráfico' : 'Ver tabla' }}
            </button>
        </div>

        <div
            v-if="months.length === 0"
            class="py-10 text-center text-sm text-muted-foreground"
        >
            No hay datos suficientes para mostrar el gráfico.
        </div>

        <table v-else-if="showTable" class="w-full text-sm">
            <thead>
                <tr
                    class="border-b border-border text-left text-muted-foreground"
                >
                    <th class="py-2 font-medium">Mes</th>
                    <th class="py-2 font-medium">Ingresos</th>
                    <th class="py-2 font-medium">Egresos</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="month in months"
                    :key="month.period"
                    class="border-b border-border last:border-0"
                >
                    <td class="py-2 text-foreground">{{ month.name }}</td>
                    <td class="py-2 text-foreground tabular-nums">
                        {{ formatMoney(month.income) }}
                    </td>
                    <td class="py-2 text-foreground tabular-nums">
                        {{ formatMoney(month.expense) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-else class="relative">
            <div
                v-if="hoveredMonth"
                class="pointer-events-none absolute z-10 -translate-x-1/2 rounded-md border border-border bg-popover px-3 py-2 text-xs shadow-md"
                :style="{ left: `${hoverXPercent}%`, top: '0px' }"
            >
                <p class="mb-1 font-medium text-popover-foreground">
                    {{ hoveredMonth.name }}
                </p>
                <p class="flex items-center gap-1.5 text-success">
                    <span class="size-1.5 rounded-full bg-success" />
                    {{ formatMoney(hoveredMonth.income) }}
                </p>
                <p class="flex items-center gap-1.5 text-danger">
                    <span class="size-1.5 rounded-full bg-danger" />
                    {{ formatMoney(hoveredMonth.expense) }}
                </p>
            </div>

            <div class="flex">
                <div
                    class="flex flex-col justify-between pr-2 text-right text-xs text-muted-foreground"
                    :style="{ height: `${height}px` }"
                >
                    <span
                        v-for="line in [...gridLines].reverse()"
                        :key="line.value"
                        >{{ formatMoney(line.value) }}</span
                    >
                </div>

                <div
                    class="relative flex-1"
                    @mousemove="onMouseMove"
                    @mouseleave="hoverIndex = null"
                >
                    <div
                        v-for="line in gridLines"
                        :key="line.value"
                        class="absolute right-0 left-0 border-t border-border/60"
                        :style="{ top: `${line.y}px` }"
                    />

                    <div
                        v-if="hoveredMonth"
                        class="pointer-events-none absolute top-0 bottom-0 w-px bg-border"
                        :style="{ left: `${hoverXPercent}%` }"
                    />

                    <svg
                        :viewBox="`0 0 ${width} ${height}`"
                        preserveAspectRatio="none"
                        class="block w-full"
                        :style="{ height: `${height}px` }"
                    >
                        <defs>
                            <linearGradient
                                id="dash-income-gradient"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stop-color="var(--success)"
                                    stop-opacity="0.25"
                                />
                                <stop
                                    offset="100%"
                                    stop-color="var(--success)"
                                    stop-opacity="0"
                                />
                            </linearGradient>
                            <linearGradient
                                id="dash-expense-gradient"
                                x1="0"
                                y1="0"
                                x2="0"
                                y2="1"
                            >
                                <stop
                                    offset="0%"
                                    stop-color="var(--danger)"
                                    stop-opacity="0.18"
                                />
                                <stop
                                    offset="100%"
                                    stop-color="var(--danger)"
                                    stop-opacity="0"
                                />
                            </linearGradient>
                        </defs>

                        <path
                            :d="incomeArea"
                            fill="url(#dash-income-gradient)"
                        />
                        <path
                            :d="expenseArea"
                            fill="url(#dash-expense-gradient)"
                        />
                        <path
                            :d="incomeLine"
                            fill="none"
                            stroke="var(--success)"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                        <path
                            :d="expenseLine"
                            fill="none"
                            stroke="var(--danger)"
                            stroke-width="2"
                            stroke-linecap="round"
                        />

                        <template
                            v-for="(month, index) in months"
                            :key="month.period"
                        >
                            <circle
                                :cx="xFor(index)"
                                :cy="yFor(month.income)"
                                r="3.5"
                                fill="var(--success)"
                                stroke="var(--card)"
                                stroke-width="1.5"
                            />
                            <circle
                                :cx="xFor(index)"
                                :cy="yFor(month.expense)"
                                r="3.5"
                                fill="var(--danger)"
                                stroke="var(--card)"
                                stroke-width="1.5"
                            />
                        </template>
                    </svg>

                    <div
                        class="mt-2 flex justify-between text-xs text-muted-foreground"
                    >
                        <span v-for="month in months" :key="month.period">{{
                            month.name
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

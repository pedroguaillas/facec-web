<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { VOUCHER_TYPE } from '@/types/order';
import type { OrderListItem } from '@/types/order';

const props = defineProps<{
    order: OrderListItem;
}>();

const PROCESS_LABELS: Record<string, string> = {
    CREADO: 'Procesar',
    FIRMADO: 'Enviar y procesar',
    ENVIADO: 'Autorizar',
    RECIBIDA: 'Autorizar',
    EN_PROCESO: 'Autorizar',
    DEVUELTA: 'Volver a procesar',
    NO_AUTORIZADO: 'Volver a procesar',
};

const stateKey = computed(() =>
    (props.order.state || 'CREADO').replace(/ /g, '_'),
);
const isAuthorized = computed(() => props.order.state === 'AUTORIZADO');
const isCancelled = computed(() => props.order.state === 'ANULADO');
const processLabel = computed(() => PROCESS_LABELS[stateKey.value]);

const voucherName = computed(() =>
    props.order.voucher_type === VOUCHER_TYPE.Invoice
        ? 'Factura'
        : 'Nota de Crédito',
);

const cancelDialogOpen = ref(false);
const processing = ref(false);

function openInNewTab(path: string) {
    window.open(path, '_blank', 'noopener');
}

function process() {
    processing.value = true;
    router.post(
        `/orders/${props.order.id}/process`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function confirmCancel() {
    processing.value = true;
    router.post(
        `/orders/${props.order.id}/cancel`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                cancelDialogOpen.value = false;
            },
        },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon-sm" aria-label="Acciones">
                <ChevronLeft />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem @select="openInNewTab(`/orders/${order.id}/pdf`)">
                Ver Pdf
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="!isCancelled && isAuthorized"
                class="text-destructive"
                @select="cancelDialogOpen = true"
            >
                Anular
            </DropdownMenuItem>
            <DropdownMenuItem
                v-else-if="!isCancelled && processLabel"
                @select="process"
            >
                {{ processLabel }}
            </DropdownMenuItem>
            <DropdownMenuItem @select="openInNewTab(`/orders/${order.id}/xml`)">
                Descargar Xml
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="cancelDialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Anular comprobante</DialogTitle>
                <DialogDescription>
                    ¿Está seguro de anular {{ voucherName }} {{ order.serie }}?
                    Para anular el comprobante en este sistema, primero debe
                    anularlo en el SRI.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="cancelDialogOpen = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    @click="confirmCancel"
                >
                    Anular
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

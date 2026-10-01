<script setup>
import { computed } from 'vue';
import { Ban, Banknote, Ellipsis, FileText, ListTree } from 'lucide-vue-next';
import Button from '@/Components/Shadcn/Button.vue';
import DropdownMenu from '@/Components/Shadcn/DropdownMenu.vue';

/**
 * Les actions d'une paie, au même endroit dans chaque présentation : le geste attendu en
 * bouton (« Marquer payé » pour une paie à payer, « Bulletin » pour une paie payée), le
 * reste dans « … ». Une entrée indisponible dit pourquoi.
 */
const props = defineProps({
    row: { type: Object, required: true },
    canPay: { type: Boolean, default: false },
    canCancel: { type: Boolean, default: false },
    /** Le détail s'ouvre depuis le menu (tableau, grille) ; la vue détaillée le montre déjà. */
    withDetails: { type: Boolean, default: true },
    expanded: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['pay', 'cancel', 'details', 'payslip']);

const primary = computed(() => {
    if (! props.row.payment && props.row.payable && props.canPay) return 'pay';

    return 'payslip';
});

const items = computed(() => {
    const list = [];
    if (props.withDetails) {
        list.push({ key: 'details', label: props.expanded ? 'Masquer le détail' : 'Voir le détail', icon: ListTree, description: 'Gains, retenues et charges patronales' });
    }
    if (primary.value !== 'payslip') {
        list.push({ key: 'payslip', label: 'Bulletin de paie', icon: FileText, description: props.row.payment ? null : 'Provisoire tant que la paie n’est pas payée' });
    }
    if (props.row.payment) {
        list.push({
            key: 'cancel',
            label: 'Annuler la paie',
            icon: Ban,
            destructive: true,
            separatorBefore: list.length > 0,
            disabled: ! props.canCancel,
            description: props.canCancel ? 'Avantages remis en attente, retenues de dettes annulées' : 'Demande le droit « salary_payments.cancel »',
        });
    } else if (props.row.payable && ! props.canPay) {
        list.push({ key: 'no-pay', label: 'Marquer payé', icon: Banknote, disabled: true, separatorBefore: list.length > 0, description: 'Demande le droit « salary_payments.pay »' });
    }

    return list;
});

const onSelect = (key) => {
    if (key === 'details') emit('details');
    else if (key === 'payslip') emit('payslip');
    else if (key === 'cancel') emit('cancel');
};
</script>

<template>
    <div class="flex items-center justify-end gap-1.5">
        <Button v-if="primary === 'pay'" type="button" :size="props.compact ? 'xs' : 'sm'" variant="success" @click="emit('pay')">
            <Banknote class="h-4 w-4" />Marquer payé
        </Button>
        <Button v-else type="button" :size="props.compact ? 'xs' : 'sm'" variant="outline" @click="emit('payslip')">
            <FileText class="h-4 w-4" />Bulletin
        </Button>
        <DropdownMenu v-if="items.length" :items="items" label="Paie" @select="onSelect">
            <template #trigger>
                <Button type="button" size="icon" variant="ghost" :aria-label="`Plus d’actions pour la paie de ${props.row.name}`">
                    <Ellipsis class="h-4 w-4" />
                </Button>
            </template>
        </DropdownMenu>
    </div>
</template>

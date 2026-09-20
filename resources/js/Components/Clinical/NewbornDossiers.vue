<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Baby, ClipboardList, FileText, UserRound } from 'lucide-vue-next';
import Badge from '@/Components/Shadcn/Badge.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { cn } from '@/lib/cn';

/**
 * ADR-145, ADR-146 — les nouveau-nés d'une mère, à un seul endroit.
 *
 * Le bébé vit d'abord **dans le dossier de sa mère** : son dossier médical s'ouvre dès que la Maternité
 * l'a consigné, sans attendre qu'il soit patient. Il ne le devient qu'à l'accueil — la Réception le choisit
 * dans l'arborescence de sa mère, en un clic. Ce composant n'a donc plus aucun geste de création : il montre
 * qui est là, s'il est déjà patient, et mène à son dossier.
 *
 * Les données viennent de `MaternitySheetSection::forPassage()` : rien n'est recalculé ici.
 */
const props = defineProps({
    /** La projection serveur ; `null` : rien à afficher (aucun dossier Maternité, ou pas le droit de le lire). */
    babies: { type: Object, default: null },
    /** Le dossier Maternité lui-même n'a pas besoin d'un lien vers lui-même. */
    showMaternityLink: { type: Boolean, default: true },
});

const list = computed(() => props.babies?.newborns ?? []);
const patients = computed(() => list.value.filter((baby) => baby.patient_number).length);

const sexTone = (baby) => ({
    Féminin: 'bg-pink-50 text-pink-600 dark:bg-pink-950/40 dark:text-pink-300',
    Masculin: 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
}[baby.sex] ?? 'bg-muted text-muted-foreground');

const summary = (baby) => [baby.sex, baby.birth_weight_g ? `${baby.birth_weight_g} g` : null].filter(Boolean).join(' · ');
</script>

<template>
    <Card v-if="babies" class="overflow-hidden">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground">
                    <Baby class="h-4 w-4" aria-hidden="true" />Nouveau-nés
                    <Badge v-if="list.length" variant="outline">{{ patients }} / {{ list.length }} patient{{ patients > 1 ? 's' : '' }}</Badge>
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">Chaque bébé a son dossier ici, chez sa mère. Il devient patient à l'accueil, quand la Réception le choisit ; ses soins restent sur le compte de la mère.</p>
            </div>
            <Button v-if="showMaternityLink && babies.maternity_url" :as="Link" :href="babies.maternity_url" variant="outline" size="sm">
                <ClipboardList class="h-4 w-4" />Dossier Maternité
            </Button>
        </header>

        <ul v-if="list.length" class="divide-y divide-border">
            <li v-for="baby in list" :key="baby.rank" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                <span :class="cn('grid h-10 w-10 shrink-0 place-items-center rounded-full', sexTone(baby))" aria-hidden="true">
                    <Baby class="h-5 w-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-foreground">
                        {{ baby.name }}
                        <span v-if="summary(baby)" class="font-normal text-muted-foreground">· {{ summary(baby) }}</span>
                    </p>
                    <p v-if="baby.patient_number" class="font-mono text-xs text-muted-foreground">{{ baby.patient_number }}</p>
                    <p v-else class="text-xs text-muted-foreground">{{ baby.filled ? 'Pas encore patient — dossier ouvert à l’accueil' : 'Fiche non renseignée' }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button v-if="baby.medical_record_url" :as="Link" :href="baby.medical_record_url" size="sm" variant="outline">
                        <FileText class="h-4 w-4" />Dossier médical
                    </Button>
                    <Button v-if="baby.patient_url" :as="Link" :href="baby.patient_url" size="sm" variant="ghost">
                        <UserRound class="h-4 w-4" />Dossier patient
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="px-5 py-6 text-center text-sm text-muted-foreground">Aucune fiche de nouveau-né dans ce dossier Maternité.</p>
    </Card>
</template>

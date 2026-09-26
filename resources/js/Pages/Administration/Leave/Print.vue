<script setup>
import { hrSiteName, hrUrl } from '@/utilities/hrUrl';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FileSignature, FileText, Printer } from 'lucide-vue-next';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/Shadcn/Card.vue';
import { usePermissions } from '@/composables/usePermissions';

defineOptions({ layout: AppLayout });
const props = defineProps({
    leave: Object,
    /** ADR-198 — les canevas « Personnel + demande de congé » publiés par le Super Admin. */
    templates: { type: Array, default: () => [] },
    /** Les documents déjà générés pour ce congé. */
    documents: { type: Array, default: () => [] },
});

const page = usePage();
const { can } = usePermissions();
const formatDate = (value) => value
    ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
    : 'N/R';
const formatDateTime = (value) => (value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '');
const printPage = () => window.print();

// Le document officiel : le canevas choisi, rempli pour ce congé (ADR-070, comme un contrat).
const generateHref = (template) => hrUrl(`/administration/generated-documents/create?${new URLSearchParams({
    template: template.uuid,
    employee: props.leave.employee.uuid,
    leave: props.leave.uuid,
})}`);
</script>

<template>
    <Head title="Demande de congé" />

    <div class="mx-auto max-w-4xl space-y-3">
        <div class="print-actions flex flex-wrap justify-between gap-2">
            <Button :as="Link" :href="hrUrl('/administration/leave')" variant="outline"><ArrowLeft class="h-4 w-4" />Retour aux congés</Button>
            <Button @click="printPage"><Printer class="h-4 w-4" />Imprimer la fiche de demande</Button>
        </div>

        <!-- ADR-198 — le document officiel du congé : un canevas du Super Admin, rempli pour ce congé. -->
        <Card class="print-actions p-5">
            <div class="flex items-start gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileSignature class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <h1 class="font-heading text-base font-bold text-foreground">Document officiel du congé</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">Le courrier ou la décision de congé composé par le Super Administrateur : la page 1 reprend {{ leave.employee.name }}, le type, les dates et le motif ; le texte du canevas suit, tel quel.</p>
                </div>
            </div>

            <div v-if="templates.length" class="mt-4 grid gap-2 sm:grid-cols-2">
                <Link
                    v-for="template in templates"
                    :key="template.uuid"
                    :href="generateHref(template)"
                    class="group flex items-center gap-3 rounded-xl border border-border p-3 transition hover:border-primary/40 hover:bg-accent/50"
                >
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary"><FileText class="h-4 w-4" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-foreground">{{ template.name }}</span>
                        <span class="block text-xs text-muted-foreground">{{ template.document_type }} · générer pour ce congé</span>
                    </span>
                    <Printer class="h-4 w-4 text-muted-foreground group-hover:text-primary" />
                </Link>
            </div>
            <p v-else-if="leave.status === 'CANCELLED'" class="mt-4 text-sm text-muted-foreground">Ce congé est annulé : seule sa fiche de demande s’imprime.</p>
            <p v-else-if="can('generated_documents.create')" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Aucun canevas de congé n’est publié pour ce site. Le Super Administrateur doit en composer un avec le contexte « Personnel + demande de congé ». En attendant, la fiche de demande s’imprime ci-dessous.</p>
            <p v-else class="mt-4 text-sm text-muted-foreground">Votre compte n’a pas le droit de générer un document : seule la fiche de demande s’imprime.</p>

            <div v-if="documents.length" class="mt-4 border-t border-border pt-3">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Déjà générés pour ce congé</p>
                <ul class="mt-2 space-y-1">
                    <li v-for="document in documents" :key="document.uuid">
                        <Link :href="hrUrl(`/administration/generated-documents/${document.uuid}/print`)" class="inline-flex items-center gap-2 text-sm text-primary hover:underline">
                            <FileText class="h-4 w-4" />{{ document.template_name }}<span class="text-xs text-muted-foreground">· {{ formatDateTime(document.created_at) }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </Card>

        <article class="print-doc bg-white p-8 text-slate-900">
            <header class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-4">
                <div>
                    <strong class="uppercase">{{ page.props.site?.brand || 'Clinique Saint Georges' }}</strong>
                    <p class="text-xs">Site {{ hrSiteName() }}</p>
                </div>
                <div class="text-end">
                    <h1 class="text-xl font-black uppercase">Demande de congé</h1>
                    <p class="mt-1 font-mono text-[10px]">{{ leave.uuid }}</p>
                    <p class="mt-1 text-xs">Enregistrée le {{ formatDate(leave.requested_on) }}</p>
                </div>
            </header>

            <section class="mt-7 rounded border border-slate-300 p-4">
                <h2 class="text-xs font-black uppercase tracking-wider">Demandeur</h2>
                <div class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 text-sm">
                    <p><strong>Nom et prénoms</strong><br>{{ leave.employee.name }}</p>
                    <p><strong>Matricule</strong><br>{{ leave.employee.employee_number }}</p>
                    <p><strong>Fonction</strong><br>{{ leave.employee.job_title || 'N/R' }}</p>
                    <p><strong>Département</strong><br>{{ leave.employee.department || 'N/R' }}</p>
                </div>
            </section>

            <section class="mt-4 rounded border border-slate-300 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xs font-black uppercase tracking-wider">Période demandée</h2>
                        <p class="mt-1 text-sm font-bold">{{ leave.leave_type || 'Type non renseigné' }}</p>
                    </div>
                    <span class="rounded-full border border-slate-300 px-3 py-1 text-xs font-bold">{{ leave.status_label }}</span>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 text-sm">
                    <p><strong>Premier jour</strong><br>{{ formatDate(leave.starts_on) }}</p>
                    <p><strong>Dernier jour demandé</strong><br>{{ formatDate(leave.returns_on) }}</p>
                    <p><strong>Durée calculée</strong><br>{{ leave.days_requested ?? 'N/R' }} jour(s)</p>
                    <p><strong>Méthode</strong><br>{{ leave.day_count_method_snapshot === 'WEEKDAYS_INCLUSIVE' ? 'Jours ouvrés, lundi à vendredi' : 'Jours calendaires inclusifs' }}</p>
                    <p v-if="leave.consumes_balance_snapshot"><strong>{{ leave.status === 'APPROVED' ? 'Solde ferme après cette approbation' : 'Solde ferme actuel, demande non déduite' }}</strong><br>{{ leave.remaining_days_snapshot ?? 'N/R' }} jour(s)</p>
                    <p v-if="leave.consumes_balance_snapshot"><strong>Solde projeté avec demandes en attente</strong><br>{{ leave.projected_remaining_days_snapshot ?? 'N/R' }} jour(s)</p>
                </div>
            </section>

            <section class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 rounded border border-slate-300 p-4 text-sm">
                <p><strong>Intérimaire</strong><br>{{ leave.interim_employee?.name || 'N/R' }}</p>
                <p><strong>Téléphone d’urgence</strong><br>{{ leave.emergency_phone || 'N/R' }}</p>
                <p class="col-span-2"><strong>Adresse pendant le congé</strong><br>{{ leave.leave_address || 'N/R' }}</p>
                <p class="col-span-2 whitespace-pre-line"><strong>Motif</strong><br>{{ leave.reason }}</p>
                <p v-if="leave.decision_reason || leave.cancel_reason" class="col-span-2 whitespace-pre-line"><strong>Décision / observation</strong><br>{{ leave.decision_reason || leave.cancel_reason }}</p>
            </section>

            <footer class="mt-16 grid grid-cols-3 gap-10 text-center text-xs">
                <p class="border-t border-slate-500 pt-2">Demandeur</p>
                <p class="border-t border-slate-500 pt-2">Intérimaire</p>
                <p class="border-t border-slate-500 pt-2">Administration / RH</p>
            </footer>
        </article>
    </div>
</template>

<style>
@media print {
    .print-actions,
    aside,
    nav {
        display: none !important;
    }

    .print-doc {
        padding: 0 !important;
    }
}
</style>

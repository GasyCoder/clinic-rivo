<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import QueueCounters from '@/Components/Clinical/QueueCounters.vue';
import Button from '@/Components/Shadcn/Button.vue';
import Card from '@/Components/UI/Card.vue';
import { Baby, CircleCheck, FileText, HeartPulse, Search, UserCheck } from 'lucide-vue-next';
import IconInput from '@/Components/Shadcn/IconInput.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ orientations: Object, counts: Object, filter: String, search: String });
const q = ref(props.search ?? '');
const visit = (filter = props.filter) => router.get('/maternity', { filter, q: q.value || undefined }, { preserveState: true, replace: true });
const patientName = (item) => `${item.episode.patient.first_name} ${item.episode.patient.last_name}`;
const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';

/**
 * Les deux cartes étaient écrites à la main ici : elles rejoignent le
 * composant partagé pour qu'une file ne change pas d'allure selon le
 * service. Le compte reste celui du serveur.
 */
const counterTiles = computed(() => [
    { value: 'active', label: 'À prendre en charge', hint: 'Patientes en attente', icon: Baby, tone: 'red', count: props.counts.active, active: props.filter === 'active' },
    { value: 'completed', label: 'Prises en charge terminées', hint: 'Dossiers clos', icon: CircleCheck, tone: 'emerald', count: props.counts.completed, active: props.filter === 'completed' },
]);
</script>

<template>
    <Head title="Maternité" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-md bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300"><HeartPulse class="h-5 w-5" /></span>
                <div><p class="text-xs font-bold uppercase tracking-wide text-rose-600">Workspace paramédical spécialisé</p><h1 class="mt-0.5 text-2xl font-bold text-foreground">Maternité</h1><p class="mt-1 text-sm text-muted-foreground">Orientations, suivi obstétrical, accouchement et nouveau-né sur le même passage.</p></div>
            </div>
            <div class="w-full lg:w-96"><IconInput v-model="q" :icon="Search" placeholder="Patiente, numéro patient ou passage…" @keyup.enter="visit()" /></div>
        </header>

        <QueueCounters class="lg:grid-cols-2" :tiles="counterTiles" @select="visit" />

        <Card class="overflow-hidden shadow-sm">
            <div class="divide-y divide-border">
                <article v-for="orientation in orientations.data" :key="orientation.uuid" class="grid gap-4 p-4 transition hover:bg-muted/60 dark:hover:bg-muted0/30 lg:grid-cols-[70px_minmax(0,1.5fr)_minmax(180px,1fr)_auto] lg:items-center">
                    <div class="text-center"><span v-if="orientation.queue_number" class="text-[10px] font-bold uppercase text-muted-foreground">File</span><strong class="block text-xl text-rose-700">{{ orientation.queue_number ? `N° ${orientation.queue_number}` : '—' }}</strong></div>
                    <div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold text-foreground">{{ patientName(orientation) }}</h2><span v-if="orientation.episode.priority === 'EMERGENCY'" class="rounded bg-red-600 px-2 py-0.5 text-[9px] font-bold uppercase text-white">Urgence</span></div><p class="mt-1 text-xs text-muted-foreground"><span class="font-mono">{{ orientation.episode.patient.patient_number }}</span> · Passage {{ orientation.episode.episode_number }}</p><p v-if="orientation.reason" class="mt-2 line-clamp-2 text-xs text-muted-foreground">{{ orientation.reason }}</p></div>
                    <div class="text-xs text-muted-foreground"><p class="font-semibold">{{ orientation.status_label }}</p><p class="mt-1">Orientée le {{ formatDate(orientation.oriented_at) }}</p><p v-if="orientation.accepted_by" class="mt-1">Prise par {{ orientation.accepted_by }}</p></div>
                    <div>
                        <Link v-if="orientation.status === 'PENDING'" :href="`/maternity/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll><Button size="rg"><UserCheck class="h-4 w-4" />Prendre en charge</Button></Link>
                        <Button v-else :as="Link" :href="`/maternity/orientations/${orientation.uuid}`" size="rg" variant="white-outline"><FileText class="h-4 w-4" />{{ orientation.status === 'COMPLETED' ? 'Consulter' : 'Ouvrir le dossier' }}</Button>
                    </div>
                </article>
                <div v-if="!orientations.data.length" class="px-5 py-16 text-center"><HeartPulse class="h-8 w-8 text-muted-foreground" /><p class="mt-3 text-sm text-muted-foreground">Aucune orientation Maternité dans cette vue.</p></div>
            </div>
            <div v-if="orientations.links?.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-border p-4"><Link v-for="link in orientations.links" :key="link.label" :href="link.url || '#'" :class="['rounded border px-3 py-1.5 text-xs font-semibold', link.active ? 'border-rose-600 bg-rose-600 text-white' : 'border-border text-muted-foreground ', !link.url && 'pointer-events-none opacity-40']" v-html="link.label" /></div>
        </Card>
    </div>
</template>

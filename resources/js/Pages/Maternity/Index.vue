<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Card from '@/Components/UI/Card.vue';
import Icon from '@/Components/UI/Icon.vue';
import IconInput from '@/Components/UI/IconInput.vue';

defineOptions({ layout: AppLayout });
const props = defineProps({ orientations: Object, counts: Object, filter: String, search: String });
const q = ref(props.search ?? '');
const visit = (filter = props.filter) => router.get('/maternity', { filter, q: q.value || undefined }, { preserveState: true, replace: true });
const patientName = (item) => `${item.episode.patient.first_name} ${item.episode.patient.last_name}`;
const formatDate = (value) => value ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—';
</script>

<template>
    <Head title="Maternité" />
    <div class="w-full space-y-5">
        <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-md bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300"><Icon class="text-xl" name="heart" /></span>
                <div><p class="text-xs font-bold uppercase tracking-wide text-rose-600">Workspace paramédical spécialisé</p><h1 class="mt-0.5 text-2xl font-bold text-slate-700 dark:text-white">Maternité</h1><p class="mt-1 text-sm text-slate-500">Orientations, suivi obstétrical, accouchement et nouveau-né sur le même passage.</p></div>
            </div>
            <div class="w-full lg:w-96"><IconInput v-model="q" icon="search" placeholder="Patiente, numéro patient ou passage…" @keyup.enter="visit()" /></div>
        </header>

        <div class="grid grid-cols-2 gap-3">
            <button type="button" :class="['rounded-lg border p-4 text-start transition', filter === 'active' ? 'border-rose-300 bg-rose-50/70 dark:border-rose-900 dark:bg-rose-950/20' : 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950']" @click="visit('active')"><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">À prendre en charge</span><strong class="mt-1 block text-2xl text-slate-700 dark:text-white">{{ counts.active }}</strong></button>
            <button type="button" :class="['rounded-lg border p-4 text-start transition', filter === 'completed' ? 'border-rose-300 bg-rose-50/70 dark:border-rose-900 dark:bg-rose-950/20' : 'border-gray-200 bg-white dark:border-gray-900 dark:bg-gray-950']" @click="visit('completed')"><span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Prises en charge terminées</span><strong class="mt-1 block text-2xl text-slate-700 dark:text-white">{{ counts.completed }}</strong></button>
        </div>

        <Card class="overflow-hidden shadow-sm">
            <div class="divide-y divide-gray-200 dark:divide-gray-900">
                <article v-for="orientation in orientations.data" :key="orientation.uuid" class="grid gap-4 p-4 transition hover:bg-gray-50/60 dark:hover:bg-gray-1000/30 lg:grid-cols-[70px_minmax(0,1.5fr)_minmax(180px,1fr)_auto] lg:items-center">
                    <div class="text-center"><span v-if="orientation.queue_number" class="text-[10px] font-bold uppercase text-slate-400">File</span><strong class="block text-xl text-rose-700">{{ orientation.queue_number ? `N° ${orientation.queue_number}` : '—' }}</strong></div>
                    <div><div class="flex flex-wrap items-center gap-2"><h2 class="text-sm font-bold text-slate-700 dark:text-white">{{ patientName(orientation) }}</h2><span v-if="orientation.episode.priority === 'EMERGENCY'" class="rounded bg-red-600 px-2 py-0.5 text-[9px] font-bold uppercase text-white">Urgence</span></div><p class="mt-1 text-xs text-slate-400"><span class="font-mono">{{ orientation.episode.patient.patient_number }}</span> · Passage {{ orientation.episode.episode_number }}</p><p v-if="orientation.reason" class="mt-2 line-clamp-2 text-xs text-slate-500">{{ orientation.reason }}</p></div>
                    <div class="text-xs text-slate-500"><p class="font-semibold">{{ orientation.status_label }}</p><p class="mt-1">Orientée le {{ formatDate(orientation.oriented_at) }}</p><p v-if="orientation.accepted_by" class="mt-1">Prise par {{ orientation.accepted_by }}</p></div>
                    <div>
                        <Link v-if="orientation.status === 'PENDING'" :href="`/maternity/orientations/${orientation.uuid}/accept`" method="post" as="button" preserve-scroll><Button size="rg"><Icon name="user-check" /><span class="ms-2">Prendre en charge</span></Button></Link>
                        <Button v-else :as="Link" :href="`/maternity/orientations/${orientation.uuid}`" size="rg" variant="white-outline"><Icon name="file-text" /><span class="ms-2">{{ orientation.status === 'COMPLETED' ? 'Consulter' : 'Ouvrir le dossier' }}</span></Button>
                    </div>
                </article>
                <div v-if="!orientations.data.length" class="px-5 py-16 text-center"><Icon class="text-3xl text-slate-300" name="heart" /><p class="mt-3 text-sm text-slate-400">Aucune orientation Maternité dans cette vue.</p></div>
            </div>
            <div v-if="orientations.links?.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-gray-200 p-4 dark:border-gray-900"><Link v-for="link in orientations.links" :key="link.label" :href="link.url || '#'" :class="['rounded border px-3 py-1.5 text-xs font-semibold', link.active ? 'border-rose-600 bg-rose-600 text-white' : 'border-gray-200 text-slate-500 dark:border-gray-800', !link.url && 'pointer-events-none opacity-40']" v-html="link.label" /></div>
        </Card>
    </div>
</template>

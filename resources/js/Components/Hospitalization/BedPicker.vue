<script setup>
import { computed, ref } from 'vue';
import Badge from '@/Components/Shadcn/Badge.vue';
import Input from '@/Components/Shadcn/Input.vue';
import { careLevelVariant } from '@/utilities/hospitalBeds';
import { BedDouble, Search } from 'lucide-vue-next';

/**
 * ADR-164 — choisir un lit libre : services › chambres › lits. Le serveur ne
 * sert que les lits libres ; il revérifie de toute façon sous verrou, car un
 * collègue peut prendre le même lit entre l'ouverture et le clic.
 */
const props = defineProps({
    services: { type: Array, default: () => [] },
    modelValue: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const filtered = computed(() => {
    const needle = normalize(query.value).trim();

    if (!needle) return props.services;

    return props.services
        .map((service) => ({
            ...service,
            rooms: service.rooms
                .map((room) => ({
                    ...room,
                    beds: normalize(`${service.name} ${room.name}`).includes(needle)
                        ? room.beds
                        : room.beds.filter((bed) => normalize(bed.label).includes(needle)),
                }))
                .filter((room) => room.beds.length),
        }))
        .filter((service) => service.rooms.length);
});

const freeTotal = computed(() => props.services.reduce(
    (total, service) => total + service.rooms.reduce((sum, room) => sum + room.beds.length, 0),
    0,
));
</script>

<template>
    <div class="space-y-3">
        <p v-if="!freeTotal" class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-800 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="status">
            <BedDouble class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            Aucun lit libre sur le site : tous sont occupés ou hors service. Un lit se libère à la sortie d’un patient.
        </p>
        <template v-else>
            <div class="relative">
                <Search class="pointer-events-none absolute start-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input v-model="query" class="ps-8" placeholder="Rechercher un service, une chambre, un lit" aria-label="Rechercher un lit libre" />
            </div>
            <div class="max-h-80 space-y-3 overflow-y-auto pe-1" role="radiogroup" aria-label="Lits libres">
                <p v-if="!filtered.length" class="text-sm text-muted-foreground">Aucun lit libre ne correspond.</p>
                <section v-for="service in filtered" :key="service.uuid">
                    <p class="flex items-center gap-2 text-xs font-semibold text-foreground">
                        {{ service.name }}
                        <Badge :variant="careLevelVariant(service.care_level)" class="px-2 py-0.5 text-[11px]">{{ service.care_level_label }}</Badge>
                    </p>
                    <div v-for="room in service.rooms" :key="room.uuid" class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span class="w-24 shrink-0 truncate text-xs text-muted-foreground" :title="room.name">{{ room.name }}</span>
                        <button
                            v-for="bed in room.beds"
                            :key="bed.uuid"
                            type="button"
                            role="radio"
                            :aria-checked="modelValue === bed.uuid"
                            :aria-label="`${service.name}, ${room.name}, ${bed.label}`"
                            :class="[
                                'rounded-md border px-2.5 py-1 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30',
                                modelValue === bed.uuid
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200',
                            ]"
                            @click="emit('update:modelValue', bed.uuid)"
                        >
                            {{ bed.label }}
                        </button>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>

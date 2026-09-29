<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { User } from 'lucide-vue-next';
import { useSectionAutosave } from '@/composables/useSectionAutosave';
import { beginAutosaveVisit, endAutosaveVisit } from '@/utilities/autosaveVisits';
import EmployeeSectionCard from './EmployeeSectionCard.vue';
import IdentityFields from './IdentityFields.vue';

/**
 * ADR-221 — Identité : genre, nom, prénoms, naissance. Enregistrée toute seule ;
 * le nom et le genre restent exigés, donc rien ne part tant qu'ils sont vides.
 *
 * ADR-194 — la photo 4 × 4 part dès qu'elle est recadrée, seule, en multipart
 * (un POST qui annonce PUT) : elle n'attend pas la pause de frappe.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    options: { type: Object, required: true },
    url: { type: String, required: true },
    canEdit: { type: Boolean, default: true },
});

const { form, state, savedAt, retry } = useSectionAutosave('identity', {
    sex: props.employee.sex ?? '',
    last_name: props.employee.last_name ?? '',
    first_name: props.employee.first_name ?? '',
    birth_date: props.employee.birth_date ?? '',
    birth_place: props.employee.birth_place ?? '',
}, () => props.url, {
    canEdit: () => props.canEdit,
    ready: () => String(form.last_name ?? '').trim() !== '' && form.sex !== '',
});

const missing = computed(() => [! String(form.last_name ?? '').trim() && 'le nom', ! form.sex && 'le genre'].filter(Boolean).join(' et '));

/* Photo : envoyée seule, dès qu'elle est prête. */
const photoForm = useForm({ photo: null, remove_photo: false });
const sendPhoto = () => {
    if (! props.canEdit || (! photoForm.photo && ! photoForm.remove_photo)) return;
    beginAutosaveVisit();
    photoForm.transform((data) => ({ ...data, _autosave: true, _method: 'put' })).post(props.url, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => photoForm.reset(),
        onFinish: () => endAutosaveVisit(),
    });
};
watch(() => [photoForm.photo, photoForm.remove_photo], ([photo, remove]) => {
    if (photo || remove) sendPhoto();
});
</script>

<template>
    <EmployeeSectionCard
        :icon="User"
        title="Identité"
        description="Qui est la personne. Le nom et le genre sont exigés ; la civilité se déduit du genre."
        :state="state"
        :saved-at="savedAt"
        :incomplete-hint="missing ? `Indiquez ${missing}` : ''"
        :read-only="! canEdit"
        @retry="retry"
    >
        <IdentityFields
            v-model:photo="photoForm.photo"
            v-model:remove-photo="photoForm.remove_photo"
            :form="form"
            :options="options"
            :current-photo-url="employee.photo_url"
            :photo-error="photoForm.errors.photo"
            :sending="photoForm.processing"
            :can-edit="canEdit"
            photo-subtitle="Facultative : elle part dès qu’elle est recadrée."
        />
    </EmployeeSectionCard>
</template>

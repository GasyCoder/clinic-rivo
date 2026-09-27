<script setup>
import { computed, nextTick, ref } from 'vue';
import { FlaskConical, ListChecks, ScanLine } from 'lucide-vue-next';
import StayExams from '@/Components/Hospitalization/StayExams.vue';
import PregnancyParaclinicalHistory from '@/Components/Maternity/PregnancyParaclinicalHistory.vue';
import PrenatalRecommendations from '@/Components/Maternity/PrenatalRecommendations.vue';
import Tabs from '@/Components/Shadcn/Tabs.vue';
import TabsContent from '@/Components/Shadcn/TabsContent.vue';
import TabsList from '@/Components/Shadcn/TabsList.vue';
import TabsTrigger from '@/Components/Shadcn/TabsTrigger.vue';

/**
 * ADR-204 — les examens complémentaires d'une prise en charge Maternité.
 *
 * Aucun second système : les analyses partent au Laboratoire (`LabRequest`),
 * l'imagerie par `ImagingRequest`, avec la même facturation à la demande, la
 * même confirmation signée et la même fenêtre de compte rendu qu'en
 * consultation ou au séjour. Leurs résultats restent la source de vérité : la
 * Maternité les lit, elle n'en garde aucune copie.
 *
 * Un résultat en attente n'empêche ni « Suivant », ni de terminer.
 */
const props = defineProps({
    orientationUuid: { type: String, required: true },
    labRequests: { type: Array, default: null },
    imagingRequests: { type: Array, default: null },
    options: { type: Object, default: () => ({}) },
    capabilities: { type: Object, required: true },
    history: { type: Object, default: null },
    advice: { type: Object, default: null },
    showRecommendations: { type: Boolean, default: false },
    patientLabel: { type: String, default: '' },
    /** La prise en charge est en cours : sans elle, rien ne se demande, et ce n'est pas un droit qui manque. */
    active: { type: Boolean, default: true },
});

const tab = ref('lab');
const labPanel = ref(null);
const imagingPanel = ref(null);

const pendingCount = computed(() => props.history?.counts?.pending ?? 0);

/** « Demander » depuis un rappel : l'examen rejoint la sélection, à confirmer. */
const requestSuggestion = async (suggestion) => {
    tab.value = suggestion.category === 'LAB' ? 'lab' : 'imaging';
    await nextTick();
    (suggestion.category === 'LAB' ? labPanel.value : imagingPanel.value)
        ?.select(suggestion.category === 'LAB' ? 'lab' : 'imaging', suggestion.catalog_item_uuid);
};

const shared = computed(() => ({
    baseUrl: `/maternity/orientations/${props.orientationUuid}`,
    labRequests: props.labRequests,
    imagingRequests: props.imagingRequests,
    labCatalog: props.options.lab_catalog ?? [],
    imagingCatalog: props.options.imaging_catalog ?? [],
    canRequestLab: Boolean(props.capabilities.can_request_lab),
    canRequestImaging: Boolean(props.capabilities.can_request_imaging),
    orientationUuid: props.orientationUuid,
    templates: props.options.imaging_report_templates ?? [],
    templateRights: props.options.imaging_report_template_rights ?? {},
    patientLabel: props.patientLabel,
    scopeLabel: 'de cette prise en charge',
    emptySource: 'cette prise en charge',
}));
</script>

<template>
    <div class="space-y-4">
        <PrenatalRecommendations
            v-if="showRecommendations && advice"
            :advice="advice"
            :can-request-lab="Boolean(capabilities.can_request_lab)"
            :can-request-imaging="Boolean(capabilities.can_request_imaging)"
            @request="requestSuggestion"
        />

        <Tabs v-model="tab">
            <TabsList class="h-auto w-full flex-wrap justify-start gap-1 sm:w-auto">
                <TabsTrigger value="lab" class="min-h-9"><FlaskConical class="h-4 w-4" aria-hidden="true" />Analyses</TabsTrigger>
                <TabsTrigger value="imaging" class="min-h-9"><ScanLine class="h-4 w-4" aria-hidden="true" />Imagerie</TabsTrigger>
                <TabsTrigger value="results" class="min-h-9">
                    <ListChecks class="h-4 w-4" aria-hidden="true" />Résultats
                    <span v-if="pendingCount" class="ms-1 rounded-full bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">{{ pendingCount }} en attente</span>
                </TabsTrigger>
            </TabsList>

            <!-- Montés en permanence : une sélection commencée survit au changement d'onglet. -->
            <TabsContent value="lab" force-mount class="data-[state=inactive]:hidden">
                <StayExams ref="labPanel" v-bind="shared" only="lab" />
            </TabsContent>
            <TabsContent value="imaging" force-mount class="data-[state=inactive]:hidden">
                <StayExams ref="imagingPanel" v-bind="shared" only="imaging" />
            </TabsContent>
            <TabsContent value="results">
                <PregnancyParaclinicalHistory :history="history" />
            </TabsContent>
        </Tabs>

        <p v-if="active && ! capabilities.can_request_lab && ! capabilities.can_request_imaging" class="text-xs text-muted-foreground">
            Demander un examen demande le droit « laboratory_orders.create » ou « imaging_orders.create », qui s’accorde dans Rôles & permissions.
        </p>
    </div>
</template>

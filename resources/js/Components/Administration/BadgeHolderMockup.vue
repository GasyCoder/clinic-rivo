<script setup>
import { computed, useId } from 'vue';
import EmployeeBadge from '@/Components/Administration/EmployeeBadge.vue';
import { BADGE_DEFAULT_DESIGN, badgeCardOf, badgePalette, formatMm } from '@/utilities/employeeBadge';

/**
 * ADR-209 — le badge tel qu'on le portera : glissé dans un porte-badge souple
 * transparent (triple perforation : une fente au centre, deux trous ronds), accroché
 * par un mousqueton à un tour de cou aux couleurs du site.
 *
 * Tout est à l'échelle, en millimètres : le porte-badge prend l'insert du format
 * choisi (carte bancaire, A6 105 × 149, 86 × 101…) plus ses bords et sa bande de
 * perforation ; cordon et mousqueton gardent leur taille réelle. Un aperçu
 * seulement : rien de ceci ne s'imprime.
 */
const props = defineProps({
    person: { type: Object, required: true },
    design: { type: Object, default: () => ({}) },
    /**
     * La hauteur visée (une longueur CSS, « 32rem ») : la largeur s'en déduit, sans
     * jamais dépasser le conteneur. Vide : toute la largeur.
     */
    height: { type: String, default: null },
});

const uid = `holder-${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const sid = (name) => `${uid}-${name}`;

const d = computed(() => ({ ...BADGE_DEFAULT_DESIGN, ...(props.design ?? {}) }));
const colors = computed(() => badgePalette(d.value));
const r1 = (value) => Math.round(value * 10) / 10;

/* --- Les mesures, en mm ---------------------------------------------- */

const m = computed(() => {
    const card = badgeCardOf(d.value);
    const landscape = card.width > card.height;
    // Le porte-badge : l'insert, un bord de chaque côté, la bande de perforation en haut.
    const side = Math.max(3.5, card.width * 0.045);
    const band = landscape ? Math.max(12, card.height * 0.2) : Math.max(14, card.width * 0.16);
    const holder = { width: card.width + 2 * side, height: card.height + band + side + 1 };
    // La scène : le cordon au-dessus, un peu d'air autour pour l'ombre.
    const pad = Math.max(6, holder.width * 0.08);
    const lanyard = Math.max(46, holder.width * 0.8);
    const scene = { width: holder.width + 2 * pad, height: lanyard + holder.height + pad };
    const x = pad;
    const y = lanyard;
    const cx = scene.width / 2;
    // La fente centrale, et les deux trous ronds de part et d'autre.
    const slot = { width: Math.min(16, holder.width * 0.28), height: 3.4, y: y + band * 0.42 };
    const hole = { r: 2.2, left: x + 7, right: x + holder.width - 7 };
    // Le mousqueton : sa boucle descend dans la fente ; l'anneau et la bague du cordon au-dessus.
    const hook = { width: 9, height: 26, bottom: slot.y + 1.2 };
    const ring = { cy: slot.y - hook.height - 2.4, r: 3.2 };
    const crimp = { width: 11, height: 6, y: ring.cy - ring.r - 5.4 };

    return { card, landscape, side, band, holder, pad, lanyard, scene, x, y, cx, slot, hole, hook, ring, crimp };
});

/** Les deux brins du cordon, du haut de la scène jusqu'à la bague. */
const straps = computed(() => {
    const { scene, cx, crimp } = m.value;
    const spread = scene.width * 0.46;
    const end = crimp.y + 1;

    return [
        `M ${r1(cx - spread)} -4 L ${r1(cx - 2.5)} ${r1(end)}`,
        `M ${r1(cx + spread)} -4 L ${r1(cx + 2.5)} ${r1(end)}`,
    ];
});
/** Où le nom s'écrit : chaque brin de gauche à droite, pour se lire à l'endroit. */
const textPaths = computed(() => {
    const { scene, cx, crimp } = m.value;
    const spread = scene.width * 0.46;
    const end = crimp.y + 1;

    return [
        `M ${r1(cx - spread)} -4 L ${r1(cx - 2.5)} ${r1(end)}`,
        `M ${r1(cx + 2.5)} ${r1(end)} L ${r1(cx + spread)} -4`,
    ];
});
/** Le nom de l'établissement, imprimé le long du cordon. */
const strapText = computed(() => {
    const brand = String(d.value.brand || '').trim().toLocaleUpperCase('fr-FR');

    return brand ? `${brand}  •  ${brand}  •  ${brand}` : '';
});

/** Où l'insert se place dans la scène, en pourcentage : le badge, en HTML, s'y glisse. */
const insertStyle = computed(() => {
    const { scene, x, y, side, band, card } = m.value;

    return {
        left: `${((x + side) / scene.width) * 100}%`,
        top: `${((y + band) / scene.height) * 100}%`,
        width: `${(card.width / scene.width) * 100}%`,
        height: `${(card.height / scene.height) * 100}%`,
    };
});

/** Les proportions de la scène gardées : les calques et le badge restent superposés au millimètre. */
const frameStyle = computed(() => {
    const { width, height } = m.value.scene;

    return {
        aspectRatio: `${width} / ${height}`,
        width: props.height ? `min(100%, calc(${props.height} * ${(width / height).toFixed(4)}))` : '100%',
    };
});

const label = computed(() => `Aperçu : badge ${formatMm(m.value.card)} dans son porte-badge, au tour de cou`);
</script>

<template>
    <figure class="badge-holder relative mx-auto select-none" :style="frameStyle" role="img" :aria-label="label">
        <!-- Derrière le badge : le cordon, le mousqueton, le porte-badge et son ombre. -->
        <svg class="absolute inset-0 h-full w-full overflow-visible" :viewBox="`0 0 ${m.scene.width} ${m.scene.height}`" aria-hidden="true">
            <defs>
                <linearGradient :id="sid('metal')" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#9CA3AF" />
                    <stop offset="0.45" stop-color="#F3F4F6" />
                    <stop offset="1" stop-color="#6B7280" />
                </linearGradient>
                <filter :id="sid('shadow')" x="-20%" y="-20%" width="140%" height="140%">
                    <feDropShadow dx="0.6" dy="2.2" stdDeviation="2.2" flood-color="#0F172A" flood-opacity="0.22" />
                </filter>
                <!-- Les perforations sont des trous : le fond se voit au travers. -->
                <mask :id="sid('holes')" maskUnits="userSpaceOnUse" :x="0" :y="0" :width="m.scene.width" :height="m.scene.height">
                    <rect :width="m.scene.width" :height="m.scene.height" fill="#FFFFFF" />
                    <rect :x="m.cx - m.slot.width / 2" :y="m.slot.y - m.slot.height / 2" :width="m.slot.width" :height="m.slot.height" :rx="m.slot.height / 2" fill="#000000" />
                    <circle :cx="m.hole.left" :cy="m.slot.y" :r="m.hole.r" fill="#000000" />
                    <circle :cx="m.hole.right" :cy="m.slot.y" :r="m.hole.r" fill="#000000" />
                </mask>
                <path v-for="(path, index) in textPaths" :id="sid(`strap-${index}`)" :key="index" :d="path" />
            </defs>

            <!-- Le cordon : deux brins, bordés de la couleur d'accent, le nom de l'établissement dessus. -->
            <g stroke-linecap="butt" fill="none">
                <g v-for="(strap, index) in [...straps].reverse()" :key="index">
                    <path :d="strap" :stroke="colors.accent" stroke-width="12" />
                    <path :d="strap" :stroke="colors.primary" stroke-width="10.6" />
                </g>
            </g>
            <text v-if="strapText" font-size="4.1" font-weight="800" fill="#FFFFFF" letter-spacing="0.9" opacity="0.9" dominant-baseline="central">
                <textPath :href="`#${sid('strap-0')}`" startOffset="6%">{{ strapText }}</textPath>
            </text>
            <text v-if="strapText" font-size="4.1" font-weight="800" fill="#FFFFFF" letter-spacing="0.9" opacity="0.9" dominant-baseline="central">
                <textPath :href="`#${sid('strap-1')}`" startOffset="10%">{{ strapText }}</textPath>
            </text>

            <!-- La bague du cordon, l'anneau, et la boucle du mousqueton. -->
            <rect :x="m.cx - m.crimp.width / 2" :y="m.crimp.y" :width="m.crimp.width" :height="m.crimp.height" rx="1.4" :fill="`url(#${sid('metal')})`" stroke="#6B7280" stroke-width="0.3" />
            <circle :cx="m.cx" :cy="m.ring.cy" :r="m.ring.r" fill="none" :stroke="`url(#${sid('metal')})`" stroke-width="1.5" />

            <!-- Le porte-badge : du PVC transparent, percé en haut. -->
            <g :mask="`url(#${sid('holes')})`">
                <rect :x="m.x" :y="m.y" :width="m.holder.width" :height="m.holder.height" rx="3.5" fill="#FFFFFF" fill-opacity="0.55" :filter="`url(#${sid('shadow')})`" />
            </g>
            <rect :x="m.cx - m.slot.width / 2" :y="m.slot.y - m.slot.height / 2" :width="m.slot.width" :height="m.slot.height" :rx="m.slot.height / 2" fill="none" stroke="#94A3B8" stroke-width="0.35" />
            <circle :cx="m.hole.left" :cy="m.slot.y" :r="m.hole.r" fill="none" stroke="#94A3B8" stroke-width="0.35" />
            <circle :cx="m.hole.right" :cy="m.slot.y" :r="m.hole.r" fill="none" stroke="#94A3B8" stroke-width="0.35" />

            <!-- La boucle du mousqueton passe dans la fente. -->
            <rect
                :x="m.cx - m.hook.width / 2"
                :y="m.hook.bottom - m.hook.height"
                :width="m.hook.width"
                :height="m.hook.height"
                :rx="m.hook.width / 2"
                fill="none"
                :stroke="`url(#${sid('metal')})`"
                stroke-width="1.9"
            />
            <path :d="`M ${m.cx + m.hook.width / 2 - 0.9} ${m.hook.bottom - m.hook.height * 0.72} l -2.6 4.2`" stroke="#6B7280" stroke-width="0.9" stroke-linecap="round" />
        </svg>

        <!-- Le badge, dans l'insert. -->
        <div class="absolute" :style="insertStyle">
            <EmployeeBadge :person="person" :design="design" />
        </div>

        <!-- Devant le badge : le reflet et le bord du PVC, et l'ouverture de la poche. -->
        <svg class="pointer-events-none absolute inset-0 h-full w-full" :viewBox="`0 0 ${m.scene.width} ${m.scene.height}`" aria-hidden="true">
            <defs>
                <linearGradient :id="sid('gloss')" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.5" />
                    <stop offset="0.35" stop-color="#FFFFFF" stop-opacity="0.08" />
                    <stop offset="0.36" stop-color="#FFFFFF" stop-opacity="0" />
                    <stop offset="1" stop-color="#FFFFFF" stop-opacity="0" />
                </linearGradient>
            </defs>
            <rect :x="m.x + m.side * 0.5" :y="m.y + m.band - 1" :width="m.holder.width - m.side" :height="m.card.height + m.side + 1" rx="2.5" :fill="`url(#${sid('gloss')})`" />
            <line :x1="m.x + 1.5" :y1="m.y + m.band - 1.2" :x2="m.x + m.holder.width - 1.5" :y2="m.y + m.band - 1.2" stroke="#0F172A" stroke-opacity="0.14" stroke-width="0.35" />
            <rect :x="m.x" :y="m.y" :width="m.holder.width" :height="m.holder.height" rx="3.5" fill="none" stroke="#0F172A" stroke-opacity="0.16" stroke-width="0.45" />
            <rect :x="m.x + 0.6" :y="m.y + 0.6" :width="m.holder.width - 1.2" :height="m.holder.height - 1.2" rx="3" fill="none" stroke="#FFFFFF" stroke-opacity="0.7" stroke-width="0.45" />
        </svg>
    </figure>
</template>

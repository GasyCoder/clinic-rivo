/**
 * Bébé, enfant ou adulte, d'après l'âge en années révolues (ADR-184) — la même
 * règle que `App\Enums\PatientAgeBand`, avec les bornes réglées pour le site
 * (`page.props.site.ageBands`). L'écran s'en sert pour adapter le formulaire ;
 * le refus, lui, se décide côté serveur (`PatientAgeRules`).
 */
export const DEFAULT_AGE_BANDS = Object.freeze({ baby_max_age: 1, child_max_age: 15 });

export const AGE_BAND_LABELS = Object.freeze({ BABY: 'Bébé', CHILD: 'Enfant', ADULT: 'Adulte' });

const years = (value) => `${value} an${value > 1 ? 's' : ''}`;

/** L'âge révolu à `today` ; `null` pour une date vide, illisible ou future. */
export const yearsFromBirthDate = (value, today = new Date()) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value ?? ''));

    if (! match) return null;

    const [, y, m, d] = match.map(Number);
    const birth = new Date(y, m - 1, d);
    const now = new Date(today.getFullYear(), today.getMonth(), today.getDate());

    if (Number.isNaN(birth.getTime()) || birth > now) return null;

    let age = now.getFullYear() - y;

    if (now.getMonth() < m - 1 || (now.getMonth() === m - 1 && now.getDate() < d)) age -= 1;

    return age;
};

export const ageBandFor = (value, bands = DEFAULT_AGE_BANDS) => {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value)) || Number(value) < 0) {
        return null;
    }

    const age = Math.floor(Number(value));

    if (age <= bands.baby_max_age) return 'BABY';
    if (age <= bands.child_max_age) return 'CHILD';

    return 'ADULT';
};

export const isMinorBand = (band) => band === 'BABY' || band === 'CHILD';

/** La civilité qui va avec la tranche et le sexe ; `''` quand le sexe ne la décide pas. */
export const civilityFor = (band, sex) => {
    if (isMinorBand(band)) return sex === 'F' ? 'GIRL' : sex === 'M' ? 'BOY' : '';
    if (band === 'ADULT') return sex === 'F' ? 'MRS' : sex === 'M' ? 'MR' : '';

    return '';
};

/** « Bébé : 0 à 1 an · Enfant : 2 à 15 ans · Adulte : 16 ans et plus ». */
export const describeAgeBands = (bands = DEFAULT_AGE_BANDS) => [
    `Bébé : ${bands.baby_max_age === 0 ? 'moins d’un an' : `0 à ${years(bands.baby_max_age)}`}`,
    `Enfant : ${bands.baby_max_age + 1} à ${years(bands.child_max_age)}`,
    `Adulte : ${years(bands.child_max_age + 1)} et plus`,
].join(' · ');

/** Le texte d'une tranche pour un patient donné, ex. « Enfant · 2 à 15 ans ». */
export const bandRange = (band, bands = DEFAULT_AGE_BANDS) => ({
    BABY: bands.baby_max_age === 0 ? 'moins d’un an' : `0 à ${years(bands.baby_max_age)}`,
    CHILD: `${bands.baby_max_age + 1} à ${years(bands.child_max_age)}`,
    ADULT: `${years(bands.child_max_age + 1)} et plus`,
}[band] ?? '');

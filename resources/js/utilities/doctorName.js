/**
 * « Dr » devant le nom d'un médecin — sauf s'il le porte déjà.
 *
 * Un compte peut être nommé « Dr. Gaston Faly » : l'écran écrivait alors
 * « Dr Dr. Gaston Faly ». Le titre n'est ajouté que s'il manque.
 */
export const doctorName = (name) => {
    const value = String(name ?? '').trim();

    if (value === '') return '';

    return /^dr\b\.?/i.test(value) ? value : `Dr ${value}`;
};

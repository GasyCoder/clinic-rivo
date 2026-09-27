/**
 * Le brouillon d'e-mail qui porte une commande à son fournisseur.
 *
 * Rien n'est envoyé par l'application : `mailto:` ouvre le logiciel de
 * messagerie de la personne, avec l'objet et le corps déjà écrits. Elle relit,
 * complète, envoie depuis sa propre adresse — et garde la trace de l'envoi dans
 * sa boîte, là où elle recevra la réponse du fournisseur.
 *
 * Un envoi par le serveur aurait demandé un SMTP configuré par site, une
 * adresse d'expédition, une politique de pièces jointes et une file d'attente ;
 * et une commande « envoyée » dans RIVO ne prouverait pas qu'un e-mail est
 * réellement parti. Ce n'est pas ce qui a été demandé, et ce serait une
 * décision à part.
 *
 * La commande reste envoyée dans RIVO même si la messagerie ne s'ouvre pas :
 * l'e-mail accompagne l'envoi, il ne le conditionne pas.
 */

const line = (label, value) => (value ? `${label} : ${value}` : null);

/**
 * @param {object} order   numéro, date, fournisseur, lignes, total
 * @param {object} context nom du site et de qui écrit, pour la signature
 * @returns {{subject: string, body: string}}
 */
export function composeSupplierOrderMail(order, context = {}) {
    const number = order.order_number ?? null;
    const reference = number ? `Commande ${number}` : 'Commande';
    const subject = context.clinic ? `${reference} — ${context.clinic}` : reference;
    // Le numéro est un identifiant : le fournisseur le cite en retour, et la
    // réception le retrouve pour rattacher la livraison. Seul le mot
    // « commande » se met en minuscule dans la phrase, jamais la référence.
    const spelled = number ? `commande ${number}` : 'commande';

    const products = (order.lines ?? []).map((product) => {
        const quantity = Number(product.quantity ?? product.quantity_ordered ?? 0);
        const unit = product.unit ? ` ${product.unit}` : '';
        const code = product.code ?? product.medicine_code;

        return `  • ${quantity}${unit} — ${product.name ?? product.medicine_name ?? ''}${code ? ` (${code})` : ''}`;
    });

    const body = [
        'Bonjour,',
        '',
        `Veuillez trouver ci-dessous notre ${spelled}.`,
        '',
        ...[
            line('Référence', order.order_number),
            line('Date', order.ordered_on),
            line('Livraison souhaitée', order.expected_delivery_on),
        ].filter(Boolean),
        '',
        'Produits :',
        ...products,
        '',
        ...[line('Montant total', order.total)].filter(Boolean),
        ...(order.notes ? ['', `Remarque : ${order.notes}`] : []),
        '',
        'Merci de nous confirmer la disponibilité et le délai de livraison.',
        '',
        'Cordialement,',
        ...[context.author, context.clinic].filter(Boolean),
    ].join('\n');

    return { subject, body };
}

/**
 * Ouvre le brouillon. Sans adresse, il n'y a rien à ouvrir : l'appelant ne
 * propose alors pas le geste, plutôt que de lancer une messagerie vide.
 */
export function openSupplierOrderMail(email, order, context = {}) {
    if (!email) return false;

    const { subject, body } = composeSupplierOrderMail(order, context);
    const href = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

    // `location.href` plutôt qu'un onglet : un `mailto:` ouvert par `window.open`
    // laisse une fenêtre blanche derrière lui dans plusieurs navigateurs.
    window.location.href = href;

    return true;
}

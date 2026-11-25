/*
 * Pixel Meta (Facebook, Instagram). Tant que start() n'a pas été appelé (visiteur
 * qui n'a pas accepté, pas de pixel), rien n'est chargé et track() ne fait rien.
 * Jamais de donnée personnelle ici (nom, e-mail, téléphone, code de billet).
 */
const SCRIPT = 'https://connect.facebook.net/fr_FR/fbevents.js';

// Évènements de la billetterie (noms Google Analytics) et leur équivalent chez Meta
const EVENTS = { select_item: 'ViewContent', begin_checkout: 'InitiateCheckout', purchase: 'Purchase' };

let started = false;

export function start(id, { pageView = true } = {}) {
    if (!started) {
        started = true;
        load();
        // ni clics ni contenu de la page relevés automatiquement : seulement nos évènements
        window.fbq('set', 'autoConfig', false, id);
        window.fbq.disablePushState = true;
        window.fbq('init', id);
    }
    if (pageView) window.fbq('track', 'PageView');
}

/** Même signature que le track() de Google Analytics : l'évènement est traduit. */
export function track(name, params = {}) {
    const event = EVENTS[name];
    if (!started || !event) return;

    const items = params.items ?? [];
    const quantity = (item) => item.quantity ?? 1;
    const data = {
        currency: params.currency ?? 'XOF',
        value: params.value ?? items.reduce((sum, item) => sum + item.price * quantity(item), 0),
        content_type: 'product',
        content_ids: items.map((item) => item.item_id),
        content_name: items.map((item) => item.item_name).join(', '),
        contents: items.map((item) => ({ id: item.item_id, quantity: quantity(item), item_price: item.price })),
    };
    if (name !== 'select_item') data.num_items = items.reduce((sum, item) => sum + quantity(item), 0);

    // eventID : Meta ne compte qu'une fois une même commande
    window.fbq('track', event, data, params.transaction_id ? { eventID: params.transaction_id } : {});
}

/** Retrait de l'accord : plus aucun envoi (cookies effacés par forgetCookies). */
export function forget() {
    if (started) window.fbq('consent', 'revoke');
}

/** Amorce officielle de Meta : file d'attente jusqu'au chargement du script. */
function load() {
    if (window.fbq) return;
    const fbq = (window.fbq = window._fbq = function () {
        if (fbq.callMethod) fbq.callMethod.apply(fbq, arguments);
        else fbq.queue.push(arguments);
    });
    fbq.push = fbq;
    fbq.loaded = true;
    fbq.version = '2.0';
    fbq.queue = [];

    const script = document.createElement('script');
    script.async = true;
    script.src = SCRIPT;
    document.head.append(script);
}

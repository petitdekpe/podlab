/*
 * Google Analytics 4. Tant que start() n'a pas été appelé (visiteur qui n'a pas
 * accepté, page sans mesure), rien n'est chargé et track() ne fait rien.
 * Jamais de donnée personnelle ici (nom, e-mail, téléphone, code de billet).
 */
let started = false;

export function start(id, config = {}) {
    if (started) return;
    started = true;

    window.dataLayer = window.dataLayer || [];
    window.gtag = function () {
        window.dataLayer.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', id, {
        // mesure d'audience seulement, pas de publicité
        allow_google_signals: false,
        allow_ad_personalization_signals: false,
        ...config,
    });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    document.head.append(script);
}

export function track(name, params = {}) {
    if (started) window.gtag('event', name, params);
}

/** Retrait de l'accord : plus aucun envoi (cookies effacés par forgetCookies). */
export function forget(id) {
    window[`ga-disable-${id}`] = true;
}

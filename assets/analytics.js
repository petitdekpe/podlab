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

/** Retrait de l'accord : plus aucun envoi, cookies _ga effacés. */
export function forget(id) {
    window[`ga-disable-${id}`] = true;
    const parts = location.hostname.split('.');
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0].trim();
        if (name !== '_ga' && !name.startsWith('_ga_')) continue;
        const expired = `${name}=; path=/; max-age=0`;
        document.cookie = expired;
        // Google pose ses cookies sur le domaine parent (beninbouge.com)
        parts.forEach((_, i) => (document.cookie = `${expired}; domain=${parts.slice(i).join('.')}`));
    }
}

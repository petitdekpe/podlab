import { track as google } from './analytics.js';
import { track as meta } from './pixel.js';

/** Évènement envoyé à chaque outil accepté par le visiteur (aucun par défaut). */
export function track(name, params = {}) {
    google(name, params);
    meta(name, params);
}

/** Efface les cookies dont le nom passe le filtre, y compris sur le domaine parent (beninbouge.com). */
export function forgetCookies(matches) {
    const parts = location.hostname.split('.');
    for (const cookie of document.cookie.split(';')) {
        const name = cookie.split('=')[0].trim();
        if (!matches(name)) continue;
        const expired = `${name}=; path=/; max-age=0`;
        document.cookie = expired;
        parts.forEach((_, i) => (document.cookie = `${expired}; domain=${parts.slice(i).join('.')}`));
    }
}

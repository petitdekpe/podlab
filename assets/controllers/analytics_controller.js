import { Controller } from '@hotwired/stimulus';
import * as google from '../analytics.js';
import * as meta from '../pixel.js';
import { forgetCookies } from '../tracking.js';

/*
 * Bandeau de cookies : mesure d'audience (Google Analytics) et publicité (pixel Meta).
 *
 * - Première visite : le bandeau s'affiche, aucun outil n'est chargé.
 * - Accepté : chargés tout de suite, puis à chaque page. Refusé : jamais.
 * - Chaque usage a sa réponse : qui n'a accepté que la mesure d'audience est
 *   interrogé à nouveau quand le pixel est ajouté. Un refus vaut pour les deux.
 * - Un bouton [data-cookies] (pied de page) rouvre le bandeau.
 * - Achat confirmé (page de retour) : envoyé une seule fois par commande et par outil.
 */
const AUDIENCE = 'mesure-audience';
const PUBLICITE = 'publicite';

export default class extends Controller {
    static values = {
        id: String,
        pixel: String,
        return: Boolean,
        pageView: { type: Boolean, default: true },
        purchase: Object,
    };

    connect() {
        this.onReopen = (e) => {
            if (e.target.closest?.('[data-cookies]')) this.element.hidden = false;
        };
        document.addEventListener('click', this.onReopen);

        const refused = [AUDIENCE, PUBLICITE].some((key) => this.read(key) === 'non');
        this.element.hidden = refused || this.active().every((key) => this.read(key) !== null);
        // aperçu du cache de Turbo : la vraie page suit
        if (!document.documentElement.hasAttribute('data-turbo-preview')) this.start();
    }

    disconnect() {
        document.removeEventListener('click', this.onReopen);
    }

    accept() {
        const before = this.active().filter((key) => this.read(key) === 'oui');
        this.active().forEach((key) => this.write(key, 'oui'));
        this.element.hidden = true;
        // seulement ce qui vient d'être accepté : le reste tourne déjà
        this.start(this.active().filter((key) => !before.includes(key)));
    }

    refuse() {
        const before = [AUDIENCE, PUBLICITE].some((key) => this.read(key) === 'oui');
        [AUDIENCE, PUBLICITE].forEach((key) => this.write(key, 'non'));
        this.element.hidden = true;
        if (before) {
            if (this.idValue) google.forget(this.idValue);
            meta.forget();
            forgetCookies((name) => name === '_ga' || name.startsWith('_ga_') || name === '_fbp' || name === '_fbc');
            location.reload(); // repart d'une page où aucun outil n'est chargé
        }
    }

    /** Usages en service, d'après les identifiants saisis dans /admin. */
    active() {
        return [this.idValue && AUDIENCE, this.pixelValue && PUBLICITE].filter(Boolean);
    }

    start(keys = this.active().filter((key) => this.read(key) === 'oui')) {
        if (keys.includes(AUDIENCE)) this.startGoogle();
        if (keys.includes(PUBLICITE)) this.startMeta();
    }

    startGoogle() {
        const config = { send_page_view: this.pageViewValue };
        if (this.returnValue) {
            // Retour de paiement : la visite garde sa vraie origine (pas « tick.africa »),
            // et les paramètres ajoutés à l'adresse ne sont pas transmis
            config.ignore_referrer = true;
            config.page_location = location.origin + location.pathname;
        }
        google.start(this.idValue, config);
        this.purchase(google, 'achat');
    }

    startMeta() {
        if (this.returnValue && location.search) {
            // le pixel lit l'adresse lui-même : les paramètres du paiement en sont retirés
            history.replaceState(history.state, '', location.pathname + location.hash);
        }
        meta.start(this.pixelValue, { pageView: this.pageViewValue });
        this.purchase(meta, 'achat-pub');
    }

    purchase(tool, prefix) {
        const id = this.purchaseValue.transaction_id;
        if (!id || this.read(`${prefix}-${id}`) !== null) return;
        this.write(`${prefix}-${id}`, '1');
        tool.track('purchase', this.purchaseValue);
    }

    // --- mémoire du navigateur (peut être bloquée : le bandeau revient alors) ----

    read(key) {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    write(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {
            // ignoré
        }
    }
}

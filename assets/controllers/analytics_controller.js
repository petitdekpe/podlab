import { Controller } from '@hotwired/stimulus';
import { forget, start, track } from '../analytics.js';

/*
 * Bandeau de cookies et mesure d'audience.
 *
 * - Première visite : le bandeau s'affiche, Google Analytics n'est pas chargé.
 * - Accepté : chargé tout de suite, puis à chaque page. Refusé : jamais.
 * - Un bouton [data-cookies] (pied de page) rouvre le bandeau.
 * - Achat confirmé (page de retour) : envoyé une seule fois par commande.
 */
const KEY = 'mesure-audience';

export default class extends Controller {
    static values = { id: String, return: Boolean, pageView: { type: Boolean, default: true }, purchase: Object };

    connect() {
        this.onReopen = (e) => {
            if (e.target.closest?.('[data-cookies]')) this.element.hidden = false;
        };
        document.addEventListener('click', this.onReopen);

        const choice = this.read(KEY);
        this.element.hidden = choice !== null;
        if (choice === 'oui') this.start();
    }

    disconnect() {
        document.removeEventListener('click', this.onReopen);
    }

    accept() {
        this.write(KEY, 'oui');
        this.element.hidden = true;
        this.start();
    }

    refuse() {
        const before = this.read(KEY);
        this.write(KEY, 'non');
        this.element.hidden = true;
        if (before === 'oui') {
            forget(this.idValue);
            location.reload(); // repart d'une page où Google Analytics n'est pas chargé
        }
    }

    start() {
        const config = { send_page_view: this.pageViewValue };
        if (this.returnValue) {
            // Retour de paiement : la visite garde sa vraie origine (pas « tick.africa »),
            // et les paramètres ajoutés à l'adresse ne sont pas transmis
            config.ignore_referrer = true;
            config.page_location = location.origin + location.pathname;
        }
        start(this.idValue, config);
        this.purchase();
    }

    purchase() {
        const id = this.purchaseValue.transaction_id;
        if (!id || this.read(`achat-${id}`) !== null) return;
        this.write(`achat-${id}`, '1');
        track('purchase', this.purchaseValue);
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

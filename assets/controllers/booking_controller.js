import { Controller } from '@hotwired/stimulus';
import { track } from '../analytics.js';

/*
 * Billetterie des séances : formulaire de réservation.
 *
 * - Le bouton « Choisir cette séance » d'une carte coche la séance dans le
 *   formulaire et y fait défiler la page (sans JS, le lien ?seance= fait de même).
 * - La quantité est bornée par les places restantes du billet choisi.
 * - Le total est recalculé à chaque changement (le prix final reste celui de Tick Africa).
 * - Un seul envoi : le bouton est désactivé pendant l'ouverture du paiement.
 */
export default class extends Controller {
    static targets = ['form', 'quantity', 'total', 'submit', 'details'];

    connect() {
        // Places et coordonnées n'apparaissent qu'une fois la séance choisie
        if (this.hasDetailsTarget && !this.selected()) this.detailsTarget.hidden = true;
        this.choice = this.selected();
        this.update();
    }

    pick(event) {
        if (!this.hasFormTarget) return;
        const radio = this.formTarget.querySelector(`input[name="billet"][value="${CSS.escape(event.params.billet)}"]`);
        if (!radio || radio.disabled) return;

        event.preventDefault();
        radio.checked = true;
        this.update();
        document.getElementById('reservation')?.scrollIntoView({ behavior: this.motion(), block: 'start' });
        // le focus suit, pour le clavier et les lecteurs d'écran
        setTimeout(() => radio.focus({ preventScroll: true }), this.motion() === 'smooth' ? 450 : 0);
    }

    minus() {
        this.step(-1);
    }

    plus() {
        this.step(1);
    }

    step(delta) {
        if (!this.hasQuantityTarget) return;
        this.quantityTarget.value = this.clamp(Number(this.quantityTarget.value || 1) + delta);
        this.update();
    }

    update() {
        if (!this.hasQuantityTarget) return;
        const selected = this.selected();
        if (selected && this.hasDetailsTarget && this.detailsTarget.hidden) {
            this.detailsTarget.hidden = false;
        }
        if (selected && selected !== this.choice) {
            this.choice = selected;
            track('select_item', { items: [this.item(selected, 1)] });
        }
        const max = selected ? Number(selected.dataset.max) || 1 : 10;
        this.quantityTarget.max = max;

        // ne corrige que les valeurs complètes (on laisse l'acheteur effacer pour retaper)
        if (this.quantityTarget.value !== '' && Number(this.quantityTarget.value) > max) {
            this.quantityTarget.value = max;
        }

        if (this.hasTotalTarget) {
            const qty = this.clamp(Number(this.quantityTarget.value || 1));
            this.totalTarget.textContent = selected
                ? (Number(selected.dataset.price) * qty).toLocaleString('fr-FR').replace(/ /g, ' ')
                : '—';
        }
    }

    submit(event) {
        if (this.sending) {
            event.preventDefault();
            return;
        }
        this.sending = true;
        this.trackCheckout();
        if (this.hasSubmitTarget) {
            this.submitTarget.setAttribute('aria-busy', 'true');
            this.submitTarget.classList.add('is-busy');
            this.submitTarget.firstChild.textContent = 'Ouverture du paiement… ';
        }
        // retour arrière depuis la page de paiement : le formulaire redevient utilisable
        addEventListener('pageshow', () => this.reset(), { once: true });
    }

    reset() {
        this.sending = false;
        if (!this.hasSubmitTarget) return;
        this.submitTarget.removeAttribute('aria-busy');
        this.submitTarget.classList.remove('is-busy');
        this.submitTarget.firstChild.textContent = 'Payer ';
    }

    trackCheckout() {
        const selected = this.selected();
        if (!selected || !this.hasQuantityTarget) return;
        const qty = this.clamp(Number(this.quantityTarget.value || 1));
        track('begin_checkout', {
            currency: 'XOF',
            value: Number(selected.dataset.price) * qty,
            items: [this.item(selected, qty)],
        });
    }

    item(radio, quantity) {
        return { ...JSON.parse(radio.dataset.item), quantity };
    }

    selected() {
        return this.hasFormTarget ? this.formTarget.querySelector('input[name="billet"]:checked') : null;
    }

    clamp(n) {
        const max = Number(this.quantityTarget.max) || 10;
        return Math.min(max, Math.max(1, Math.round(n) || 1));
    }

    motion() {
        return matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    }
}

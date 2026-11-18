import { Controller } from '@hotwired/stimulus';

/*
 * Barre d'action collante : visible tant qu'aucun des éléments surveillés
 * (sélecteurs CSS) n'est à l'écran. Sert à garder un bouton "Réserver" à
 * portée de pouce sans doubler le bouton du hero ni la billetterie.
 */
export default class extends Controller {
    static values = { watch: Array };

    connect() {
        if (!('IntersectionObserver' in window)) return;

        this.inView = new Set();
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                entry.isIntersecting ? this.inView.add(entry.target) : this.inView.delete(entry.target);
            });
            this.element.classList.toggle('is-visible', this.inView.size === 0 && this.scrolled);
        });

        // Évite d'afficher la barre avant tout défilement (état initial avant observation)
        this.scrolled = false;
        this.onScroll = () => {
            this.scrolled = true;
            removeEventListener('scroll', this.onScroll);
            this.element.classList.toggle('is-visible', this.inView.size === 0);
        };
        addEventListener('scroll', this.onScroll, { passive: true });

        this.watchValue
            .map((selector) => document.querySelector(selector))
            .filter(Boolean)
            .forEach((el) => this.observer.observe(el));
    }

    disconnect() {
        this.observer?.disconnect();
        removeEventListener('scroll', this.onScroll);
    }
}

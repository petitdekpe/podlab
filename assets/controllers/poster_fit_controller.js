import { Controller } from '@hotwired/stimulus';

/*
 * Premier écran : l'affiche prend la hauteur de la billetterie.
 *
 * Élargir l'affiche rétrécit la billetterie, qui peut alors s'allonger : la largeur
 * est donc cherchée par dichotomie, entre --poster-min et --poster-max (app.css).
 * Recalculée quand la billetterie change de taille (séance choisie, erreur, fenêtre).
 * Sans JS, ou au-delà de --poster-max, le cadre garde la bonne hauteur et le fond
 * flouté comble le reste.
 */
export default class extends Controller {
    static targets = ['poster', 'ticketing'];

    connect() {
        this.stacked = matchMedia('(max-width: 900px)');
        this.fit();
        this.observer = new ResizeObserver(() => {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.fit());
        });
        this.observer.observe(this.ticketingTarget);
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer.disconnect();
        cancelAnimationFrame(this.frame);
        this.element.style.removeProperty('--poster-w');
    }

    fit() {
        this.element.style.removeProperty('--poster-w');
        if (this.stacked.matches) return;

        // sans réglage, la colonne est à sa largeur maximale
        let high = this.posterTarget.getBoundingClientRect().width;
        if (this.fits(high)) return;

        let low = Math.min(high, parseFloat(getComputedStyle(this.element).getPropertyValue('--poster-min')) || 0);
        while (high - low > 0.5) {
            const middle = (low + high) / 2;
            this.apply(middle);
            if (this.fits(middle)) low = middle;
            else high = middle;
        }
        this.apply(low);
    }

    // l'affiche, à cette largeur, tient-elle dans la hauteur de la billetterie ?
    fits(width) {
        const ratio = parseFloat(getComputedStyle(this.element).getPropertyValue('--poster-ratio')) || 0.8;

        return width / ratio <= this.ticketingTarget.getBoundingClientRect().height;
    }

    apply(width) {
        this.element.style.setProperty('--poster-w', `${width}px`);
    }
}

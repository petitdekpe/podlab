import { Controller } from '@hotwired/stimulus';

/*
 * Recharge la page à une date donnée (ex. ouverture du direct), ou, si une URL
 * est fournie, y emmène le visiteur. Un petit décalage aléatoire évite de faire
 * arriver tout le monde à la même seconde.
 */
export default class extends Controller {
    static values = { at: String, url: String };

    // Au-delà, setTimeout se déclencherait immédiatement (~24,8 jours)
    static MAX_DELAY = 2147483647;

    connect() {
        this.at = Date.parse(this.atValue);
        if (isNaN(this.at)) return;

        const wait = this.at - Date.now();
        if (wait < this.constructor.MAX_DELAY) {
            this.timer = setTimeout(() => this.go(), Math.max(0, wait) + 500 + Math.random() * 4000);
        }

        // Onglet en arrière-plan ou téléphone en veille : les minuteries peuvent prendre du retard
        this.onVisible = () => {
            if (document.visibilityState === 'visible' && Date.now() >= this.at) this.go();
        };
        document.addEventListener('visibilitychange', this.onVisible);
    }

    disconnect() {
        clearTimeout(this.timer);
        if (this.onVisible) document.removeEventListener('visibilitychange', this.onVisible);
    }

    go() {
        if (this.done) return;
        this.done = true;
        this.urlValue ? location.assign(this.urlValue) : location.reload();
    }
}

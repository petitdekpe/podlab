import { Controller } from '@hotwired/stimulus';

/*
 * Embed de billetterie tick.africa : ajuste la hauteur de l'iframe
 * quand le widget envoie son message `tickafrica:height`.
 */
export default class extends Controller {
    static targets = ['frame'];

    connect() {
        this.onMessage = (e) => {
            if (e.source !== this.frameTarget.contentWindow) return;
            if (e.data?.type === 'tickafrica:height') {
                this.frameTarget.height = e.data.height;
            }
        };
        addEventListener('message', this.onMessage);
    }

    disconnect() {
        removeEventListener('message', this.onMessage);
    }
}

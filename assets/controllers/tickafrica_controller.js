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
            // un widget chargé dans un conteneur masqué peut annoncer une hauteur nulle : on l'ignore
            if (e.data?.type === 'tickafrica:height' && Number(e.data.height) > 0) {
                this.frameTarget.height = e.data.height;
            }
        };
        addEventListener('message', this.onMessage);
    }

    disconnect() {
        removeEventListener('message', this.onMessage);
    }
}

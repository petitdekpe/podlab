import { Controller } from '@hotwired/stimulus';

/*
 * Admin, photo d'un lieu : les deux curseurs choisissent la partie de l'image
 * gardée au recadrage ; l'aperçu a les proportions du site.
 */
export default class extends Controller {
    static targets = ['preview', 'x', 'y'];

    update() {
        this.previewTarget.style.objectPosition = `${this.xTarget.value}% ${this.yTarget.value}%`;
    }
}

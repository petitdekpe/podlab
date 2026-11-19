import { Controller } from '@hotwired/stimulus';

/*
 * Recharge la page à une date donnée (ex. ouverture du direct), avec un petit
 * décalage aléatoire pour ne pas faire arriver tout le monde à la même seconde.
 */
export default class extends Controller {
    static values = { at: String };

    connect() {
        const wait = Date.parse(this.atValue) - Date.now();
        if (isNaN(wait)) return;

        this.timer = setTimeout(() => location.reload(), Math.max(0, wait) + 500 + Math.random() * 4000);
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}

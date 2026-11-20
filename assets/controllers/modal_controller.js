import { Controller } from '@hotwired/stimulus';

/*
 * Fenêtre pop-up (<dialog>).
 *
 * - N'importe quel élément portant data-open-modal="<id>" l'ouvre, où qu'il
 *   soit dans la page.
 * - Avec data-modal-auto-open-value="true", elle s'ouvre toute seule à
 *   l'arrivée sur la page. data-modal-once-key-value="<clé>" limite cette
 *   ouverture automatique à une fois par visite (onglet) : un rechargement ou
 *   un retour sur la page ne la rouvre pas. Les boutons, eux, l'ouvrent toujours.
 * - Les iframes marquées data-src ne sont chargées qu'à la première ouverture.
 * - Échap ou un clic à l'extérieur la ferme, et le focus revient sur le
 *   bouton d'origine.
 *
 * <dialog data-controller="modal" data-modal-id-value="mon-id"
 *         data-action="click->modal#backdrop close->modal#closed">
 */
export default class extends Controller {
    static values = { id: String, autoOpen: Boolean, onceKey: String };

    connect() {
        this.onClick = (event) => {
            const trigger = event.target.closest?.('[data-open-modal]');
            if (trigger && trigger.dataset.openModal === this.idValue) {
                event.preventDefault();
                this.open();
            }
        };
        document.addEventListener('click', this.onClick);

        if (this.autoOpenValue && !this.alreadyShown()) {
            // petit délai : la page s'affiche d'abord, puis la fenêtre apparaît
            this.autoTimer = setTimeout(() => {
                this.open();
                this.markShown();
            }, 700);
        }
    }

    disconnect() {
        clearTimeout(this.autoTimer);
        document.removeEventListener('click', this.onClick);
        document.documentElement.classList.remove('is-modal-open');
    }

    open() {
        if (this.element.open) return;

        this.element.querySelectorAll('iframe[data-src]').forEach((frame) => {
            frame.src = frame.dataset.src;
            frame.removeAttribute('data-src');
        });

        this.element.showModal();
        document.documentElement.classList.add('is-modal-open');
    }

    close() {
        this.element.close();
    }

    // Un clic sur le fond (hors du contenu) ferme la fenêtre
    backdrop(event) {
        if (event.target === this.element) this.close();
    }

    closed() {
        document.documentElement.classList.remove('is-modal-open');
    }

    // --- Une seule ouverture automatique par visite ----------------------------

    storageKey() {
        return `modal-shown:${this.onceKeyValue || this.idValue}`;
    }

    alreadyShown() {
        try {
            return sessionStorage.getItem(this.storageKey()) === '1';
        } catch (e) {
            return false; // stockage indisponible (navigation privée…) : on l'affiche
        }
    }

    markShown() {
        try {
            sessionStorage.setItem(this.storageKey(), '1');
        } catch (e) {
            // ignoré
        }
    }
}

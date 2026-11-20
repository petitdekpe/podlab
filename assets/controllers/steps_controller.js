import { Controller } from '@hotwired/stimulus';

/*
 * Parcours en plusieurs étapes dans une fenêtre pop-up.
 *
 * Panneaux, dans l'ordre : 0 annonce → 1 principe → 2 règles → 3 avertissement
 * → 4 achat. Les étapes 1 et 2 se parcourent librement ; la SEULE validation
 * se fait à la fin (étape 3).
 *
 * - « Valider et passer à l'achat » reste désactivé tant que la case de
 *   l'étape 3 n'est pas cochée.
 * - Une fois validé, le parcours est mémorisé pour la durée de la visite
 *   (sessionStorage) : si on rouvre la fenêtre, « Acheter un accès au direct »
 *   mène directement à l'achat.
 * - Le formulaire d'achat (iframe[data-lazy-src]) commence à se charger dès que
 *   la personne démarre les étapes, pour être prêt dès la validation.
 * - À la fermeture de la fenêtre, on revient à l'annonce.
 *
 * Note : la validation se fait dans le navigateur ; elle n'est pas enregistrée
 * côté serveur.
 */
export default class extends Controller {
    static targets = ['panel', 'check', 'next'];
    static values = { storageKey: String };

    connect() {
        this.index = 0;
        this.show(0, false);
    }

    start() {
        // Le formulaire de paiement commence à se charger tout de suite, en arrière-plan,
        // pendant que la personne lit les étapes : à la validation, il est déjà prêt.
        this.preload();
        this.show(this.validated() ? this.panelTargets.length - 1 : 1);
    }

    preload() {
        this.panelTargets.at(-1)?.querySelectorAll('iframe[data-lazy-src]').forEach((frame) => {
            frame.src = frame.dataset.lazySrc;
            frame.removeAttribute('data-lazy-src');
        });
    }

    next() {
        const last = this.panelTargets.length - 1;
        const target = Math.min(this.index + 1, last);

        // fin de la dernière étape de validation : on mémorise
        if (target === last) this.remember();
        this.show(target);
    }

    back() {
        // depuis l'achat, « Relire les règles » revient à la dernière étape
        this.show(Math.max(this.index - 1, 0));
    }

    // Active « Continuer » quand la case du panneau est cochée
    sync(event) {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') return;

        const panel = event.target.closest('[data-steps-target="panel"]');
        const button = panel?.querySelector('[data-steps-target="next"]');
        if (button) button.disabled = !event.target.checked;
    }

    // Fermeture de la fenêtre : retour à l'annonce
    reset() {
        if (!this.validated()) {
            this.checkTargets.forEach((box) => (box.checked = false));
            this.nextTargets.forEach((button) => (button.disabled = true));
        }
        this.show(0, false);
    }

    show(index, focus = true) {
        this.index = index;
        this.panelTargets.forEach((panel, i) => (panel.hidden = i !== index));

        const panel = this.panelTargets[index];

        // filet de sécurité : si le formulaire n'a pas été préchargé, on le charge à l'arrivée
        if (index === this.panelTargets.length - 1) this.preload();

        if (focus) {
            // le lecteur d'écran annonce le nouveau titre
            const heading = panel.querySelector('h2');
            heading?.focus({ preventScroll: true });
            this.element.scrollTo?.({ top: 0 });
        }
    }

    // --- mémoire de la visite --------------------------------------------------

    validated() {
        try {
            return sessionStorage.getItem(this.storageKeyValue) === '1';
        } catch (e) {
            return false;
        }
    }

    remember() {
        try {
            sessionStorage.setItem(this.storageKeyValue, '1');
        } catch (e) {
            // ignoré : il faudra simplement recommencer à la prochaine ouverture
        }
    }
}

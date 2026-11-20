import { Controller } from '@hotwired/stimulus';

/*
 * Bande-annonce.
 *
 * - Elle ne se lance JAMAIS toute seule : la personne appuie sur lecture
 *   (avec le son, puisque c'est son choix).
 * - Quand on dépasse la vidéo en cours de lecture, le lecteur passe en
 *   miniature dans un coin de l'écran et la lecture continue sans coupure
 *   (la balise <video> ne bouge pas dans la page, seul son style change).
 * - Si la vidéo est en pause ou terminée, il n'y a pas de miniature.
 */
export default class extends Controller {
    static targets = ['player', 'video'];

    connect() {
        this.mini = false;

        this.onEnded = () => this.setMini(false);
        this.videoTarget.addEventListener('ended', this.onEnded);

        // On observe l'emplacement (qui garde sa place), pas le lecteur qui peut se déplacer
        this.observer = new IntersectionObserver(
            (entries) => entries.forEach((entry) => this.onVisibility(entry.intersectionRatio)),
            { threshold: [0, 0.2, 0.5] },
        );
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        this.videoTarget.removeEventListener('ended', this.onEnded);
        this.videoTarget.pause();
    }

    onVisibility(ratio) {
        if (ratio >= 0.5) {
            this.setMini(false);
        } else if (ratio < 0.2) {
            // La vidéo sort de l'écran : si elle joue, elle continue en miniature
            this.setMini(!this.videoTarget.paused && !this.videoTarget.ended);
        }
    }

    // --- Miniature ---------------------------------------------------------

    setMini(on) {
        if (this.mini === on) return;
        this.mini = on;
        this.element.classList.toggle('is-mini', on);
        // En miniature, les contrôles natifs sont trop petits : un toucher sur la vidéo suffit
        this.videoTarget.controls = !on;
    }

    toggleFromMini() {
        if (!this.mini) return;
        const video = this.videoTarget;
        video.paused ? video.play().catch(() => {}) : video.pause();
    }

    expand() {
        this.element.scrollIntoView({
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center',
        });
    }

    close() {
        this.videoTarget.pause();
        this.setMini(false);
    }
}

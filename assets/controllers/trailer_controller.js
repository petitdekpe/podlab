import { Controller } from '@hotwired/stimulus';

/*
 * Bande-annonce.
 *
 * - Se lance toute seule dès qu'elle est visible, avec le son.
 *   Les navigateurs interdisent le son automatique tant que la personne n'a pas
 *   interagi avec la page : si le lancement avec le son est refusé, la vidéo
 *   démarre sans le son, un bouton « Activer le son » apparaît, et le son est
 *   rétabli dès le premier clic, toucher ou appui de touche sur la page.
 * - Quand on dépasse la vidéo en cours de lecture, le lecteur passe en
 *   miniature dans un coin de l'écran et la lecture continue sans coupure
 *   (la balise <video> ne bouge pas dans la page, seul son style change).
 * - Elle ne démarre pas toute seule si la personne a demandé de réduire les
 *   animations.
 */
export default class extends Controller {
    static targets = ['player', 'video', 'unmute'];

    connect() {
        this.userPaused = false;
        this.started = false;
        this.mini = false;

        this.onPause = () => {
            if (!this.videoTarget.ended) this.userPaused = true;
        };
        this.onPlay = () => (this.userPaused = false);
        this.onEnded = () => this.setMini(false);
        this.videoTarget.addEventListener('pause', this.onPause);
        this.videoTarget.addEventListener('play', this.onPlay);
        this.videoTarget.addEventListener('ended', this.onEnded);

        this.onInteraction = () => {
            this.removeInteractionListeners();
            if (this.videoTarget.muted) this.unmute();
        };

        // On observe l'emplacement (qui garde sa place), pas le lecteur qui peut se déplacer
        this.observer = new IntersectionObserver(
            (entries) => entries.forEach((entry) => this.onVisibility(entry.intersectionRatio)),
            { threshold: [0, 0.2, 0.5] },
        );
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        this.removeInteractionListeners();
        this.videoTarget.removeEventListener('pause', this.onPause);
        this.videoTarget.removeEventListener('play', this.onPlay);
        this.videoTarget.removeEventListener('ended', this.onEnded);
        this.videoTarget.pause();
    }

    async onVisibility(ratio) {
        if (ratio >= 0.5) {
            this.setMini(false);

            if (!this.started && !this.userPaused && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                await this.start();
            }
        } else if (ratio < 0.2) {
            // La vidéo sort de l'écran : si elle joue, elle continue en miniature
            if (!this.videoTarget.paused && !this.videoTarget.ended) this.setMini(true);
            else this.setMini(false);
        }
    }

    async start() {
        const video = this.videoTarget;

        // 1) avec le son
        video.muted = false;
        try {
            await video.play();
            this.started = true;
            return;
        } catch (e) {
            // refusé par la politique d'autoplay du navigateur
        }

        // 2) sans le son, avec un bouton pour le réactiver
        video.muted = true;
        try {
            await video.play();
            this.started = true;
            this.unmuteTarget.hidden = false;
            ['pointerdown', 'keydown', 'touchend'].forEach((type) =>
                addEventListener(type, this.onInteraction, { once: true, capture: true }),
            );
        } catch (e) {
            // lecture impossible : les contrôles natifs restent disponibles
        }
    }

    unmute() {
        this.videoTarget.muted = false;
        this.unmuteTarget.hidden = true;
        this.removeInteractionListeners();
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
        this.element.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    }

    close() {
        this.videoTarget.pause();
        this.userPaused = true;
        this.setMini(false);
    }

    removeInteractionListeners() {
        ['pointerdown', 'keydown', 'touchend'].forEach((type) =>
            removeEventListener(type, this.onInteraction, { capture: true }),
        );
    }
}

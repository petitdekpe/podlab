import { Controller } from '@hotwired/stimulus';

/*
 * Lecteur du direct (YouTube en mode « verrouillé »).
 *
 * - L'identifiant de la vidéo est demandé au serveur (billet valide + direct
 *   ouvert). Il n'est jamais dans le HTML de la page.
 * - Aucun contrôle YouTube : un calque transparent couvre la vidéo (pas de
 *   pause, de retour, de lien, de menu clic droit). Seuls le volume et le
 *   plein écran sont proposés. Si la lecture est mise en pause par un autre
 *   moyen (touche média du clavier…), elle est relancée.
 * - Le bouton « Rejoindre le direct » est un clic de la personne : c'est ce
 *   geste qui permet de lancer la vidéo avec le son.
 * - Un signal de présence vérifie toutes les 20 s que ce billet est toujours
 *   celui qui a la main. Sinon la vidéo est retirée.
 * - Un filigrane avec le numéro du billet se déplace sur l'image, pour
 *   décourager les enregistrements partagés.
 *
 * NB : le retour en arrière dans un direct YouTube se désactive dans
 * YouTube Studio (option DVR) ; ce contrôleur ne peut pas l'imposer seul.
 */

const PING_EVERY = 20_000;
const WATERMARK_EVERY = 12_000;

let youtubeApi = null;
function loadYouTubeApi() {
    if (window.YT?.Player) return Promise.resolve();
    if (youtubeApi) return youtubeApi;

    youtubeApi = new Promise((resolve) => {
        const previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve();
        };
        const script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(script);
    });

    return youtubeApi;
}

export default class extends Controller {
    static values = { fluxUrl: String, pingUrl: String, csrf: String, endsAt: String };
    static targets = [
        'stage', 'frame', 'shield', 'watermark', 'gate', 'status', 'join', 'hint', 'bar',
        'muteBtn', 'waves', 'volume', 'fsBtn', 'usedPanel', 'endedPanel',
    ];

    connect() {
        this.stopped = false;
        this.playing = false;

        this.onContextMenu = (e) => e.preventDefault();
        this.stageTarget.addEventListener('contextmenu', this.onContextMenu);

        this.onFullscreenChange = () => this.stageTarget.classList.toggle('is-fullscreen', !!document.fullscreenElement);
        document.addEventListener('fullscreenchange', this.onFullscreenChange);

        if (!document.fullscreenEnabled) this.fsBtnTarget.hidden = true;

        this.pingTimer = setInterval(() => this.ping(), PING_EVERY);
        this.moveTimer = setInterval(() => this.moveWatermark(), WATERMARK_EVERY);
        this.moveWatermark();

        this.prepare();
    }

    disconnect() {
        clearInterval(this.pingTimer);
        clearInterval(this.moveTimer);
        clearTimeout(this.hintTimer);
        this.stageTarget.removeEventListener('contextmenu', this.onContextMenu);
        document.removeEventListener('fullscreenchange', this.onFullscreenChange);
        this.player?.destroy?.();
    }

    // --- Préparation : on récupère le flux et on crée le lecteur en pause --------

    async prepare() {
        try {
            const response = await this.post(this.fluxUrlValue);

            if (response.status === 409) return this.block('used');
            if (response.status === 410) return this.block('ended');
            if (response.status === 403) return this.fail('Le direct n\'est pas encore ouvert. Rechargez la page dans un instant.');
            if (response.status === 503) return this.fail('Le direct n\'est pas encore configuré. Réessayez dans quelques minutes.');
            if (!response.ok) return this.fail('Impossible de charger le direct. Rechargez la page.');

            const { videoId } = await response.json();
            await loadYouTubeApi();
            if (this.stopped) return;

            this.player = new YT.Player(this.frameTarget, {
                videoId,
                playerVars: {
                    autoplay: 0,
                    controls: 0,
                    disablekb: 1,
                    fs: 0,
                    rel: 0,
                    iv_load_policy: 3,
                    modestbranding: 1,
                    playsinline: 1,
                    origin: location.origin,
                },
                events: {
                    onReady: () => {
                        this.statusTarget.textContent = 'Le direct est prêt.';
                        this.joinTarget.disabled = false;
                    },
                    onStateChange: (e) => this.onState(e.data),
                    onError: () => this.showHint('Lecture impossible pour le moment.'),
                },
            });
        } catch (e) {
            this.fail('Connexion impossible. Vérifiez votre réseau et rechargez la page.');
        }
    }

    fail(message) {
        this.statusTarget.textContent = message;
        this.joinTarget.hidden = true;
    }

    // --- Actions ---------------------------------------------------------------

    // Appelé par un clic : le son peut alors démarrer
    join() {
        if (!this.player) return;
        this.player.unMute();
        this.player.setVolume(Number(this.volumeTarget.value));
        this.player.playVideo();

        this.gateTarget.hidden = true;
        this.barTarget.hidden = false;
        this.syncMuteIcon();

        // Si rien ne démarre (direct pas encore lancé côté YouTube, navigateur strict…)
        this.hintTimer = setTimeout(() => {
            if (!this.playing) this.showHint('En attente du début du direct…');
        }, 4000);
    }

    retry() {
        this.hintTarget.hidden = true;
        this.player?.unMute();
        this.player?.playVideo();
        clearTimeout(this.hintTimer);
        this.hintTimer = setTimeout(() => {
            if (!this.playing) this.showHint('En attente du début du direct…');
        }, 4000);
    }

    toggleMute() {
        if (!this.player) return;
        if (this.player.isMuted() || this.player.getVolume() === 0) {
            this.player.unMute();
            if (this.player.getVolume() === 0) {
                this.player.setVolume(60);
                this.volumeTarget.value = 60;
            }
        } else {
            this.player.mute();
        }
        this.syncMuteIcon();
    }

    setVolume() {
        if (!this.player) return;
        const volume = Number(this.volumeTarget.value);
        this.player.setVolume(volume);
        volume === 0 ? this.player.mute() : this.player.unMute();
        this.syncMuteIcon();
    }

    toggleFullscreen() {
        if (document.fullscreenElement) document.exitFullscreen?.();
        else this.stageTarget.requestFullscreen?.().catch(() => {});
    }

    syncMuteIcon() {
        const muted = !this.player || this.player.isMuted() || Number(this.volumeTarget.value) === 0;
        this.wavesTarget.style.display = muted ? 'none' : '';
        this.muteBtnTarget.setAttribute('aria-label', muted ? 'Activer le son' : 'Couper le son');
    }

    // --- État du lecteur -------------------------------------------------------

    onState(state) {
        const PLAYING = 1;
        const PAUSED = 2;
        const BUFFERING = 3;
        const ENDED = 0;

        this.playing = state === PLAYING || state === BUFFERING;

        if (state === PLAYING) {
            clearTimeout(this.hintTimer);
            this.hintTarget.hidden = true;
        }
        // Pas de pause possible dans un direct : on relance
        if (state === PAUSED && !this.stopped) this.player.playVideo();
        if (state === ENDED) this.block('ended');
    }

    showHint(message) {
        this.hintTarget.querySelector('span').textContent = message;
        this.hintTarget.hidden = false;
    }

    // --- Présence et fin du direct --------------------------------------------

    async ping() {
        if (this.stopped) return;

        if (Date.now() >= Date.parse(this.endsAtValue)) return this.block('ended');

        try {
            const response = await this.post(this.pingUrlValue);
            if (response.status === 409) this.block('used');
            else if (response.status === 410) this.block('ended');
            else if (response.status === 401 || response.status === 423) location.reload(); // déconnecté ou accès fermé
        } catch (e) {
            // réseau coupé un instant : on réessaie au prochain signal
        }
    }

    // La vidéo est retirée de la page, puis un panneau explique pourquoi
    block(reason) {
        if (this.stopped) return;
        this.stopped = true;
        clearInterval(this.pingTimer);
        clearTimeout(this.hintTimer);

        if (document.fullscreenElement) document.exitFullscreen?.();
        this.player?.destroy?.();
        this.player = null;

        // (le <div> « frame » a été remplacé par l'iframe de YouTube : on ne s'y réfère plus)
        [this.shieldTarget, this.gateTarget, this.hintTarget, this.barTarget]
            .forEach((el) => (el.hidden = true));
        if (this.hasWatermarkTarget) this.watermarkTarget.hidden = true;
        this.stageTarget.querySelectorAll('iframe, .live__frame').forEach((el) => el.remove());

        (reason === 'used' ? this.usedPanelTarget : this.endedPanelTarget).hidden = false;
    }

    post(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
        });
    }

    // --- Filigrane -------------------------------------------------------------

    moveWatermark() {
        if (!this.hasWatermarkTarget) return; // filigrane désactivé dans l'administration
        const top = 8 + Math.random() * 72;
        const left = 4 + Math.random() * 62;
        this.watermarkTarget.style.top = `${top}%`;
        this.watermarkTarget.style.left = `${left}%`;
    }
}

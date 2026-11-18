import { Controller } from '@hotwired/stimulus';

/*
 * Live countdown to a fixed date.
 *
 * <div data-controller="countdown" data-countdown-target-value="2026-11-22T13:00:00+01:00">
 *   <div data-countdown-target="running"> … <span data-countdown-target="days"></span> … </div>
 *   <div data-countdown-target="ended" hidden> … </div>
 * </div>
 */
export default class extends Controller {
    static values = { target: String };
    static targets = ['days', 'hours', 'minutes', 'seconds', 'running', 'ended'];

    connect() {
        this.tick();
        this.timer = setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick() {
        const target = Date.parse(this.targetValue);
        const diff = Math.max(0, (isNaN(target) ? 0 : target) - Date.now());
        const sec = Math.floor(diff / 1000);
        const pad = (n) => String(n).padStart(2, '0');

        this.daysTargets.forEach((el) => (el.textContent = pad(Math.floor(sec / 86400))));
        this.hoursTargets.forEach((el) => (el.textContent = pad(Math.floor((sec % 86400) / 3600))));
        this.minutesTargets.forEach((el) => (el.textContent = pad(Math.floor((sec % 3600) / 60))));
        this.secondsTargets.forEach((el) => (el.textContent = pad(sec % 60)));

        const ended = diff <= 0;
        this.runningTargets.forEach((el) => (el.hidden = ended));
        this.endedTargets.forEach((el) => (el.hidden = !ended));

        if (ended) {
            clearInterval(this.timer);
        }
    }
}

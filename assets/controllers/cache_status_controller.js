import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['status', 'bar', 'encode', 'encodeMissing'];

    static values = {
        url: String,
        encodingLabel: String,  // "Encoding and caching existing tags"
        cachedLabel: String,    // "cached"
        missingLabel: String,   // "to encode"
        upToDateLabel: String   // "up to date"
    };

    pollInterval = 4000;

    connect() {
        if (this.hasUrlValue && this.urlValue) {
            this.refresh();
        }
    }

    disconnect() {
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    refresh() {
        let self = this;
        fetch(this.urlValue, { method: 'GET' })
            .then(response => response.json())
            .then(function (result) {
                let cached = Number(result.cached || 0);
                let missing = Number(result.missing || 0);
                let running = !!result.running;
                let done = Number(result.done || 0);
                let encodeTotal = Number(result.encodeTotal || 0);

                if (self.hasStatusTarget) {
                    if (running) {
                        self.statusTarget.textContent = `${self.encodingLabelValue} ${done.toLocaleString()}/${encodeTotal.toLocaleString()}`;
                        self.statusTarget.className = 'tag is-info';
                    } else if (missing > 0) {
                        self.statusTarget.textContent = `${cached.toLocaleString()} ${self.cachedLabelValue} · ${missing.toLocaleString()} ${self.missingLabelValue}`;
                        self.statusTarget.className = 'tag is-warning';
                    } else {
                        self.statusTarget.textContent = `${cached.toLocaleString()} ${self.cachedLabelValue} · ${self.upToDateLabelValue}`;
                        self.statusTarget.className = 'tag is-success';
                    }
                }
                if (self.hasBarTarget) {
                    self.barTarget.classList.toggle('is-hidden', !running);
                    if (running) {
                        self.barTarget.max = encodeTotal || 1;
                        self.barTarget.value = done;
                    }
                }
                // Both buttons are disabled while a run is in progress; "Encode missing" is also
                // disabled when there is nothing to encode ("Encode all" always re-encodes).
                self.encodeTargets.forEach((button) => { button.disabled = running; });
                self.encodeMissingTargets.forEach((button) => { button.disabled = running || missing === 0; });
                self.timer = setTimeout(() => self.refresh(), self.pollInterval);
            })
            .catch(function () {
                // Soft-fail: stop polling on error, don't disrupt the page.
            });
    }
}

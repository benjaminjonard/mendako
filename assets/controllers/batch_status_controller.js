import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['processed', 'total', 'bar', 'launch', 'status', 'vocab'];

    static values = {
        url: String,
        activeLabel: String,
        idleLabel: String,
        remainingLabel: String,
        vocabLabel: String
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
                let processed = Number(result.processed || 0);
                let total = Number(result.total || 0);
                let pending = Number(result.pending || 0);
                let running = !!result.running;
                // One-off vocabulary encoding notice: explicit "Encoding existing tags X/Y".
                if (self.hasVocabTarget) {
                    let vocabTotal = Number(result.vocabularyTotal || 0);
                    let vocabDone = Number(result.vocabularyDone || 0);
                    let warming = !!result.vocabularyWarming && vocabTotal > 0;
                    self.vocabTarget.classList.toggle('is-hidden', !warming);
                    if (warming) {
                        self.vocabTarget.textContent = `${self.vocabLabelValue} ${vocabDone.toLocaleString()}/${vocabTotal.toLocaleString()}`;
                    }
                }
                if (self.hasProcessedTarget) {
                    self.processedTarget.textContent = processed.toLocaleString();
                }
                if (self.hasTotalTarget) {
                    self.totalTarget.textContent = total.toLocaleString();
                }
                // Clear running/idle indicator. While running, show the queue size (it
                // visibly drains) so it's obvious work is happening even when slow.
                if (self.hasStatusTarget) {
                    if (running) {
                        self.statusTarget.textContent = `${self.activeLabelValue} — ${pending.toLocaleString()} ${self.remainingLabelValue}`;
                        self.statusTarget.className = 'tag is-info';
                    } else {
                        self.statusTarget.textContent = self.idleLabelValue;
                        self.statusTarget.className = 'tag is-light';
                    }
                }
                // Disable the launch buttons while a run is in progress so it can't be started twice.
                self.launchTargets.forEach((button) => { button.disabled = running; });
                if (self.hasBarTarget) {
                    // max=0 makes <progress> indeterminate; clamp to 1 so an empty library reads 0%.
                    self.barTarget.max = total || 1;
                    self.barTarget.value = processed;
                }
                self.timer = setTimeout(() => self.refresh(), self.pollInterval);
            })
            .catch(function () {
                // Soft-fail: stop polling on error, don't disrupt the page.
            });
    }
}

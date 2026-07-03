import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'analyzing', 'pending', 'list'];

    static values = {
        url: String,
        sourceLabels: { type: Object, default: {} }
    };

    // Bounded polling: ~1.5s interval, give up after ~20 attempts (~30s) since there
    // is no server-side "analysis complete" marker yet.
    pollInterval = 1500;
    maxAttempts = 20;

    connect() {
        if (this.hasUrlValue && this.urlValue) {
            this.attempts = 0;
            this.stopped = false;
            this.showAnalyzing();
            this.poll();
        }
    }

    disconnect() {
        // A fetch already in flight must not write to detached DOM or re-arm the timer
        // after the controller is gone (Turbo nav / form re-render).
        this.stopped = true;
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    showAnalyzing() {
        if (this.hasAnalyzingTarget) {
            this.analyzingTarget.classList.remove('is-hidden');
        }
    }

    hideAnalyzing() {
        if (this.hasAnalyzingTarget) {
            this.analyzingTarget.classList.add('is-hidden');
        }
    }

    poll() {
        let self = this;
        fetch(this.urlValue, { method: 'GET' })
            .then(response => response.json())
            .then(function (result) {
                if (self.stopped) {
                    return; // controller disconnected mid-flight
                }
                if (!result.enabled) {
                    self.hideAnalyzing();
                    return;
                }

                if (result.status === 'ready') {
                    self.hideAnalyzing();
                    self.render(result);
                    return;
                }

                // Still analyzing — keep polling until the cap, then give up quietly.
                self.attempts += 1;
                if (self.attempts >= self.maxAttempts) {
                    self.hideAnalyzing();
                    return;
                }
                self.timer = setTimeout(() => self.poll(), self.pollInterval);
            })
            .catch(function () {
                // Soft-fail: a transient error must never block tagging.
                self.hideAnalyzing();
            });
    }

    render(result) {
        // Additive prefill: append confident tags to the field, never removing what's there.
        (result.highConfidence || []).forEach((tag) => this.appendToInput(tag.name));

        if (!this.hasListTarget) {
            return;
        }
        this.listTarget.innerHTML = '';
        const rows = (result.pending || []);
        if (rows.length === 0) {
            if (this.hasPendingTarget) {
                this.pendingTarget.classList.add('is-hidden');
            }
            return;
        }
        rows.forEach((tag) => this.listTarget.appendChild(this.buildRow(tag)));
        if (this.hasPendingTarget) {
            this.pendingTarget.classList.remove('is-hidden');
        }
    }

    buildRow(tag) {
        let row = document.createElement('li');
        row.className = 'suggestion-row';
        row.dataset.suggestion = tag.name;

        let name = document.createElement('a');
        name.className = 'suggestion-name is-clickable is-category-' + tag.category;
        name.textContent = tag.name;
        name.dataset.suggestion = tag.name;
        name.dataset.action = 'click->suggestions#fillInputWithSuggestion';

        let score = document.createElement('span');
        score.className = 'suggestion-score';
        score.textContent = Math.round((tag.score || 0) * 100) + '%';

        let source = document.createElement('span');
        source.className = 'tag is-small suggestion-source suggestion-source-' + tag.source;
        source.textContent = this.sourceLabelsValue[tag.source] || tag.source;

        // Reject control: removes the row client-side before saving (no server change).
        let reject = document.createElement('button');
        reject.type = 'button';
        reject.textContent = '×';
        reject.className = 'is-clickable suggestion-remove';
        reject.dataset.action = 'click->suggestions#rejectSuggestion';

        row.append(name, source, score, reject);

        return row;
    }

    appendToInput(suggestion) {
        if (!this.inputTarget.value.split(' ').includes(suggestion)) {
            this.inputTarget.value = (this.inputTarget.value + ' ' + suggestion).trim();
        }
    }

    fillInputWithSuggestion(event) {
        this.appendToInput(event.currentTarget.dataset.suggestion);
    }

    // Add-and-remove: the "+" control adds the tag to the field, then drops its row so the
    // list closes up. Rejecting ("−") just drops the row. Either way the suggestion leaves the list.
    acceptSuggestion(event) {
        const row = event.currentTarget.closest('[data-suggestion]');
        if (row === null) {
            return;
        }
        this.appendToInput(row.dataset.suggestion);
        row.remove();
    }

    rejectSuggestion(event) {
        event.stopPropagation();
        const row = event.currentTarget.closest('[data-suggestion]');
        if (row === null) {
            return;
        }
        row.remove();
    }
}

import { Controller } from '@hotwired/stimulus';

// Semantic job state → Bulma tag colour. The backend decides the state; the controller just paints.
const STATE_CLASS = {
    starting: 'is-info', // coordinator queued/fanning out; per-item queue not yet complete
    running: 'is-info',
    waiting: 'is-warning', // queued but not yet picked up by a worker
    partial: 'is-warning', // idle but some posts still uncovered
    done: 'is-success'
};

// One controller for the whole Jobs panel: it polls a single endpoint and repaints every job card,
// so adding more jobs costs one more card — never another request or another controller.
export default class extends Controller {
    static values = {
        url: String
    };

    pollInterval = 4000;

    connect() {
        this.stopped = false;
        if (this.hasUrlValue && this.urlValue) {
            this.refresh();
        }
    }

    disconnect() {
        this.stopped = true;
        if (this.timer) {
            clearTimeout(this.timer);
        }
    }

    refresh() {
        let self = this;
        fetch(this.urlValue, { method: 'GET' })
            .then(response => response.json())
            .then(function (jobs) {
                // A fetch in flight during disconnect (Turbo nav) must not repaint a detached
                // element nor re-arm the timer, else polling leaks forever.
                if (self.stopped) {
                    return;
                }
                self.element.querySelectorAll('[data-job-status-key]').forEach((card) => {
                    let job = jobs[card.dataset.jobStatusKey];
                    if (job) {
                        self.paint(card, job);
                    }
                });
                self.timer = setTimeout(() => self.refresh(), self.pollInterval);
            })
            .catch(function () {
                // Soft-fail: stop polling on error, don't disrupt the page.
            });
    }

    paint(card, job) {
        let status = card.querySelector('[data-job-role="status"]');
        if (status) {
            status.textContent = job.label;
            status.className = `tag ${STATE_CLASS[job.state] || 'is-light'}`;
        }

        let bar = card.querySelector('[data-job-role="bar"]');
        if (bar) {
            bar.classList.toggle('is-hidden', !job.showBar);
            // max=0 makes <progress> indeterminate; clamp to 1 so an empty library reads 0%.
            bar.max = Number(job.total) || 1;
            bar.value = Number(job.processed) || 0;
        }

        // Disable the launch buttons while a run is in flight so a second can't stack on top.
        card.querySelectorAll('[data-job-role="launch"]').forEach((button) => {
            button.disabled = !!job.running;
        });

        // Cancel is the inverse: only actionable while there's a run to cancel.
        card.querySelectorAll('[data-job-role="cancel"]').forEach((button) => {
            button.disabled = !job.running;
        });
    }
}

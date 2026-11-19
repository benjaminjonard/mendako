import { Controller } from '@hotwired/stimulus';

const STATE_CLASS = {
    starting: 'is-info',
    running: 'is-info',
    waiting: 'is-warning',
    partial: 'is-warning',
    done: 'is-success'
};

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
            bar.max = Number(job.total) || 1;
            bar.value = Number(job.processed) || 0;
        }

        card.querySelectorAll('[data-job-role="launch"]').forEach((button) => {
            button.disabled = !!job.running;
        });

        card.querySelectorAll('[data-job-role="cancel"]').forEach((button) => {
            button.disabled = !job.running;
        });
    }
}

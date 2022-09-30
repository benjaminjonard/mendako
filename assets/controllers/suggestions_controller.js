import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'label'];

    fillInputWithSuggestion(event) {
        let suggestion = event.target.dataset.suggestion;
        if (!this.inputTarget.value.includes(suggestion)) {
            this.inputTarget.value += ' ' + suggestion;
        }
    }
}

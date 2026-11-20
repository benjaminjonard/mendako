import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];

    fillInputWithSuggestion(event) {
        const suggestion = event.currentTarget.dataset.suggestion;
        if (!this.inputTarget.value.split(' ').includes(suggestion)) {
            this.inputTarget.value = (this.inputTarget.value + ' ' + suggestion).trim();
        }
    }
}

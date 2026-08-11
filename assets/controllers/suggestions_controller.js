import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];

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

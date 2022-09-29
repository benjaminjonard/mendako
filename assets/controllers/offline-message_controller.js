import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    hide(event) {
        this.element.classList.add("is-hidden");
    }

    show(event) {
        this.element.classList.remove("is-hidden");
    }
}

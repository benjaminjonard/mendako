import { Controller } from '@hotwired/stimulus';
import { useClickOutside } from 'stimulus-use'

export default class extends Controller {
    connect() {
        useClickOutside(this)
    }

    clickOutside() {
        this.element.classList.remove('is-active');
    }

    display(event) {
        if (this.element.classList.contains('is-active')) {
            this.element.classList.remove('is-active');
            this.element.classList.remove('is-active');
        } else {
            this.element.classList.add('is-active');
            this.element.classList.add('is-active');
        }
    }
}

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'menu'];

    display(event) {
        if (this.buttonTarget.classList.contains('is-active')) {
            this.buttonTarget.classList.remove('is-active');
            this.menuTarget.classList.remove('is-active');
        } else {
            this.buttonTarget.classList.add('is-active');
            this.menuTarget.classList.add('is-active');
        }
    }
}

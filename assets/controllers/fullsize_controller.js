import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['buttonFullsize', 'buttonSmallsize', 'image'];

    fullsize(event) {
        this.buttonFullsizeTarget.classList.add('is-hidden');
        this.buttonSmallsizeTarget.classList.remove('is-hidden');
        this.imageTarget.classList.add('is-fullsize');
    }

    smallsize(event) {
        this.buttonSmallsizeTarget.classList.add('is-hidden');
        this.buttonFullsizeTarget.classList.remove('is-hidden');
        this.imageTarget.classList.remove('is-fullsize');
    }
}

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['image', 'video'];

    load(event) {
        let reader = new FileReader();
        let image = this.imageTarget;
        let video = this.videoTarget;

        reader.onload = function (e) {
            if (e.target.result.startsWith('data:image')) {
                video.classList.add('is-hidden');
                image.src = e.target.result;
                image.classList.remove('is-hidden');
            } else {
                image.classList.add('is-hidden');
                video.src = e.target.result;
                video.classList.remove('is-hidden');
            }
        };

        reader.readAsDataURL(event.target.files[0]);
    }
}

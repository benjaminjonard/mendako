import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['viewer', 'viewerContent'];

    static videoMimetypes = ['video/mp4', 'video/webm', 'video/x-m4v'];

    open(event) {
        const { mediaSrc, mediaMimetype } = event.currentTarget.dataset;
        if (!mediaSrc) {
            return;
        }

        let media;
        if (this.constructor.videoMimetypes.includes(mediaMimetype)) {
            media = document.createElement('video');
            media.setAttribute('controls', '');
            media.muted = true; // required for reliable autoplay
            media.setAttribute('autoplay', '');
            media.src = mediaSrc;
        } else {
            media = document.createElement('img');
            media.src = mediaSrc;
        }

        this.viewerContentTarget.replaceChildren(media);
        this.viewerTarget.classList.add('is-active');
    }

    close() {
        this.viewerTarget.classList.remove('is-active');
        // Clearing the content stops any playing video.
        this.viewerContentTarget.replaceChildren();
    }

    keydown(event) {
        if (event.key === 'Escape' && this.viewerTarget.classList.contains('is-active')) {
            this.close();
        }
    }
}

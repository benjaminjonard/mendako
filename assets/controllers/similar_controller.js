import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'list', 'wrapper'];

    static values = {
        url: String
    }

    checkSimilar(event) {
        this.wrapperTarget.classList.add('is-hidden');
        this.listTarget.innerHTML = '';
        let file = this.inputTarget.files[0];
        let formData = new FormData();
        formData.append("file", file);

        let self = this;

        fetch(this.urlValue, {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(function (result) {
                result.forEach((post) => {
                    console.log(post);
                    self.listTarget.innerHTML += post;
                })

                if (result[0]) {
                    self.wrapperTarget.classList.remove('is-hidden');
                }
            })
    }
}
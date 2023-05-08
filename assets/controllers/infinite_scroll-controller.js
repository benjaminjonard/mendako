import { Controller } from '@hotwired/stimulus';
import { useIntersection } from 'stimulus-use'

export default class extends Controller {
    static values = {
        url: String,
        page: Number
    };

    connect() {
        useIntersection(this, this.options)
    }

    appear() {
        let self = this;

        let headers = new Headers();
        headers.append("X-Requested-With", "XMLHttpRequest")

        const url = new URL(this.urlValue);
        url.searchParams.set('page', this.pageValue + 1)

        fetch(url.toString(), {
            method: 'GET',
            headers: headers
        })
            .then(response => response.json())
            .then(function (result) {
                if (result === '') {
                    self.element.remove();
                    return;
                }

                self.element.insertAdjacentHTML('beforebegin', result);
                self.pageValue +=1;
            })
    }
}

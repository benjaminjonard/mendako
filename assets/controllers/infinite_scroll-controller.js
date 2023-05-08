import { Controller } from '@hotwired/stimulus';
import { useIntersection } from 'stimulus-use'

export default class extends Controller {
    static values = {
        page: Number
    };

    static targets = [ 'tagsContainer', 'postsContainer', 'bottom', 'tag' ]

    connect() {
        useIntersection(this, {element: this.bottomTarget})
    }

    appear() {
        let self = this;

        let headers = new Headers();
        headers.append("X-Requested-With", "XMLHttpRequest")

        const url = new URL(window.location.href);
        url.searchParams.set('page', this.pageValue + 1)

        let data = {};
        data.tags = [];
        self.tagTargets.forEach((tag) => {
            data.tags.push(tag.dataset['id']);
        })

        fetch(url.toString(), {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(data)
        })
            .then(response => response.json())
            .then(function (result) {
                if (result === '') {
                    self.element.remove();
                    return;
                }

                self.postsContainerTarget.insertAdjacentHTML('beforeend', result.posts);
                self.tagsContainerTarget.innerHTML = result.tags;

                self.pageValue +=1;
            })
    }
}

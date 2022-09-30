import { Controller } from '@hotwired/stimulus';
import { useClickOutside } from 'stimulus-use'

export default class extends Controller {
    static targets = ['input', 'list', 'tag'];

    connect() {
        useClickOutside(this)
    }

    clickOutside() {
        this.listTarget.classList.add('is-hidden');
    }

    autocomplete(event) {
        let self = this;
        const lastWord = this.inputTarget.value.split(' ').pop();
        if (lastWord) {
            fetch('/tags/autocomplete?query=' + lastWord, {
                method: 'GET'
            })
                .then(response => response.json())
                .then(function(result) {
                    self.listTarget.innerHTML = '';

                    if (result.length > 0) {
                        self.listTarget.classList.remove('is-hidden');
                        result.forEach((tag) => {
                            self.listTarget.appendChild(self.tagToLi(tag));
                        })
                    } else {
                        self.listTarget.classList.add('is-hidden');
                    }
                })
        }
    }

    addToInput(event) {
        let words = this.inputTarget.value.split(' ');
        words.pop();
        words.push(event.target.innerHTML);
        this.inputTarget.value = words.join(' ') + ' ';
        this.listTarget.classList.add('is-hidden');
        this.listTarget.innerHTML = '';
    }

    tagToLi(tag) {
        let li = document.createElement('li');
        li.innerHTML = tag.name.trim();
        li.setAttribute('data-autocomplete-target', 'tag')
        li.setAttribute('data-action', 'click->autocomplete#addToInput')
        li.classList.add('is-category-' + tag.category)
        li.classList.add('is-clickable')
        return li;
    }
}
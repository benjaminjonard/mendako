import { Controller } from '@hotwired/stimulus';
import { toast } from 'bulma-toast'

export default class extends Controller {
    connect() {
        toast({
            message: this.element.dataset.message,
            closeOnClick: false,
            type: 'is-primary',
            dismissible: false,
            animate: { in: 'fadeInRight', out: 'fadeOutRight' },
            duration: 5000
        })
    }
}

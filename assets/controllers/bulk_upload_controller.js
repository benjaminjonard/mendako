import { Controller } from '@hotwired/stimulus';
import { toast } from 'bulma-toast';

const MAX_CONCURRENT_UPLOADS = 3;

export default class extends Controller {
    static targets = ['dropzone', 'input', 'grid', 'bulkBar', 'board', 'card', 'viewer', 'viewerContent', 'progress', 'progressCounter'];

    static values = {
        addUrl: String,
        assignUrl: String,
        deleteUrl: String,
        csrf: String
    };

    static videoMimetypes = ['video/mp4', 'video/webm', 'video/x-m4v'];

    static maxVisibleRows = 5;

    connect() {
        this.items = [];          // { file, state: 'pending'|'uploading'|'error', error, row }
        this.activeUploads = 0;
        this.batchTotal = 0;      // files in the current batch
        this.batchDone = 0;       // processed (succeeded + failed)
    }

    /* ---------- Drag & drop ---------- */

    dragover(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.add('is-dragover');
    }

    dragleave(event) {
        event.preventDefault();
        // Ignore dragleave fired when moving onto a child node of the dropzone.
        if (event.relatedTarget && this.dropzoneTarget.contains(event.relatedTarget)) {
            return;
        }
        this.dropzoneTarget.classList.remove('is-dragover');
    }

    drop(event) {
        event.preventDefault();
        this.dropzoneTarget.classList.remove('is-dragover');
        this.enqueueFiles(event.dataTransfer.files);
    }

    openFileDialog() {
        this.inputTarget.click();
    }

    filesSelected() {
        this.enqueueFiles(this.inputTarget.files);
        this.inputTarget.value = '';
    }

    /* ---------- Upload queue (bounded concurrency) ---------- */

    enqueueFiles(files) {
        const valid = Array.from(files).filter((file) => file && (file.size > 0 || file.type !== ''));
        if (valid.length === 0) {
            return;
        }

        // Fresh batch once the previous one has fully drained (only leftover error rows remain).
        if (this.activeUploads === 0 && !this.items.some((item) => item.state !== 'error')) {
            this.items.forEach((item) => item.row && item.row.remove());
            this.items = [];
            this.batchTotal = 0;
            this.batchDone = 0;
        }

        valid.forEach((file) => this.items.push({ file, state: 'pending', error: null, row: null }));
        this.batchTotal += valid.length;

        this.updateCounter();
        this.pumpQueue();
    }

    pumpQueue() {
        while (this.activeUploads < MAX_CONCURRENT_UPLOADS) {
            const item = this.items.find((entry) => entry.state === 'pending');
            if (!item) {
                break;
            }
            item.state = 'uploading';
            this.uploadFile(item);
        }
        this.renderWindow();
    }

    uploadFile(item) {
        this.activeUploads += 1;

        const formData = new FormData();
        formData.append('file', item.file);
        formData.append('_token', this.csrfValue);

        fetch(this.addUrlValue, { method: 'POST', body: formData })
            .then((response) => this.parseJson(response))
            .then((result) => {
                if (result.card) {
                    this.gridTarget.insertAdjacentHTML('afterbegin', result.card);
                    this.dropItem(item);
                } else {
                    item.state = 'error';
                    item.error = result.error || 'Failed';
                }
            })
            .catch(() => {
                item.state = 'error';
                item.error = 'Failed';
            })
            .finally(() => {
                this.activeUploads -= 1;
                this.batchDone += 1;
                this.updateCounter();
                this.pumpQueue();
            });
    }

    dropItem(item) {
        const index = this.items.indexOf(item);
        if (index !== -1) {
            this.items.splice(index, 1);
        }
        if (item.row) {
            item.row.remove();
            item.row = null;
        }
    }

    /* ---------- Progress window (max N rows) + counter ---------- */

    updateCounter() {
        if (!this.hasProgressCounterTarget) {
            return;
        }

        const draining = this.items.length > 0 || this.activeUploads > 0;
        if (this.batchTotal === 0 || !draining) {
            this.progressCounterTarget.classList.add('is-hidden');
            return;
        }

        this.progressCounterTarget.classList.remove('is-hidden');
        this.progressCounterTarget.textContent = `${this.batchDone}/${this.batchTotal}`;
    }

    renderWindow() {
        if (!this.hasProgressTarget) {
            return;
        }

        // Prioritise in-flight uploads, then pending, then errors; cap to maxVisibleRows.
        const order = { uploading: 0, pending: 1, error: 2 };
        const visible = [...this.items]
            .sort((a, b) => order[a.state] - order[b.state])
            .slice(0, this.constructor.maxVisibleRows);

        // Drop rows that fell out of the window.
        this.items.forEach((item) => {
            if (item.row && !visible.includes(item)) {
                item.row.remove();
                item.row = null;
            }
        });

        // Create/refresh visible rows in priority order.
        visible.forEach((item) => {
            if (!item.row) {
                item.row = this.buildRow(item.file);
            }
            this.progressTarget.append(item.row);
            this.applyRowState(item);
        });
    }

    buildRow(file) {
        const row = document.createElement('li');
        row.className = 'bulk-upload-progress-item';

        const name = document.createElement('span');
        name.className = 'bulk-upload-progress-name';
        name.textContent = file.name;

        const status = document.createElement('span');
        status.className = 'bulk-upload-progress-status';

        row.append(name, status);

        return row;
    }

    applyRowState(item) {
        item.row.dataset.state = item.state;
        const status = item.row.querySelector('.bulk-upload-progress-status');
        if (status) {
            status.textContent = item.state === 'uploading'
                ? 'Uploading…'
                : (item.state === 'error' ? (item.error || 'Failed') : 'Pending');
        }
    }

    /* ---------- Media viewer ---------- */

    openViewer(event) {
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

    showDuplicates(event) {
        const url = event.currentTarget.dataset.similarUrl;
        if (!url) {
            return;
        }

        fetch(url)
            .then((response) => this.parseJson(response))
            .then((result) => {
                const items = result.similar || [];
                if (items.length === 0) {
                    this.notifyError('No matching images found');
                    return;
                }
                const grid = document.createElement('div');
                grid.className = 'grid grid-posts bulk-upload-duplicates';
                grid.innerHTML = items.join('');
                this.viewerContentTarget.replaceChildren(grid);
                this.viewerTarget.classList.add('is-active');
            })
            .catch(() => this.notifyError('Could not load duplicates'));
    }

    closeViewer() {
        this.viewerTarget.classList.remove('is-active');
        // Clearing the content stops any playing video.
        this.viewerContentTarget.replaceChildren();
    }

    viewerKeydown(event) {
        if (event.key === 'Escape' && this.viewerTarget.classList.contains('is-active')) {
            this.closeViewer();
        }
    }

    /* ---------- Selection ---------- */

    toggleSelection() {
        // Selection state is read on demand by the bulk actions; nothing to toggle visually.
    }

    selectAll() {
        this.cardTargets.forEach((card) => {
            const checkbox = card.querySelector('input[type=checkbox]');
            if (checkbox) {
                checkbox.checked = true;
            }
        });
    }

    selectedIds() {
        return this.cardTargets
            .filter((card) => card.querySelector('input[type=checkbox]')?.checked)
            .map((card) => card.dataset.bulkUploadId);
    }

    /* ---------- Bulk actions ---------- */

    assignSelected() {
        this.assign(this.selectedIds());
    }

    deleteSelected() {
        this.remove(this.selectedIds());
    }

    /* ---------- Individual actions ---------- */

    assignOne(event) {
        this.assign([event.currentTarget.dataset.bulkUploadId]);
    }

    deleteOne(event) {
        this.remove([event.currentTarget.dataset.bulkUploadId]);
    }

    /* ---------- HTTP ---------- */

    assign(ids) {
        if (ids.length === 0) {
            return;
        }

        const board = this.boardTarget.value;
        if (!board) {
            this.notifyError('Select a board first');
            return;
        }

        const formData = this.baseFormData(ids);
        formData.append('board', board);

        this.post(this.assignUrlValue, formData);
    }

    remove(ids) {
        if (ids.length === 0) {
            return;
        }

        this.post(this.deleteUrlValue, this.baseFormData(ids));
    }

    baseFormData(ids) {
        const formData = new FormData();
        formData.append('_token', this.csrfValue);
        ids.forEach((id) => formData.append('ids[]', id));

        return formData;
    }

    post(url, formData) {
        fetch(url, { method: 'POST', body: formData })
            .then((response) => this.parseJson(response))
            .then((result) => {
                if (result.error) {
                    this.notifyError(result.error);
                    return;
                }
                (result.removedIds || []).forEach((id) => this.removeCard(id));
            })
            .catch(() => this.notifyError('Action failed'));
    }

    parseJson(response) {
        return response.json().catch(() => ({ error: 'Unexpected server response' }));
    }

    removeCard(id) {
        const card = this.cardTargets.find((element) => element.dataset.bulkUploadId === id);
        if (card) {
            card.remove();
        }
    }

    notifyError(message) {
        toast({
            message,
            type: 'is-danger',
            dismissible: true,
            duration: 5000,
            position: 'top-right',
            extraClasses: 'toast'
        });
    }
}

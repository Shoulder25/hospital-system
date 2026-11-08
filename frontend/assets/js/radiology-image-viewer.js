'use strict';

(() => {
    let dialog = null;
    let image = null;
    let viewport = null;
    let scale = 1;
    let fitScale = 1;
    let minimumScale = 0.05;
    let naturalWidth = 0;
    let zoomLabel = null;

    function renderImageSize() {
        if (!image || !naturalWidth) return;
        image.style.width = `${Math.round(naturalWidth * scale)}px`;
        if (zoomLabel) zoomLabel.textContent = `${Math.round(scale * 100)}%`;
    }

    function createDialog() {
        dialog = document.createElement('dialog');
        dialog.className = 'radiology-image-viewer';
        dialog.setAttribute('aria-label', 'Medical image viewer');

        const header = document.createElement('div');
        header.className = 'radiology-image-viewer-header';
        const title = document.createElement('strong');
        title.dataset.viewerTitle = '';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'button button-outline button-small';
        close.textContent = 'Close image';
        close.addEventListener('click', () => dialog.close());
        header.append(title, close);

        viewport = document.createElement('div');
        viewport.className = 'radiology-image-viewer-viewport';
        image = document.createElement('img');
        image.className = 'radiology-image-viewer-image';
        image.addEventListener('load', () => {
            naturalWidth = image.naturalWidth;
            const availableWidth = Math.max(1, viewport.clientWidth - 24);
            const availableHeight = Math.max(1, viewport.clientHeight - 24);
            fitScale = Math.min(
                1,
                availableWidth / image.naturalWidth,
                availableHeight / image.naturalHeight
            );
            scale = fitScale;
            minimumScale = Math.min(0.05, fitScale);
            renderImageSize();
        });
        viewport.appendChild(image);

        const actions = document.createElement('div');
        actions.className = 'radiology-image-viewer-actions';
        const zoomOut = document.createElement('button');
        zoomOut.type = 'button';
        zoomOut.textContent = 'Zoom out';
        zoomOut.addEventListener('click', () => {
            scale = Math.max(minimumScale, scale / 1.25);
            renderImageSize();
        });
        const zoomIn = document.createElement('button');
        zoomIn.type = 'button';
        zoomIn.textContent = 'Zoom in';
        zoomIn.addEventListener('click', () => {
            scale = Math.min(4, scale * 1.25);
            renderImageSize();
        });
        const reset = document.createElement('button');
        reset.type = 'button';
        reset.textContent = 'Fit image';
        reset.addEventListener('click', () => {
            if (!image || !naturalWidth) return;
            scale = fitScale;
            renderImageSize();
            viewport.scrollTo({ top: 0, left: 0 });
        });
        zoomLabel = document.createElement('span');
        zoomLabel.setAttribute('aria-live', 'polite');
        actions.append(zoomOut, zoomIn, reset, zoomLabel);

        dialog.append(header, viewport, actions);
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        document.body.appendChild(dialog);
    }

    window.CareBridgeRadiologyImageViewer = {
        open(src, alt, title) {
            if (!dialog) createDialog();
            const heading = dialog.querySelector('[data-viewer-title]');
            heading.textContent = title || alt || 'Medical image';
            image.alt = alt || heading.textContent;
            naturalWidth = 0;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
                image.src = src;
            } else {
                window.open(src, '_blank', 'noopener');
            }
        },
    };
})();

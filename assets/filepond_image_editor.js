/**
 * Bildeditor für FilePond: Zuschneiden (frei oder mit festem Seitenverhältnis),
 * 90°-Drehung und Spiegeln.
 *
 * Zwei Wege:
 * - `createFilePondEditor()` liefert ein Editor-Objekt für filepond-plugin-image-edit
 *   (Option `imageEditEditor`). Das Ergebnis landet als `crop`-Metadatum am FilePond-Item,
 *   image-preview zeigt es an und image-transform rechnet es beim Upload ein.
 * - `edit(file)` gibt ein Promise mit dem fertig gerenderten Blob zurück (Metadaten-Dialog).
 */
(function () {
    'use strict';

    const EDITABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const MIN_SIZE = 16;
    const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

    const TEXTS = {
        de_de: {
            title: 'Bild bearbeiten',
            aspect: 'Seitenverhältnis',
            free: 'Frei',
            original: 'Original',
            rotateLeft: 'Nach links drehen',
            rotateRight: 'Nach rechts drehen',
            flipH: 'Horizontal spiegeln',
            flipV: 'Vertikal spiegeln',
            reset: 'Zurücksetzen',
            cancel: 'Abbrechen',
            apply: 'Übernehmen',
            cropArea: 'Zuschnitt, mit Pfeiltasten verschieben, mit Umschalt + Pfeiltasten Größe ändern',
            loadError: 'Das Bild konnte nicht geladen werden.'
        },
        en_gb: {
            title: 'Edit image',
            aspect: 'Aspect ratio',
            free: 'Free',
            original: 'Original',
            rotateLeft: 'Rotate left',
            rotateRight: 'Rotate right',
            flipH: 'Flip horizontally',
            flipV: 'Flip vertically',
            reset: 'Reset',
            cancel: 'Cancel',
            apply: 'Apply',
            cropArea: 'Crop area, move with arrow keys, resize with Shift + arrow keys',
            loadError: 'The image could not be loaded.'
        }
    };

    const ASPECTS = [
        ['free', null],
        ['original', 'original'],
        ['1:1', 1],
        ['4:3', 4 / 3],
        ['3:2', 3 / 2],
        ['16:9', 16 / 9],
        ['3:4', 3 / 4],
        ['2:3', 2 / 3],
        ['9:16', 9 / 16]
    ];

    const isEditable = (file) => !!file && EDITABLE_TYPES.includes(file.type);

    const loadBitmap = async (file) => {
        if (window.createImageBitmap) {
            try {
                return await createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch (e) {
                // Fallback über <img>
            }
        }
        const url = URL.createObjectURL(file);
        try {
            const img = new Image();
            img.decoding = 'async';
            img.src = url;
            await img.decode();
            return img;
        } finally {
            URL.revokeObjectURL(url);
        }
    };

    const bitmapSize = (bitmap) => ({
        width: bitmap.naturalWidth || bitmap.width,
        height: bitmap.naturalHeight || bitmap.height
    });

    /** Zeichnet das gedrehte/gespiegelte Bild ("Ansicht") in ctx, Ursprung oben links der Ansicht. */
    const drawView = (ctx, bitmap, state) => {
        const { width: W, height: H } = bitmapSize(bitmap);
        const view = viewSize(W, H, state.quarter);
        ctx.translate(view.width / 2, view.height / 2);
        ctx.rotate(state.quarter * Math.PI / 2);
        ctx.scale(state.flipH ? -1 : 1, state.flipV ? -1 : 1);
        ctx.drawImage(bitmap, -W / 2, -H / 2, W, H);
    };

    const viewSize = (W, H, quarter) => (quarter % 2 ? { width: H, height: W } : { width: W, height: H });

    /** Größtes Rechteck mit Verhältnis ratio (Breite/Höhe), zentriert in der Ansicht. */
    const fitRect = (view, ratio) => {
        if (!ratio) {
            return { x: 0, y: 0, w: view.width, h: view.height };
        }
        let w = view.width;
        let h = w / ratio;
        if (h > view.height) {
            h = view.height;
            w = h * ratio;
        }
        return { x: (view.width - w) / 2, y: (view.height - h) / 2, w, h };
    };

    /**
     * Rechnet den Zuschnitt (Ansichtskoordinaten) in FilePonds crop-Format um, so wie es
     * image-transform und image-preview auswerten (Mittelpunkt im gespiegelten Bild, Drehung um
     * diesen Punkt, Ausschnittsbreite über zoom).
     */
    const toFilePondCrop = (state, W, H) => {
        const view = viewSize(W, H, state.quarter);
        const rect = state.rect;
        const angle = state.quarter * Math.PI / 2;
        const cos = Math.round(Math.cos(angle));
        const sin = Math.round(Math.sin(angle));

        const dx = rect.x + rect.w / 2 - view.width / 2;
        const dy = rect.y + rect.h / 2 - view.height / 2;
        const bx = dx * cos + dy * sin;
        const by = -dx * sin + dy * cos;

        const aspectRatio = rect.h / rect.w;

        // Ausschnittsbreite, die image-transform bei zoom = 1 erzeugt
        const imageRatio = H / W;
        let imgW = 1;
        let imgH = imageRatio;
        if (imgH > aspectRatio) {
            imgH = aspectRatio;
            imgW = imgH / imageRatio;
        }
        const scalar = Math.max(1 / imgW, aspectRatio / imgH);
        const canvasW = W / (scalar * imgW);
        const canvasH = canvasW * aspectRatio;
        const rotatedW = state.quarter % 2 ? canvasH : canvasW;
        const rotatedH = state.quarter % 2 ? canvasW : canvasH;
        const widthAtZoom1 = canvasW / Math.max(rotatedW / W, rotatedH / H);

        return {
            center: { x: (bx + W / 2) / W, y: (by + H / 2) / H },
            rotation: angle,
            zoom: widthAtZoom1 / rect.w,
            aspectRatio,
            flip: { horizontal: state.flipH, vertical: state.flipV },
            scaleToFit: false,
            // Eigener Zustand, damit ein erneutes Öffnen den Zuschnitt wiederherstellt
            fpEditor: { quarter: state.quarter, flipH: state.flipH, flipV: state.flipV, aspect: state.aspect, rect: { ...rect }, width: W, height: H }
        };
    };

    const renderBlob = (bitmap, state, type, quality) => {
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(state.rect.w));
        canvas.height = Math.max(1, Math.round(state.rect.h));
        const ctx = canvas.getContext('2d');
        if (type === 'image/jpeg') {
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        }
        ctx.translate(-state.rect.x, -state.rect.y);
        drawView(ctx, bitmap, state);
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('toBlob failed'))), type, quality);
        });
    };

    const button = (className, label, icon) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.className = className;
        el.title = label;
        el.setAttribute('aria-label', label);
        el.innerHTML = icon;
        return el;
    };

    const ICONS = {
        rotateLeft: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.1 8.5 4 5.4V11h5.6L7.5 8.9A6 6 0 1 1 6 13H4a8 8 0 1 0 3.1-4.5z"/></svg>',
        rotateRight: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.9 8.5 20 5.4V11h-5.6l2.1-2.1A6 6 0 1 0 18 13h2a8 8 0 1 1-3.1-4.5z"/></svg>',
        flipH: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 3h2v18h-2zM3 18 9 6v12zm18 0h-6V6z" /></svg>',
        flipV: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11h18v2H3zM6 3h12L6 9zm0 18 12-6v6z" /></svg>',
        reset: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7z"/></svg>'
    };

    /**
     * Öffnet den Editor. Ergebnis: { state, crop, bitmap } oder null bei Abbruch.
     */
    const openEditor = async (file, options = {}) => {
        const t = TEXTS[options.lang] || TEXTS.de_de;
        const bitmap = await loadBitmap(file);
        const { width: W, height: H } = bitmapSize(bitmap);

        const initial = options.initial && options.initial.width === W && options.initial.height === H ? options.initial : null;
        const state = {
            quarter: initial ? initial.quarter : 0,
            flipH: initial ? initial.flipH : false,
            flipV: initial ? initial.flipV : false,
            aspect: initial ? initial.aspect : 'free',
            rect: null
        };
        const view = () => viewSize(W, H, state.quarter);
        const ratioOf = (aspect) => {
            const entry = ASPECTS.find(([key]) => key === aspect);
            if (!entry || !entry[1]) return null;
            return entry[1] === 'original' ? view().width / view().height : entry[1];
        };
        state.rect = initial ? { ...initial.rect } : fitRect(view(), ratioOf(state.aspect));

        const previousFocus = document.activeElement;
        const root = document.createElement('div');
        root.className = 'fp-image-editor';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', t.title);

        const panel = document.createElement('div');
        panel.className = 'fp-image-editor__panel';
        root.appendChild(panel);

        const header = document.createElement('div');
        header.className = 'fp-image-editor__toolbar';
        const title = document.createElement('h2');
        title.className = 'fp-image-editor__title';
        title.textContent = t.title;
        header.appendChild(title);

        const aspectLabel = document.createElement('label');
        aspectLabel.className = 'fp-image-editor__aspect';
        const aspectText = document.createElement('span');
        aspectText.textContent = t.aspect;
        const aspectSelect = document.createElement('select');
        for (const [key] of ASPECTS) {
            const option = document.createElement('option');
            option.value = key;
            option.textContent = key === 'free' ? t.free : key === 'original' ? t.original : key;
            aspectSelect.appendChild(option);
        }
        aspectSelect.value = state.aspect;
        aspectLabel.append(aspectText, aspectSelect);
        header.appendChild(aspectLabel);

        const tools = document.createElement('div');
        tools.className = 'fp-image-editor__tools';
        const btnRotateLeft = button('fp-image-editor__tool', t.rotateLeft, ICONS.rotateLeft);
        const btnRotateRight = button('fp-image-editor__tool', t.rotateRight, ICONS.rotateRight);
        const btnFlipH = button('fp-image-editor__tool', t.flipH, ICONS.flipH);
        const btnFlipV = button('fp-image-editor__tool', t.flipV, ICONS.flipV);
        const btnReset = button('fp-image-editor__tool', t.reset, ICONS.reset);
        tools.append(btnRotateLeft, btnRotateRight, btnFlipH, btnFlipV, btnReset);
        header.appendChild(tools);
        panel.appendChild(header);

        const stage = document.createElement('div');
        stage.className = 'fp-image-editor__stage';
        const frame = document.createElement('div');
        frame.className = 'fp-image-editor__frame';
        const canvas = document.createElement('canvas');
        canvas.className = 'fp-image-editor__canvas';
        const cropEl = document.createElement('div');
        cropEl.className = 'fp-image-editor__crop';
        cropEl.tabIndex = 0;
        cropEl.setAttribute('role', 'slider');
        cropEl.setAttribute('aria-label', t.cropArea);
        for (const handle of HANDLES) {
            const h = document.createElement('span');
            h.className = 'fp-image-editor__handle fp-image-editor__handle--' + handle;
            h.dataset.handle = handle;
            cropEl.appendChild(h);
        }
        frame.append(canvas, cropEl);
        stage.appendChild(frame);
        panel.appendChild(stage);

        const footer = document.createElement('div');
        footer.className = 'fp-image-editor__footer';
        const info = document.createElement('span');
        info.className = 'fp-image-editor__info';
        info.setAttribute('aria-live', 'polite');
        const btnCancel = button('btn btn-default', t.cancel, '');
        btnCancel.textContent = t.cancel;
        const btnApply = button('btn btn-primary', t.apply, '');
        btnApply.textContent = t.apply;
        footer.append(info, btnCancel, btnApply);
        panel.appendChild(footer);

        document.body.appendChild(root);
        document.documentElement.classList.add('fp-image-editor-open');

        let scale = 1;

        const layout = () => {
            const v = view();
            const maxW = stage.clientWidth;
            const maxH = stage.clientHeight;
            scale = Math.min(maxW / v.width, maxH / v.height, 1);
            const cssW = Math.max(1, Math.floor(v.width * scale));
            const cssH = Math.max(1, Math.floor(v.height * scale));
            const dpr = window.devicePixelRatio || 1;
            canvas.style.width = cssW + 'px';
            canvas.style.height = cssH + 'px';
            frame.style.width = cssW + 'px';
            frame.style.height = cssH + 'px';
            canvas.width = Math.round(cssW * dpr);
            canvas.height = Math.round(cssH * dpr);
            const ctx = canvas.getContext('2d');
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.scale(canvas.width / v.width, canvas.height / v.height);
            drawView(ctx, bitmap, state);
            updateCrop();
        };

        const updateCrop = () => {
            const r = state.rect;
            cropEl.style.left = r.x * scale + 'px';
            cropEl.style.top = r.y * scale + 'px';
            cropEl.style.width = r.w * scale + 'px';
            cropEl.style.height = r.h * scale + 'px';
            const text = Math.round(r.w) + ' × ' + Math.round(r.h) + ' px';
            info.textContent = text;
            cropEl.setAttribute('aria-valuetext', text);
        };

        const clampRect = (r) => {
            const v = view();
            const w = Math.min(Math.max(r.w, MIN_SIZE), v.width);
            const h = Math.min(Math.max(r.h, MIN_SIZE), v.height);
            return {
                x: Math.min(Math.max(r.x, 0), v.width - w),
                y: Math.min(Math.max(r.y, 0), v.height - h),
                w,
                h
            };
        };

        const resetCrop = () => {
            state.rect = fitRect(view(), ratioOf(state.aspect));
            layout();
        };

        const resizeRect = (start, handle, dx, dy) => {
            const v = view();
            const ratio = ratioOf(state.aspect);
            let left = start.x;
            let top = start.y;
            let right = start.x + start.w;
            let bottom = start.y + start.h;

            if (handle.includes('w')) left = Math.min(Math.max(0, left + dx), right - MIN_SIZE);
            if (handle.includes('e')) right = Math.max(Math.min(v.width, right + dx), left + MIN_SIZE);
            if (handle.includes('n')) top = Math.min(Math.max(0, top + dy), bottom - MIN_SIZE);
            if (handle.includes('s')) bottom = Math.max(Math.min(v.height, bottom + dy), top + MIN_SIZE);

            if (!ratio) {
                return { x: left, y: top, w: right - left, h: bottom - top };
            }

            // Festes Verhältnis: an der gegenüberliegenden Seite bzw. Ecke verankern
            let w = right - left;
            let h = bottom - top;
            if (handle === 'n' || handle === 's') {
                w = h * ratio;
            } else if (handle === 'e' || handle === 'w') {
                h = w / ratio;
            } else if (w / h > ratio) {
                w = h * ratio;
            } else {
                h = w / ratio;
            }

            const anchorX = handle.includes('w') ? start.x + start.w : handle.includes('e') ? start.x : start.x + start.w / 2;
            const anchorY = handle.includes('n') ? start.y + start.h : handle.includes('s') ? start.y : start.y + start.h / 2;
            const maxW = handle.includes('w') ? anchorX : handle.includes('e') ? v.width - anchorX : 2 * Math.min(anchorX, v.width - anchorX);
            const maxH = handle.includes('n') ? anchorY : handle.includes('s') ? v.height - anchorY : 2 * Math.min(anchorY, v.height - anchorY);
            const fit = Math.min(1, maxW / w, maxH / h);
            w *= fit;
            h *= fit;

            const x = handle.includes('w') ? anchorX - w : handle.includes('e') ? anchorX : anchorX - w / 2;
            const y = handle.includes('n') ? anchorY - h : handle.includes('s') ? anchorY : anchorY - h / 2;
            return { x, y, w, h };
        };

        let drag = null;
        const onPointerDown = (event) => {
            if (event.button !== 0) return;
            event.preventDefault();
            cropEl.focus({ preventScroll: true });
            drag = {
                handle: event.target.dataset.handle || 'move',
                startX: event.clientX,
                startY: event.clientY,
                rect: { ...state.rect }
            };
            cropEl.setPointerCapture(event.pointerId);
        };
        const onPointerMove = (event) => {
            if (!drag) return;
            const dx = (event.clientX - drag.startX) / scale;
            const dy = (event.clientY - drag.startY) / scale;
            if (drag.handle === 'move') {
                state.rect = clampRect({ ...drag.rect, x: drag.rect.x + dx, y: drag.rect.y + dy });
            } else {
                state.rect = resizeRect(drag.rect, drag.handle, dx, dy);
            }
            updateCrop();
        };
        const onPointerUp = (event) => {
            if (!drag) return;
            drag = null;
            if (cropEl.hasPointerCapture(event.pointerId)) {
                cropEl.releasePointerCapture(event.pointerId);
            }
        };
        cropEl.addEventListener('pointerdown', onPointerDown);
        cropEl.addEventListener('pointermove', onPointerMove);
        cropEl.addEventListener('pointerup', onPointerUp);
        cropEl.addEventListener('pointercancel', onPointerUp);

        cropEl.addEventListener('keydown', (event) => {
            const step = (event.altKey ? 1 : 10) / Math.max(scale, 0.01);
            const keys = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
            if (!keys[event.key]) return;
            event.preventDefault();
            const [dx, dy] = keys[event.key];
            state.rect = event.shiftKey
                ? resizeRect(state.rect, 'se', dx, dy)
                : clampRect({ ...state.rect, x: state.rect.x + dx, y: state.rect.y + dy });
            updateCrop();
        });

        const rotate = (direction) => {
            state.quarter = (state.quarter + direction + 4) % 4;
            resetCrop();
        };
        // Spiegeln wirkt auf die Ansicht: bei 90°/270° entspricht "horizontal" der Bild-Vertikalen
        const flip = (horizontal) => {
            const flipBitmapH = horizontal === (state.quarter % 2 === 0);
            if (flipBitmapH) {
                state.flipH = !state.flipH;
            } else {
                state.flipV = !state.flipV;
            }
            const v = view();
            state.rect = horizontal
                ? { ...state.rect, x: v.width - state.rect.x - state.rect.w }
                : { ...state.rect, y: v.height - state.rect.y - state.rect.h };
            layout();
        };

        btnRotateLeft.addEventListener('click', () => rotate(-1));
        btnRotateRight.addEventListener('click', () => rotate(1));
        btnFlipH.addEventListener('click', () => flip(true));
        btnFlipV.addEventListener('click', () => flip(false));
        btnReset.addEventListener('click', () => {
            state.quarter = 0;
            state.flipH = false;
            state.flipV = false;
            state.aspect = 'free';
            aspectSelect.value = 'free';
            resetCrop();
        });
        aspectSelect.addEventListener('change', () => {
            state.aspect = aspectSelect.value;
            resetCrop();
        });

        const resizeObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => layout()) : null;
        if (resizeObserver) {
            resizeObserver.observe(stage);
        } else {
            window.addEventListener('resize', layout);
        }

        return new Promise((resolve) => {
            const close = (result) => {
                if (resizeObserver) {
                    resizeObserver.disconnect();
                } else {
                    window.removeEventListener('resize', layout);
                }
                document.removeEventListener('keydown', onKeydown, true);
                root.remove();
                document.documentElement.classList.remove('fp-image-editor-open');
                if (previousFocus && typeof previousFocus.focus === 'function') {
                    previousFocus.focus({ preventScroll: true });
                }
                resolve(result);
            };
            const onKeydown = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    close(null);
                } else if (event.key === 'Enter' && event.target === cropEl) {
                    event.preventDefault();
                    btnApply.click();
                } else if (event.key === 'Tab') {
                    // Fokus im Dialog halten
                    const focusable = [...root.querySelectorAll('button, select, [tabindex="0"]')];
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            };
            document.addEventListener('keydown', onKeydown, true);
            btnCancel.addEventListener('click', () => close(null));
            btnApply.addEventListener('click', () => {
                close({ state: { ...state, rect: { ...state.rect } }, crop: toFilePondCrop(state, W, H), bitmap });
            });
            layout();
            cropEl.focus({ preventScroll: true });
        });
    };

    /**
     * Bearbeitet eine Datei und liefert eine neue File mit dem Ergebnis, null bei Abbruch.
     */
    const edit = async (file, options = {}) => {
        let result;
        try {
            result = await openEditor(file, options);
        } catch (e) {
            const t = TEXTS[options.lang] || TEXTS.de_de;
            window.alert(t.loadError);
            return null;
        }
        if (!result) return null;
        const type = EDITABLE_TYPES.includes(file.type) ? file.type : 'image/jpeg';
        const blob = await renderBlob(result.bitmap, result.state, type, (options.quality || 90) / 100);
        const name = file.name || options.filename || 'image';
        const edited = new File([blob], name, { type, lastModified: Date.now() });
        edited.fpEditorState = { ...result.crop.fpEditor };
        return edited;
    };

    /**
     * Editor-Objekt im Format, das filepond-plugin-image-edit erwartet.
     */
    const createFilePondEditor = (options = {}) => {
        const editor = {
            open(file, parameters) {
                if (!isEditable(file)) {
                    editor.oncancel();
                    editor.onclose && editor.onclose();
                    return;
                }
                const initial = parameters && parameters.crop && parameters.crop.fpEditor ? parameters.crop.fpEditor : null;
                openEditor(file, { ...options, initial })
                    .then((result) => {
                        if (result) {
                            editor.onconfirm({ data: { crop: result.crop } });
                        } else {
                            editor.oncancel();
                        }
                        editor.onclose && editor.onclose();
                    })
                    .catch(() => {
                        editor.oncancel();
                        editor.onclose && editor.onclose();
                    });
            },
            onconfirm() {},
            oncancel() {},
            onclose: null
        };
        return editor;
    };

    window.FilePondImageEditor = { isEditable, edit, createFilePondEditor, toFilePondCrop };
})();

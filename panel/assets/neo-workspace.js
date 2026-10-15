/* Progressive enhancements for administrative pages; existing server actions stay authoritative. */
(() => {
    'use strict';
    if (!document.body.classList.contains('neo-workspace')) return;
    const report = document.getElementById('neo-workflow-report');
    const reportToggle = document.getElementById('neo-workflow-toggle');
    if (report && reportToggle) {
        const reportMedia = matchMedia('(max-width:767px)');
        let reportTouched = false;
        const syncReport = () => {if (!reportTouched) report.hidden = reportMedia.matches;reportToggle.setAttribute('aria-expanded', String(!report.hidden));};
        reportMedia.addEventListener('change', syncReport);syncReport();
        reportToggle.addEventListener('click', () => {
            reportTouched = true;report.hidden = !report.hidden;
            reportToggle.setAttribute('aria-expanded', String(!report.hidden));
        });
    }
    document.querySelectorAll('.product-item[role=button]').forEach(card => {
        card.addEventListener('keydown', event => {
            if (event.target !== card || !['Enter', ' '].includes(event.key)) return;
            event.preventDefault();card.click();
        });
    });
    const fileInput = document.getElementById('excel-file');
    const dropzone = document.getElementById('neo-import-dropzone');
    const feedback = document.getElementById('neo-import-feedback');
    if (fileInput && dropzone) {
        const extensions = fileInput.accept.split(',').map(value => value.trim().toLowerCase());
        const validate = files => {
            const valid = files.length === 1 && extensions.includes('.' + files[0].name.split('.').pop().toLowerCase());
            feedback.textContent = valid ? '' : 'یک فایل با فرمت مجاز انتخاب کنید: ' + extensions.join('، ');
            return valid;
        };
        fileInput.addEventListener('change', event => {
            if (!validate(fileInput.files)) {event.stopImmediatePropagation();fileInput.value = '';}
        }, true);
        ['dragenter', 'dragover'].forEach(name => dropzone.addEventListener(name, event => {
            event.preventDefault();dropzone.classList.add('neo-drag-over');
        }));
        dropzone.addEventListener('dragleave', event => {
            if (!dropzone.contains(event.relatedTarget)) dropzone.classList.remove('neo-drag-over');
        });
        dropzone.addEventListener('drop', event => {
            event.preventDefault();dropzone.classList.remove('neo-drag-over');
            const files = event.dataTransfer.files;
            if (!validate(files)) return;
            fileInput.files = files;fileInput.dispatchEvent(new Event('change', {bubbles:true}));
        });
    }
    const disclosures = [
        ['button[onclick="toggleManualBox()"]', 'add-manual-product-box'],
        ['button[onclick="toggleProductList()"]', 'product-list-grid-wrapper'],
        ['#btn-col-sys', 'panel-col-sys'], ['#btn-col-schedule', 'panel-col-schedule'],
        ['#btn-col-instagram', 'panel-col-instagram'], ['#btn-col-sms', 'panel-col-sms']
    ];
    disclosures.forEach(([selector, id]) => {
        const panel = document.getElementById(id);
        if (!panel) return;
        const controls = document.querySelectorAll(selector);
        controls.forEach(control => control.setAttribute('aria-controls', id));
        const sync = () => controls.forEach(control => control.setAttribute('aria-expanded', String(getComputedStyle(panel).display !== 'none')));
        new MutationObserver(sync).observe(panel, {attributes:true,attributeFilter:['class','style']});sync();
    });
    // Enhance only ordinary form dialogs. Visual editors keep their own interaction model.
    const dialogs = [
        ['editModal', () => {document.getElementById('editModal').style.display = 'none';}],
        ['editUserModal', () => {document.getElementById('editUserModal').style.display = 'none';}],
        ['contentScheduleModal', () => window.closeContentScheduleModal()],
        ['add-occasion-modal', () => document.getElementById('add-occasion-modal').classList.add('hidden')],
        ['formula-modal', () => document.getElementById('formula-modal').classList.add('hidden')]
    ];
    let active = null;
    dialogs.forEach(([id, close]) => {
        const dialog = document.getElementById(id);
        if (!dialog) return;
        dialog.setAttribute('role', 'dialog');dialog.setAttribute('aria-modal', 'true');
        const heading = dialog.querySelector('h2,h3');
        if (heading) {heading.id ||= `neo-dialog-title-${id}`;dialog.setAttribute('aria-labelledby', heading.id);}
        else dialog.setAttribute('aria-label', 'پنجرهٔ ویرایش');
        let opened = false, trigger = null;
        const sync = () => {
            const visible = getComputedStyle(dialog).display !== 'none';
            if (visible && !opened) {
                trigger = document.activeElement;active = {dialog, close};
                const control = dialog.querySelector('input:not([type=hidden]),select,textarea,button,a[href]');
                requestAnimationFrame(() => {if (active?.dialog === dialog) control?.focus({preventScroll:true});});
            } else if (!visible && opened) {
                if (active?.dialog === dialog) active = null;
                if (trigger?.isConnected) trigger.focus({preventScroll:true});
            }
            opened = visible;
        };
        new MutationObserver(sync).observe(dialog, {attributes:true,attributeFilter:['class','style']});sync();
    });
    document.addEventListener('keydown', event => {
        if (!active || event.defaultPrevented) return;
        // A picker nested above a form dialog owns its own keyboard interaction.
        if (!active.dialog.contains(document.activeElement)) return;
        if (event.key === 'Escape') {event.preventDefault();active.close();return;}
        if (event.key !== 'Tab') return;
        const controls = Array.from(active.dialog.querySelectorAll('button,input,select,textarea,a[href],[tabindex="0"]')).filter(el => !el.disabled && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last?.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first?.focus();}
    });
})();

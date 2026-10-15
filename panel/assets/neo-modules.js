(() => {
    'use strict';
    if (!document.body.classList.contains('neo-module')) return;
    document.querySelectorAll('label:not([for])').forEach((label, index) => {
        const field = label.querySelector('input,select,textarea') || label.nextElementSibling;
        if (!field?.matches('input,select,textarea')) return;
        field.id ||= `neo-module-field-${index}`;label.htmlFor = field.id;
    });
    const search = document.querySelector('#task-search,#q');
    if (search) search.setAttribute('aria-label', document.body.classList.contains('neo-tasks') ? 'جست‌وجوی وظایف' : 'جست‌وجوی پرامپت‌ها');
    if (document.body.classList.contains('neo-tasks')) {
        const filters = document.querySelector('aside');
        if (filters) {
            filters.id ||= 'neo-task-filters';
            const toggle = document.createElement('button');toggle.type = 'button';toggle.className = 'neo-task-filters-toggle';
            toggle.textContent = 'فیلترها و آمار وظایف';toggle.setAttribute('aria-controls', filters.id);filters.before(toggle);
            const media = matchMedia('(max-width:767px)');let expanded = false;
            const sync = () => {filters.hidden = media.matches && !expanded;toggle.setAttribute('aria-expanded', String(!filters.hidden));};
            toggle.addEventListener('click', () => {expanded = !expanded;sync();});media.addEventListener('change',sync);sync();
        }
    }
    const board = document.querySelector('.neo-task-board');
    if (board) {
        const states = [['not_started','شروع نشده'],['in_progress','در حال انجام'],['pending_review','انتظار تأیید'],['completed','انجام شده']];
        const nav = document.createElement('nav');nav.className = 'neo-board-nav';nav.setAttribute('aria-label','انتخاب وضعیت وظایف');board.before(nav);
        const media = matchMedia('(max-width:767px)');let selected = 'not_started';
        const controls = states.map(([key,label]) => {
            const column = document.getElementById(`col-${key}`)?.parentElement;
            if (!column) return null;
            column.id = `neo-task-column-${key}`;
            const button = document.createElement('button');button.type = 'button';button.setAttribute('aria-controls',column.id);nav.append(button);
            const badge = document.getElementById(`badge-${key}`);
            const updateLabel = () => {button.textContent = label + (badge ? ` (${badge.textContent.trim()})` : '');};
            if (badge) new MutationObserver(updateLabel).observe(badge,{childList:true,subtree:true,characterData:true});updateLabel();
            button.addEventListener('click', () => {selected = key;sync();});
            return {key,column,button};
        }).filter(Boolean);
        function sync() {controls.forEach(({key,column,button}) => {column.hidden = media.matches && key !== selected;button.setAttribute('aria-pressed',String(selected === key));});}
        media.addEventListener('change',sync);sync();
    }
    const promptList = document.querySelector('.neo-prompts #list');
    if (promptList) {
        const enhanceCards = () => promptList.querySelectorAll('.premium-card').forEach(card => {
            card.tabIndex = 0;card.setAttribute('role', 'button');
            card.setAttribute('aria-label', 'مشاهدهٔ پرامپت ' + (card.querySelector('h3,h4')?.textContent.trim() || ''));
        });
        new MutationObserver(enhanceCards).observe(promptList, {childList:true});enhanceCards();
        promptList.addEventListener('keydown', event => {
            if (event.target.matches('.premium-card') && ['Enter', ' '].includes(event.key)) {event.preventDefault();event.target.click();}
        });
    }
    let active = null;
    const dialogs = document.querySelectorAll('.neo-prompts .modal,#create-task-modal,#task-detail-modal');
    dialogs.forEach(dialog => {
        dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');
        const heading = dialog.querySelector('h2,h3');
        if (heading) {heading.id ||= `neo-title-${dialog.id}`;dialog.setAttribute('aria-labelledby',heading.id);}
        else dialog.setAttribute('aria-label','پنجرهٔ عملیات');
        let opened = false, trigger = null;
        const sync = () => {
            const visible = !dialog.classList.contains('hidden') && getComputedStyle(dialog).display !== 'none';
            if (visible && !opened) {
                trigger = document.activeElement;active = dialog;
                requestAnimationFrame(() => {if (active === dialog) dialog.querySelector('input:not([type=hidden]),select,textarea,button')?.focus({preventScroll:true});});
            } else if (!visible && opened) {
                if (active === dialog) active = null;
                if (trigger?.isConnected) trigger.focus({preventScroll:true});
            }
            opened = visible;
        };
        new MutationObserver(sync).observe(dialog,{attributes:true,attributeFilter:['class','style']});sync();
    });
    document.addEventListener('keydown', event => {
        if (!active || event.defaultPrevented || !active.contains(document.activeElement)) return;
        if (event.key === 'Escape') {
            const close = active.querySelector('button[data-close],button[onclick="closeCreateTaskModal()"],button[onclick="closeTaskDetailModal()"]');
            if (close) {event.preventDefault();close.click();}return;
        }
        if (event.key !== 'Tab') return;
        const controls = Array.from(active.querySelectorAll('button,input,select,textarea,a[href],[tabindex="0"]')).filter(el => !el.disabled && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden');
        const first = controls[0],last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last?.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first?.focus();}
    });
    const drawerButton = document.getElementById('btnFilters');
    const drawer = document.querySelector('.neo-prompts .sidebar');
    if (drawer && drawerButton) {
        drawer.id ||= 'neo-prompt-filters';drawerButton.setAttribute('aria-controls',drawer.id);
        const media = matchMedia('(max-width:1100px)');let wasOpen = false;
        const sync = () => {
            const open = media.matches && document.body.classList.contains('filters-open');
            drawerButton.setAttribute('aria-expanded',String(open));drawer.inert = media.matches && !open;
            if (open && !wasOpen) requestAnimationFrame(() => drawer.querySelector('input,select,button')?.focus({preventScroll:true}));
            if (!open && wasOpen && media.matches) drawerButton.focus({preventScroll:true});
            wasOpen = open;
        };
        new MutationObserver(sync).observe(document.body,{attributes:true,attributeFilter:['class']});media.addEventListener('change',sync);sync();
        document.addEventListener('keydown',event => {
            if (!wasOpen || event.key !== 'Tab' || active) return;
            const controls = Array.from(drawer.querySelectorAll('input,select,button,a[href]')).filter(el => !el.disabled && el.getClientRects().length);
            const first = controls[0],last = controls[controls.length-1];
            if (event.shiftKey && document.activeElement === first) {event.preventDefault();last?.focus();}
            else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first?.focus();}
        });
    }
})();

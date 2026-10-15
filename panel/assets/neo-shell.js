/* Enhance existing navigation without replacing its destinations or permission output. */
(() => {
    'use strict';
    const sidebar = document.querySelector('.admin-sidebar');
    const main = document.querySelector('.admin-content');
    const mobile = document.querySelector('.neo-mobile-toggle');
    if (!sidebar || !mobile) return;
    sidebar.id = sidebar.id || 'neo-sidebar';
    mobile.setAttribute('aria-controls', sidebar.id);
    mobile.setAttribute('aria-label', 'باز و بسته کردن منوی بخش‌ها');
    const desktop = document.createElement('button');
    desktop.className = 'neo-nav-toggle neo-desktop-toggle';
    desktop.type = 'button';
    desktop.textContent = '☰';
    desktop.setAttribute('aria-label', 'جمع کردن منوی کناری');
    desktop.setAttribute('aria-expanded', 'true');
    mobile.after(desktop);
    desktop.addEventListener('click', () => {
        const collapsed = document.body.classList.toggle('neo-sidebar-collapsed');
        desktop.setAttribute('aria-expanded', String(!collapsed));
    });
    document.querySelectorAll('.navbar a').forEach(link => {
        const label = link.textContent.trim().replace(/\s+/g, ' ');
        link.setAttribute('aria-label', label);
        link.title = label;
    });
    const iconPaths = {
        'sale.php':'M4 19V9m8 10V5m8 14V12',
        'seo.php':'m20 20-5-5M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0',
        'portal.php':'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12m13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
        'user_chat.php':'M20 16V4H4v14l4-2h12M8 8h8m-8 4h5',
        'task_manager.php':'M8 5H5v16h14V5h-3M8 3h8v4H8zM8 12h8m-8 4h6',
        'logout.php':'M9 4H4v16h5m5-12 4 4-4 4m-6-4h12'
    };
    document.querySelectorAll('.navbar a').forEach(link => {
        const key = new URL(link.href).pathname.split('/').pop();
        const slot = link.querySelector('span:first-child');
        if (!iconPaths[key] || !slot) return;
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.7');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');svg.classList.add('neo-icon');
        const path = document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d',iconPaths[key]);svg.append(path);slot.replaceChildren(svg);
    });
    const search = document.getElementById('sidebar-search-input');
    if (search) search.setAttribute('aria-label', 'جست‌وجو در بخش‌های پنل');
    const media = window.matchMedia('(max-width:1024px)');
    let wasOpen = false;
    const sync = () => {
        const open = sidebar.classList.contains('show') && media.matches;
        mobile.setAttribute('aria-expanded', String(open));
        sidebar.setAttribute('aria-label', 'منوی بخش‌های پنل');
        sidebar.toggleAttribute('inert', media.matches && !open);
        if (main) main.inert = open;
        if (open && !wasOpen) {
            const focusDrawer = () => { if (wasOpen && !sidebar.contains(document.activeElement)) (search || sidebar.querySelector('a,button')).focus(); };
            requestAnimationFrame(focusDrawer);
            // A resize can overlap the closing transition of the previous drawer.
            setTimeout(focusDrawer, 240);
        }
        if (!open && wasOpen && media.matches) mobile.focus();
        document.body.style.overflow = open ? 'hidden' : '';
        wasOpen = open;
    };
    new MutationObserver(sync).observe(sidebar, {attributes:true, attributeFilter:['class']});
    media.addEventListener('change', () => {
        if (!media.matches && sidebar.classList.contains('show')) window.toggleSidebar();
        sync();
    });
    document.addEventListener('keydown', event => {
        if (!wasOpen) return;
        if (event.key === 'Escape') {event.preventDefault();window.toggleSidebar();return;}
        if (event.key !== 'Tab') return;
        const controls = Array.from(sidebar.querySelectorAll('a[href],button,input,select,[tabindex="0"]')).filter(el => el.offsetParent !== null);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first.focus();}
    });
    document.querySelectorAll('.nav-category-group').forEach(group => {
        const heading = group.firstElementChild;
        if (!heading || heading.matches('a,input')) return;
        const toggle = document.createElement('button');
        toggle.type = 'button';toggle.className = 'neo-category-toggle';
        while (heading.firstChild) toggle.append(heading.firstChild);
        heading.append(toggle);toggle.setAttribute('aria-expanded', 'true');
        toggle.addEventListener('click', () => {
            const closed = group.classList.toggle('neo-category-closed');
            toggle.setAttribute('aria-expanded', String(!closed));
        });
    });
    if (search) search.addEventListener('input', () => {
        if (!search.value.trim()) return;
        document.querySelectorAll('.neo-category-closed').forEach(group => {
            group.classList.remove('neo-category-closed');
            group.querySelector('.neo-category-toggle').setAttribute('aria-expanded', 'true');
        });
    });
    document.querySelectorAll('.maarksho-pilot .admin-content table tbody tr').forEach(row => {
        const headings = Array.from(row.closest('table').querySelectorAll('thead th'));
        Array.from(row.cells).forEach((cell, index) => {
            if (cell.colSpan === 1 && headings[index]) cell.dataset.label = headings[index].textContent.trim();
        });
    });
    if (document.body.classList.contains('neo-workspace')) {
        // Retain table semantics and calendar geometry; scrolling is local to the table.
        main.querySelectorAll('table').forEach((table, index) => {
            if (table.closest('.overflow-x-auto,.neo-table-scroll')) return;
            const wrapper = document.createElement('div');
            wrapper.className = 'neo-table-scroll';
            wrapper.tabIndex = 0;
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', table.caption?.textContent || `جدول ${index + 1}؛ برای مشاهدهٔ ستون‌ها پیمایش کنید`);
            table.before(wrapper);wrapper.append(table);
        });
        if (document.body.classList.contains('tab-settings')) {
            const sections = Array.from(main.querySelectorAll('form[method="POST"] h2'));
            if (sections.length > 1) {
                const nav = document.createElement('nav');
                nav.className = 'neo-section-nav';
                nav.setAttribute('aria-label', 'رفتن به بخش تنظیمات');
                sections.forEach((heading, index) => {
                    heading.id ||= `neo-settings-section-${index}`;
                    const link = document.createElement('a');link.href = `#${heading.id}`;
                    link.textContent = heading.textContent.trim();nav.append(link);
                });
                main.prepend(nav);
            }
        }
        // Associate existing labels without changing field names or form submission.
        main.querySelectorAll('label:not([for])').forEach((label, index) => {
            const field = label.querySelector('input,select,textarea') || label.nextElementSibling;
            if (!field?.matches('input,select,textarea')) return;
            field.id ||= `neo-field-${index}`;label.htmlFor = field.id;
        });
    }
    sync();
})();

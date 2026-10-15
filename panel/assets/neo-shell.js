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
    const toolbar = document.querySelector('.navbar > .container > div:last-child');
    if (toolbar) {
        const more = document.createElement('details');more.className = 'neo-toolbar-more';
        const summary = document.createElement('summary');summary.textContent = 'سایر دسترسی‌ها';
        const items = document.createElement('div');items.className = 'neo-toolbar-menu';
        more.append(summary,items);
        toolbar.querySelectorAll('a').forEach(link => {
            const file = new URL(link.href).pathname.split('/').pop();
            if (!['task_manager.php','user_chat.php'].includes(file)) items.append(link);
            else link.classList.add('neo-toolbar-primary');
        });
        if (items.childElementCount) toolbar.append(more);
        document.addEventListener('click', event => {if (!more.contains(event.target)) more.open = false;});
        more.addEventListener('keydown', event => {if (event.key === 'Escape') {more.open=false;summary.focus();event.stopPropagation();}});
    }
    const close = document.createElement('button');close.type='button';close.className='neo-drawer-close';
    close.textContent='بستن منو ×';close.addEventListener('click',()=>window.toggleSidebar());sidebar.prepend(close);
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
    const nav = sidebar.querySelector('nav');
    const groups = Array.from(nav.querySelectorAll('.nav-category-group'));
    const findLink = tab => nav.querySelector(`a[href="?tab=${tab}"]`);
    const shortcuts = document.createElement('div');
    shortcuts.className = 'neo-daily-nav';
    shortcuts.setAttribute('aria-label', 'دسترسی‌های روزانه');
    const dailyHeading = document.createElement('p');
    dailyHeading.className = 'neo-nav-caption';dailyHeading.textContent = 'کارهای روزانه';
    shortcuts.append(dailyHeading);
    ['dashboard','products','calendar'].forEach(tab => {
        const link = findLink(tab);
        if (!link) return;
        if (tab === 'dashboard') link.children[1].textContent = 'نمای کلی پروژه';
        if (tab === 'products') link.children[1].textContent = 'محصولات و محتوا';
        shortcuts.append(link);
    });
    const support = nav.querySelector('a[href="/panel/support.php"]');
    if (support) {support.classList.add('neo-support-link');nav.append(support);}
    const searchBox = search?.closest('.px-2.mb-3') || search?.parentElement;
    const projectSelect = nav.querySelector('select[onchange*="switch_project"]');
    const projectContext = projectSelect?.parentElement || nav.querySelector('.neo-single-project');
    if (projectContext) nav.prepend(projectContext);
    if (projectSelect) {
        const label = projectSelect.parentElement.querySelector('label');
        projectSelect.id = 'neo-project-select';
        if (label) {label.htmlFor = projectSelect.id;label.textContent = 'پروژهٔ جاری';}
        projectSelect.parentElement.classList.add('neo-project-picker');
        nav.prepend(projectSelect.parentElement);
    }
    if (searchBox) {
        search.placeholder = 'جست‌وجوی ابزار…';
        search.removeAttribute('onkeyup');
        searchBox.classList.add('neo-nav-search');
        if (projectContext) projectContext.after(searchBox);
        else nav.prepend(searchBox);
        searchBox.after(shortcuts);
    }
    // Move the authorized nodes themselves: no duplicated routes or new permissions.
    // Global groups are only emitted for super admins; identify by their existing routes.
    const globalGroups = groups.filter(g => g.querySelector('a[href^="?tab=main_"],a[href="?tab=feature_flags"],a[href^="?tab=markshow_"],a[href="?tab=projects"],a[href="?tab=storage_monitor"],a[href="?tab=system_debug"]'));
    groups.forEach((group, index) => {
        const heading = group.firstElementChild;
        const links = Array.from(group.querySelectorAll(':scope > a.nav-link'));
        if (!links.length) {group.remove();return;}
        const isGlobal = globalGroups.includes(group);
        const label = isGlobal ? (group.querySelector('a[href*="tab=markshow_"]') ? 'سازمان مرکزی' : 'مدیریت کل سیستم') : heading.textContent.trim();
        // Route-derived labels stay correct even when optional groups are not emitted.
        let title = label;
        if (group.querySelector('a[href="?tab=settings"],a[href="?tab=users"]')) title = 'تنظیمات و تیم پروژه';
        else if (group.querySelector('a[href*="/vm"],a[href^="vm/"]')) title = 'چیدمان فروشگاه';
        else if (group.querySelector('a[href*="/seo/"],a[href*="/seo"]')) title = 'سئو و هوش داده';
        else if (group.querySelector('a[href^="seo.php"],a[href^="sale.php"]')) title = 'تحلیل فروش و سئو';
        else if (group.querySelector('a[href="?tab=extract"],a[href="?tab=excel"],a[href="?tab=website_export"],a[href^="wp-crm.php"]')) title = 'داده و اتصال فروشگاه';
        else if (group.querySelector('a[href="?tab=occasions"],a[href^="prompthub.php"],a[href^="size/"]')) title = 'تولید و انتشار محتوا';
        else if (group.querySelector('a[href^="chat.php"]')) title = 'ارتباط با مشتری';
        const toggle = document.createElement('button');
        toggle.type = 'button';toggle.className = 'neo-category-toggle';
        const text = document.createElement('span');text.textContent = title || heading.textContent.trim();
        const count = document.createElement('span');count.className = 'neo-nav-count';count.textContent = links.length.toLocaleString('fa-IR');
        toggle.append(text,count);heading.replaceChildren(toggle);
        const panel = document.createElement('div');panel.className = 'neo-category-links';panel.id = `neo-nav-group-${index}`;
        panel.append(...links);group.append(panel);
        toggle.setAttribute('aria-controls',panel.id);
        group.dataset.open = String(links.some(link => link.classList.contains('active')));
        const setOpen = open => {group.classList.toggle('neo-category-closed',!open);toggle.setAttribute('aria-expanded',String(open));panel.hidden = !open;};
        setOpen(group.dataset.open === 'true');
        toggle.addEventListener('click', () => {
            if (search?.value.trim()) return;
            const open = group.dataset.open !== 'true';
            group.dataset.open = String(open);setOpen(open);
        });
        group._setOpen = setOpen;
        if (isGlobal) {group.classList.add('neo-system-group');nav.append(group);}
    });
    if (support) nav.append(support);
    nav.querySelectorAll('.h-px').forEach(el => el.remove());
    nav.querySelectorAll('a.active').forEach(link => link.setAttribute('aria-current','page'));
    const searchStatus = document.createElement('p');searchStatus.className = 'neo-search-status';
    searchStatus.setAttribute('role','status');searchStatus.hidden = true;
    if (searchBox) searchBox.after(searchStatus);
    const normalize = value => value.toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\u200c\u064b-\u065f]/g,'').replace(/\s+/g,' ').trim();
    const filter = () => {
        const query = normalize(search?.value || '');let count = 0;
        nav.querySelectorAll('a.nav-link').forEach(link => {
            const matches = !query || normalize(link.textContent).includes(query) || normalize(link.closest('.nav-category-group')?.querySelector('button')?.textContent || '').includes(query);
            link.hidden = !matches;link.style.removeProperty('display');if (matches) count++;
        });
        groups.forEach(group => {
            if (!group.isConnected) return;
            const matches = Array.from(group.querySelectorAll('a.nav-link')).some(link => !link.hidden);
            group.hidden = !matches;
            group._setOpen?.(query ? matches : group.dataset.open === 'true');
        });
        shortcuts.hidden = !Array.from(shortcuts.querySelectorAll('a')).some(link => !link.hidden);
        searchStatus.hidden = !query;
        searchStatus.textContent = count ? `${count.toLocaleString('fa-IR')} ابزار پیدا شد` : 'ابزاری پیدا نشد؛ عبارت دیگری بنویسید.';
    };
    if (search) search.addEventListener('input',filter);
    window.filterSidebarNav = filter;
    filter();
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

(() => {
    'use strict';
    const sidebar=document.getElementById('portal-sidebar'),toggle=document.getElementById('portal-menu-toggle'),backdrop=document.getElementById('portal-nav-backdrop'),main=document.getElementById('portal-main-container');
    if(!sidebar||!toggle)return;
    const mobile=matchMedia('(max-width:1024px)');let lastFocus=null;
    const close=()=>{sidebar.classList.remove('is-open');toggle.setAttribute('aria-expanded','false');backdrop.hidden=true;main.inert=false;sidebar.inert=mobile.matches;document.body.style.overflow='';if(lastFocus&&mobile.matches)lastFocus.focus();lastFocus=null;};
    const open=()=>{lastFocus=document.activeElement;sidebar.inert=false;sidebar.classList.add('is-open');toggle.setAttribute('aria-expanded','true');backdrop.hidden=false;main.inert=true;document.body.style.overflow='hidden';sidebar.querySelector('#portal-nav-search').focus();};
    toggle.addEventListener('click',()=>sidebar.classList.contains('is-open')?close():open());document.getElementById('portal-menu-close').addEventListener('click',close);backdrop.addEventListener('click',close);mobile.addEventListener('change',close);sidebar.inert=mobile.matches;
    document.addEventListener('keydown',event=>{
        if(!sidebar.classList.contains('is-open'))return;
        if(event.key==='Escape'){event.preventDefault();close();return;}
        if(event.key!=='Tab')return;
        const controls=[...sidebar.querySelectorAll('a[href],button,input,select,summary')].filter(el=>el.getClientRects().length&&!el.closest('[hidden]')),first=controls[0],last=controls.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    });
    const normalize=text=>text.toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,'').trim();
    const search=document.getElementById('portal-nav-search'),status=document.getElementById('portal-nav-search-status');
    search.addEventListener('input',()=>{
        const q=normalize(search.value);let found=0;
        sidebar.querySelectorAll('nav a').forEach(link=>{link.hidden=!!q&&!normalize(link.textContent).includes(q);if(!link.hidden)found++;});
        sidebar.querySelectorAll('.portal-nav-group').forEach(group=>{group.hidden=![...group.querySelectorAll('a')].some(link=>!link.hidden);if(group.tagName==='DETAILS'){if(!q&&group.dataset.wasOpen!==undefined){group.open=group.dataset.wasOpen==='true';delete group.dataset.wasOpen;}else if(q){if(group.dataset.wasOpen===undefined)group.dataset.wasOpen=String(group.open);group.open=true;}}});
        status.hidden=!q;status.textContent=found?`${found.toLocaleString('fa-IR')} بخش پیدا شد`:'بخشی با این نام پیدا نشد.';
    });
    const account=document.querySelector('.portal-account');
    document.addEventListener('click',event=>{if(account&&!account.contains(event.target))account.open=false;});
    account?.addEventListener('keydown',event=>{if(event.key==='Escape'){account.open=false;account.querySelector('summary').focus();}});
    const form=document.getElementById('filter-form');
    if(form){
        const details=document.createElement('details');details.className='portal-secondary-filters';const summary=document.createElement('summary');summary.textContent='فیلترهای بیشتر: رنگ، قیمت، پرسنل و مرتب‌سازی';const content=document.createElement('div');details.append(summary,content);
        ['f_cat','f_price','f_stock_min','f_likes','f_creator','f_sort'].forEach(name=>{const field=form.elements.namedItem(name);if(!field)return;const block=field.closest('div');if(block)content.append(block);if(field.value&&field.value!=='0'&&name!=='f_stock_min')details.open=true;});
        if(content.childElementCount)form.append(details);
    }
    const bars=[...main.querySelectorAll('.portal-bulk-bar')];
    if(bars.length){const details=document.createElement('details');details.className='portal-bulk-actions';const summary=document.createElement('summary');summary.textContent='اقدامات گروهی محصولات';details.append(summary);bars[0].before(details);details.append(...bars);}
    // Preserve normal link behavior and all project/filter query parameters.
    window.switchTab=id=>{const url=new URL(location.href);url.searchParams.set('tab',id);url.searchParams.set('p','1');location.assign(url);};
})();

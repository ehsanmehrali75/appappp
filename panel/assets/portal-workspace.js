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

/* Delegated events survive the portal's AJAX tab and pagination replacement. */
(() => {
    'use strict';
    const boxes=()=>[...document.querySelectorAll('#tab-library input[name="selected_library_products[]"]')];
    const selected=()=>boxes().filter(box=>box.checked);
    const sync=()=>{
        const all=boxes(),checked=selected(),master=document.getElementById('portal-batch-select-all'),count=document.getElementById('portal-batch-count'),apply=document.getElementById('portal-batch-apply');
        if(master){master.checked=all.length>0&&checked.length===all.length;master.indeterminate=checked.length>0&&checked.length<all.length;}
        if(count)count.textContent=checked.length+' محصول انتخاب شده';
        if(apply)apply.disabled=checked.length===0;
        all.forEach(box=>box.closest('.portal-product-card')?.classList.toggle('is-batch-selected',box.checked));
    };
    document.addEventListener('portal-library-selection',sync);
    document.addEventListener('change',event=>{
        if(event.target.id==='portal-batch-select-all'){
            boxes().forEach(box=>box.checked=event.target.checked);
            window.updateSelectedLibraryCount?.();sync();
        } else if(event.target.matches('input[name="selected_library_products[]"]'))sync();
    });
    document.addEventListener('click',event=>{
        if(!event.target.closest('#portal-batch-clear'))return;
        boxes().forEach(box=>box.checked=false);window.updateSelectedLibraryCount?.();sync();
    });
    document.addEventListener('submit',async event=>{
        const form=event.target;if(form.id!=='portal-library-batch-form')return;
        event.preventDefault();const ids=selected().map(box=>box.value),result=document.getElementById('portal-batch-result'),button=document.getElementById('portal-batch-apply');
        if(!ids.length){result.textContent='ابتدا محصول انتخاب کنید.';return;}
        button.disabled=true;const data=new FormData(form);data.set('action','bulk_manage_products');ids.forEach(id=>data.append('product_ids[]',id));
        try {
            const response=await fetch(window.location.href,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':document.querySelector('meta[name="app-csrf"]')?.content||''},body:data});
            const body=await response.json();if(!response.ok||body.status!=='success')throw new Error(body.msg||'ثبت وضعیت ناموفق بود.');
            result.textContent=body.msg;
            if(body.updated){try{sessionStorage.setItem('portal-batch-feedback',JSON.stringify({project:form.closest('[data-project]').dataset.project,message:body.msg}));}catch(_){}location.reload();}
        } catch(error){result.textContent=error.message;result.focus();}
        finally{sync();}
    });
    sync();
    try {
        const saved=JSON.parse(sessionStorage.getItem('portal-batch-feedback')||'null'),bar=document.querySelector('.portal-library-batch');
        if(saved&&bar&&saved.project===bar.dataset.project){bar.querySelector('#portal-batch-result').textContent=saved.message;sessionStorage.removeItem('portal-batch-feedback');}
    } catch(_){}
})();

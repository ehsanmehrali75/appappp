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

/* Read-only image viewer, shared by product cards, approval queues and AJAX content. */
(() => {
    'use strict';
    if(window.portalImageGalleryInstalled)return;
    window.portalImageGalleryInstalled=true;
    const css=document.createElement('link');css.rel='stylesheet';css.href='/panel/assets/portal-gallery.css?v=1';document.head.append(css);
    const selector='[data-media-gallery] img,.portal-product-card img,#pd-carousel-wrapper img,#ref-photo-modal img,#couple-set-modal img';
    let dialog,items=[],index=0,opener,oldOverflow='';
    const safe=value=>{try{const u=new URL(value,location.href);return ['http:','https:'].includes(u.protocol)&&!/(?:placehold\.co|placeholder|via\.placeholder)/i.test(u.href)?u.href:null;}catch{return null;}};
    const close=()=>dialog.close();
    const move=step=>{index=(index+step+items.length)%items.length;render();};
    function zoom(){const viewport=dialog.querySelector('.pig-viewport');const active=viewport.classList.toggle('is-zoomed');dialog.querySelector('[data-zoom]').setAttribute('aria-pressed',String(active));viewport.scrollTo(0,0);}
    function render(){
        const viewport=dialog.querySelector('.pig-viewport');viewport.classList.remove('is-zoomed');viewport.scrollTo(0,0);
        dialog.querySelector('[data-zoom]').setAttribute('aria-pressed','false');
        const img=dialog.querySelector('.pig-image');img.src=items[index];img.alt=opener.alt||'تصویر محصول';
        dialog.querySelector('.pig-count').textContent=`تصویر ${(index+1).toLocaleString('fa-IR')} از ${items.length.toLocaleString('fa-IR')}`;
        dialog.querySelectorAll('[data-step]').forEach(b=>b.disabled=items.length<2);
        dialog.querySelectorAll('.pig-thumbs button').forEach((b,i)=>b.setAttribute('aria-current',String(i===index)));
    }
    function create(){
        dialog=document.createElement('dialog');dialog.className='portal-image-gallery';dialog.setAttribute('aria-labelledby','pig-title');dialog.dir='rtl';
        dialog.innerHTML='<div class="pig-shell"><header class="pig-header"><h2 class="pig-title" id="pig-title"></h2><div class="pig-actions"><button type="button" data-zoom aria-label="بزرگ‌نمایی تصویر" aria-pressed="false">بزرگ‌نمایی</button><button type="button" data-close aria-label="بستن گالری">✕</button></div></header><div class="pig-stage"><button type="button" class="pig-prev" data-step="-1" aria-label="تصویر قبلی">‹</button><div class="pig-viewport"><img class="pig-image" alt="" referrerpolicy="no-referrer"></div><button type="button" class="pig-next" data-step="1" aria-label="تصویر بعدی">›</button></div><footer class="pig-footer"><p class="pig-count" aria-live="polite"></p><div class="pig-thumbs" aria-label="انتخاب تصویر"></div></footer></div>';
        document.body.append(dialog);
        dialog.querySelector('[data-close]').addEventListener('click',close);dialog.querySelector('[data-zoom]').addEventListener('click',zoom);dialog.querySelector('.pig-image').addEventListener('click',zoom);
        dialog.querySelectorAll('[data-step]').forEach(b=>b.addEventListener('click',()=>move(Number(b.dataset.step))));
        dialog.addEventListener('click',e=>{if(e.target===dialog||e.target.classList.contains('pig-viewport'))close();});
        dialog.addEventListener('keydown',e=>{if(e.key==='ArrowRight'||e.key==='ArrowLeft'){e.preventDefault();move(e.key==='ArrowRight'?1:-1);}});
        dialog.addEventListener('close',()=>{document.body.style.overflow=oldOverflow;dialog.querySelector('.pig-image').removeAttribute('src');opener?.isConnected&&opener.focus({preventScroll:true});});
        let start=null;const stage=dialog.querySelector('.pig-viewport');stage.addEventListener('touchstart',e=>{start=e.touches.length===1?[e.touches[0].clientX,e.touches[0].clientY]:null;},{passive:true});
        stage.addEventListener('touchend',e=>{if(!start||stage.classList.contains('is-zoomed'))return;const dx=e.changedTouches[0].clientX-start[0],dy=e.changedTouches[0].clientY-start[1];if(Math.abs(dx)>60&&Math.abs(dx)>Math.abs(dy)*1.5)move(dx<0?1:-1);start=null;},{passive:true});
    }
    function open(img){
        const clicked=safe(img.currentSrc||img.src);if(!clicked)return false;
        if(!dialog)create();opener=img;
        const group=img.closest('[data-media-gallery],#pd-carousel-wrapper,#ref-photo-modal,#couple-set-modal,.portal-product-card');
        const urls=[...(group?.querySelectorAll('img')||[img])].map(i=>safe(i.currentSrc||i.src)).filter(Boolean);
        const card=img.closest('[data-product-id]'),product=card&&window.portalProducts?.[card.dataset.productId];
        if(product&&typeof window.productMediaUrls==='function')urls.push(...window.productMediaUrls(product).map(safe).filter(Boolean));
        items=[...new Set(urls)];if(!items.includes(clicked))items.unshift(clicked);index=items.indexOf(clicked);dialog.querySelector('.pig-title').textContent=product?.name||img.alt||'گالری تصاویر محصول';
        const thumbs=dialog.querySelector('.pig-thumbs');thumbs.replaceChildren();items.forEach((url,i)=>{const b=document.createElement('button');b.type='button';b.setAttribute('aria-label','نمایش تصویر '+(i+1));const thumb=document.createElement('img');thumb.src=url;thumb.alt='';thumb.loading='lazy';thumb.referrerPolicy='no-referrer';b.append(thumb);b.addEventListener('click',()=>{index=i;render();});thumbs.append(b);});
        render();oldOverflow=document.body.style.overflow;document.body.style.overflow='hidden';dialog.showModal();dialog.querySelector('[data-close]').focus();return true;
    }
    document.addEventListener('click',e=>{const img=e.target.closest(selector);if(img&&open(img)){e.preventDefault();e.stopImmediatePropagation();}},true);
    document.addEventListener('keydown',e=>{if(['Enter',' '].includes(e.key)&&e.target.matches(selector)&&open(e.target)){e.preventDefault();e.stopImmediatePropagation();}},true);
    const enhance=root=>{const images=[...(root.matches?.(selector)?[root]:[]),...root.querySelectorAll(selector)];images.forEach(img=>{if(!safe(img.src))return;img.tabIndex=0;img.setAttribute('role','button');img.setAttribute('aria-label',(img.alt||'تصویر محصول')+'؛ نمایش بزرگ');});};
    enhance(document);new MutationObserver(records=>{for(const r of records)for(const n of r.addedNodes)if(n.nodeType===1&&!n.closest('.portal-image-gallery'))enhance(n);}).observe(document.body,{childList:true,subtree:true});
})();

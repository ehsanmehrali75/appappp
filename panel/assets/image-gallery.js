/* Shared image viewer; review controls attach separately after authorization. */
(() => {
    'use strict';
    if(window.portalImageGalleryInstalled)return;
    window.portalImageGalleryInstalled=true;
    const css=document.createElement('link');css.rel='stylesheet';css.href='/panel/assets/portal-gallery.css?v=2';document.head.append(css);
    const selector='[data-media-gallery] img,.portal-product-card img,#pd-carousel-wrapper img,#m-carousel-wrapper img,#ref-photo-modal img,#couple-set-modal img';
    let dialog,items=[],index=0,opener,oldOverflow='',reviewProductId='';
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
        document.dispatchEvent(new CustomEvent('portal-gallery-render',{detail:{dialog,productId:reviewProductId,url:items[index]}}));
    }
    function create(){
        dialog=document.createElement('dialog');dialog.className='portal-image-gallery';dialog.setAttribute('aria-labelledby','pig-title');dialog.dir='rtl';
        dialog.innerHTML='<div class="pig-shell"><header class="pig-header"><h2 class="pig-title" id="pig-title"></h2><div class="pig-actions"><button type="button" data-zoom aria-label="بزرگ‌نمایی تصویر" aria-pressed="false">بزرگ‌نمایی</button><button type="button" data-close aria-label="بستن گالری">✕</button></div></header><div class="pig-stage"><button type="button" class="pig-prev" data-step="-1" aria-label="تصویر قبلی">‹</button><div class="pig-viewport"><img class="pig-image" alt="" referrerpolicy="no-referrer"></div><button type="button" class="pig-next" data-step="1" aria-label="تصویر بعدی">›</button></div><footer class="pig-footer"><p class="pig-count" aria-live="polite"></p><div class="pig-thumbs" aria-label="انتخاب تصویر"></div></footer></div>';
        document.body.append(dialog);
        dialog.querySelector('[data-close]').addEventListener('click',close);dialog.querySelector('[data-zoom]').addEventListener('click',zoom);dialog.querySelector('.pig-image').addEventListener('click',zoom);
        dialog.querySelectorAll('[data-step]').forEach(b=>b.addEventListener('click',()=>move(Number(b.dataset.step))));
        dialog.addEventListener('click',e=>{if(e.target===dialog||e.target.classList.contains('pig-viewport'))close();});
        dialog.addEventListener('keydown',e=>{if(!e.target.matches('input,textarea,select')&&(e.key==='ArrowRight'||e.key==='ArrowLeft')){e.preventDefault();move(e.key==='ArrowRight'?1:-1);}});
        dialog.addEventListener('close',()=>{document.body.style.overflow=oldOverflow;dialog.querySelector('.pig-image').removeAttribute('src');opener?.isConnected&&opener.focus({preventScroll:true});});
        let start=null;const stage=dialog.querySelector('.pig-viewport');stage.addEventListener('touchstart',e=>{start=e.touches.length===1?[e.touches[0].clientX,e.touches[0].clientY]:null;},{passive:true});
        stage.addEventListener('touchend',e=>{if(!start||stage.classList.contains('is-zoomed'))return;const dx=e.changedTouches[0].clientX-start[0],dy=e.changedTouches[0].clientY-start[1];if(Math.abs(dx)>60&&Math.abs(dx)>Math.abs(dy)*1.5)move(dx<0?1:-1);start=null;},{passive:true});
    }
    function open(img){
        const clicked=safe(img.currentSrc||img.src);if(!clicked)return false;
        if(!dialog)create();opener=img;
        const group=img.closest('[data-media-gallery],#pd-carousel-wrapper,#m-carousel-wrapper,#ref-photo-modal,#couple-set-modal,.portal-product-card');
        const urls=[...(group?.querySelectorAll('img')||[img])].map(i=>safe(i.currentSrc||i.src)).filter(Boolean);
        const card=img.closest('[data-gallery-product-id],[data-product-id]'),id=card?.dataset.galleryProductId||card?.dataset.productId,product=id&&window.portalProducts?.[id];
        reviewProductId=id||'';
        if(product&&typeof window.productMediaUrls==='function')urls.push(...window.productMediaUrls(product).map(safe).filter(Boolean));
        items=[...new Set(urls)];if(!items.includes(clicked))items.unshift(clicked);index=items.indexOf(clicked);dialog.querySelector('.pig-title').textContent=product?.name||card?.dataset.productName||img.alt||'گالری تصاویر محصول';
        const thumbs=dialog.querySelector('.pig-thumbs');thumbs.replaceChildren();items.forEach((url,i)=>{const b=document.createElement('button');b.type='button';b.setAttribute('aria-label','نمایش تصویر '+(i+1));const thumb=document.createElement('img');thumb.src=url;thumb.alt='';thumb.loading='lazy';thumb.referrerPolicy='no-referrer';b.append(thumb);b.addEventListener('click',()=>{index=i;render();});thumbs.append(b);});
        render();oldOverflow=document.body.style.overflow;document.body.style.overflow='hidden';dialog.showModal();dialog.querySelector('[data-close]').focus();return true;
    }
    document.addEventListener('click',e=>{const img=e.target.closest(selector);if(img&&open(img)){e.preventDefault();e.stopImmediatePropagation();}},true);
    document.addEventListener('keydown',e=>{if(['Enter',' '].includes(e.key)&&e.target.matches(selector)&&open(e.target)){e.preventDefault();e.stopImmediatePropagation();}},true);
    const enhance=root=>{const images=[...(root.matches?.(selector)?[root]:[]),...root.querySelectorAll(selector)];images.forEach(img=>{if(!safe(img.src))return;img.tabIndex=0;img.setAttribute('role','button');img.setAttribute('aria-label',(img.alt||'تصویر محصول')+'؛ نمایش بزرگ');});};
    enhance(document);new MutationObserver(records=>{for(const r of records)for(const n of r.addedNodes)if(n.nodeType===1&&!n.closest('.portal-image-gallery'))enhance(n);}).observe(document.body,{childList:true,subtree:true});
})();

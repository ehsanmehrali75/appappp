(() => {
    const entry=x=>typeof x==='string'?x:x?.url||x?.src||x?.image||'';
    const list=x=>{if(typeof x==='string'){try{x=JSON.parse(x);}catch{x=[];}}return Array.isArray(x)?x:[];};
    const valid=x=>typeof x==='string'&&x&&!/placehold|placeholder/i.test(x)&&!/^\s*(javascript|data|vbscript):/i.test(x);
    window.productMediaUrls=p=>[p.image,...list(p.gallery),...list(p.images_list),...list(p.images),p.social_image||p.socialImage,...list(p.reference_gallery)].map(entry).filter(valid).filter((x,i,a)=>a.indexOf(x)===i);
    window.updateApprovalControls=(id,host)=>{
        const source=[...document.querySelectorAll('[data-approval-product]')].find(el=>el.dataset.approvalProduct===String(id)&&!el.dataset.approvalModal);if(!source||!host)return;
        const phases={};source.querySelectorAll('[data-approval-stage]').forEach(el=>phases[el.dataset.approvalStage]=el.dataset.phase);
        host.querySelectorAll('button[onclick]').forEach(button=>{const match=button.getAttribute('onclick').match(/(?:approve_stage|reject_stage|['"]stage)([12])/);if(!match)return;if(button.dataset.flowDisabled==='1'){button.disabled=false;delete button.dataset.flowDisabled;}if(phases[match[1]]!=='pending'&&!button.disabled){button.disabled=true;button.dataset.flowDisabled='1';button.title='این مرحله هنوز باز نشده یا تعیین تکلیف شده است.';}});
        if(host.matches('#manualModal,#product-detail-modal')){host.querySelectorAll('[data-approval-modal]').forEach(el=>el.remove());const clone=source.cloneNode(true);clone.dataset.approvalModal='1';const image=host.querySelector('#m-carousel-wrapper,#pd-carousel-wrapper');if(image)image.parentElement.insertAdjacentElement('afterend',clone);}
    };
    document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('[data-approval-product]').forEach(el=>{const card=el.closest('.portal-product-card,.product-item');if(card)window.updateApprovalControls(el.dataset.approvalProduct,card);}));
    const init=()=>document.querySelectorAll('[data-media-gallery]').forEach(g=>{if(g.dataset.ready)return;g.dataset.ready='1';const track=g.querySelector('.product-media-track');const count=g.querySelector('[data-media-count]');const update=()=>{if(count)count.textContent=`${Math.round(track.scrollLeft/Math.max(1,track.clientWidth))+1} / ${track.children.length}`;};track.addEventListener('scroll',update,{passive:true});g.addEventListener('click',e=>{const b=e.target.closest('[data-media-step]');if(!b)return;e.stopPropagation();track.scrollBy({left:Number(b.dataset.mediaStep)*track.clientWidth,behavior:'smooth'});});update();});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
    const clocks=()=>document.querySelectorAll('[data-approval-deadline]').forEach(el=>{let seconds=Math.ceil(Number(el.dataset.approvalDeadline)-Date.now()/1000);if(seconds<=0){el.textContent='مهلت به پایان رسیده؛ منتظر بررسی سیستم';return;}const days=Math.floor(seconds/86400);seconds%=86400;el.textContent=`${days?days+' روز و ':''}${Math.floor(seconds/3600)} ساعت و ${Math.floor((seconds%3600)/60)} دقیقه برای تأیید یا رد`;});
    clocks();setInterval(clocks,30000);
})();

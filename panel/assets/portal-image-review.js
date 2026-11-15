/* Review the current URL only; server remains authority for stages, roles and revisions. */
(() => {
    let productId='',state=null,serial=0,busy=false,box,active;
    const absolute=url=>new URL(url,location.href).href;
    async function request(action,fields={}) {
        const body=new FormData();Object.entries({action,id:productId,...fields}).forEach(([k,v])=>body.append(k,v));
        const response=await fetch('/panel/image-review.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','X-CSRF-Token':document.querySelector('meta[name="app-csrf"]')?.content||''},body});
        const data=await response.json();if(!response.ok||data.status!=='success')throw new Error(data.msg||'ثبت بررسی ناموفق بود.');return data.review;
    }
    function text(tag,value){const el=document.createElement(tag);el.textContent=value;return el;}
    function paint(message='') {
        if(!box||!active)return;box.replaceChildren();
        if(message)box.append(text('p',message));
        if(!state)return;
        const labelsForThumbs={approved:'تأیید شده',rejected:'رد شده',pending:'در انتظار بررسی'};
        active.dialog.querySelectorAll('.pig-thumbs button').forEach(b=>{const match=state.rows.find(r=>absolute(r.url)===b.querySelector('img')?.src);if(match){b.dataset.reviewState=match.state;b.title=labelsForThumbs[match.state]+(match.reason?'؛ '+match.reason:'');}});
        const row=state.rows.find(row=>absolute(row.url)===active.url);
        if(!row){box.hidden=true;return;}box.hidden=false;
        const counts=state.rows.reduce((a,r)=>(a[r.state]=(a[r.state]||0)+1,a),{});
        box.append(text('p',`${counts.approved||0} تأیید · ${counts.rejected||0} رد · ${counts.pending||0} در انتظار`));
        const labels={approved:'این تصویر تأیید شده است',rejected:'این تصویر رد شده است؛ نسخهٔ اصلاح‌شده را جایگزین کنید',pending:'این تصویر در انتظار بررسی است'};
        box.append(text('strong',labels[row.state]||row.state));
        if(row.by)box.append(text('p','بررسی‌کننده: '+row.by));
        if(row.reason)box.append(text('p','توضیح بررسی: '+row.reason));
        if(!row.available.length)return;
        const reason=document.createElement('textarea');reason.maxLength=2000;reason.placeholder='توضیح بررسی (برای رد الزامی است)';reason.setAttribute('aria-label','توضیح بررسی تصویر');box.append(reason);
        const actions=document.createElement('div');actions.className='pig-review-actions';box.append(actions);
        for(const stage of row.available) {
            const suffix=stage===1?' استایل':stage===2?' انطباق':'';
            for(const [label,key,decision] of [['تأیید این عکس'+suffix,row.key,'approved'],['رد این عکس'+suffix,row.key,'rejected'],['تأیید همهٔ عکس‌های منتظر'+suffix,'all','approved']]) {
                const b=text('button',label);b.type='button';b.disabled=busy;b.dataset.decision=decision;
                b.addEventListener('click',async()=>{
                    if(busy)return;
                    if(decision==='rejected'&&!reason.value.trim()){reason.focus();reason.setCustomValidity('علت رد تصویر را بنویسید.');reason.reportValidity();reason.addEventListener('input',()=>reason.setCustomValidity(''),{once:true});return;}
                    if(key==='all'&&!confirm('تمام تصاویر قابل بررسی در این مرحله تأیید شوند؟ تصاویر ردشده تغییر نمی‌کنند.'))return;
                    const run=serial;busy=true;actions.querySelectorAll('button').forEach(b=>b.disabled=true);
                    try {
                        const next=await request('review_image',{image_key:key,stage,decision,reason:reason.value,revision:state.revision});
                        if(run!==serial)return;state=next;paint('بررسی ذخیره شد. وضعیت کارت‌ها پس از بستن گالری تازه می‌شود.');
                        active.dialog.dataset.reviewChanged='1';
                    } catch(e){if(run===serial){state=null;paint(e.message+' گالری را ببندید و دوباره باز کنید.');}}
                    finally {busy=false;if(run===serial)box.querySelectorAll('button').forEach(b=>b.disabled=false);}
                });actions.append(b);
            }
        }
    }
    document.addEventListener('portal-gallery-render',async event=>{
        const detail=event.detail;active=detail;
        box=detail.dialog.querySelector('.pig-review');
        if(!box){box=document.createElement('section');box.className='pig-review';box.setAttribute('aria-live','polite');detail.dialog.querySelector('.pig-footer').prepend(box);
            detail.dialog.addEventListener('close',()=>{serial++;productId='';state=null;busy=false;if(detail.dialog.dataset.reviewChanged==='1')location.reload();});}
        box.hidden=!detail.productId;if(!detail.productId)return;
        if(productId===detail.productId&&state){paint();return;}
        if(productId===detail.productId&&busy)return;
        productId=detail.productId;state=null;busy=true;const run=++serial;paint('در حال دریافت وضعیت بررسی…');
        try {const next=await request('image_review_state');if(run===serial){state=next;busy=false;paint();}}
        catch(e){if(run===serial)paint(e.message);}
        finally{if(run===serial)busy=false;}
    });
})();

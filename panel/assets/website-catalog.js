(() => {
    'use strict';
    const panel = document.getElementById('website-catalog-panel');
    if (!panel) return;
    const byId = name => document.getElementById('website-catalog-' + name);
    let rows = null, token = null, busy = false;
    const invalidate = () => {token = null;byId('apply').hidden = true;};
    function show(message) {byId('result').textContent = message;}
    window.websiteCatalogLoad = data => {
        invalidate();
        rows = null;
        const values = Array.isArray(data) ? data : data?.products;
        if (!Array.isArray(values) || !values.length || !values.every(row => row && row.url && row.product_code && !row._erp_contract && !row.ProCode)) throw new Error('این فایل خروجی سایت با کد کالا نیست.');
        rows = values;
        byId('complete').checked = false;
        show(`${rows.length} ردیف آمادهٔ پیش‌نمایش است. هیچ تغییری ثبت نشده است.`);
    };
    byId('file').addEventListener('change', async event => {
        invalidate();rows = null;
        const file = event.target.files[0];if (!file) return;
        try {if (file.size > 5 * 1024 * 1024) throw new Error('حداکثر اندازهٔ فایل پنج مگابایت است.');window.websiteCatalogLoad(JSON.parse(await file.text()));}
        catch(error) {show(error.message);}
    });
    ['price-unit','price','stock','complete'].forEach(name => byId(name).addEventListener('change', invalidate));
    async function request(action, values = {}) {
        const form = new URLSearchParams({ajax_action:action,csrf:websiteCatalogCsrf,...values});
        const response = await fetch('admin.php?tab=' + (document.body.classList.contains('tab-website_export') ? 'website_export' : 'extract'),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:form});
        let result;try {result = await response.json();} catch (_) {throw new Error('پاسخ سرور قابل خواندن نیست؛ نشست و اتصال را بررسی کنید.');}if (!response.ok || result.status === 'failed') throw new Error(result.msg || 'درخواست ناموفق بود.');return result;
    }
    async function perform(action) {
        if (busy) return;
        busy = true;byId('preview').disabled = true;byId('apply').disabled = true;
        ['file','price-unit','price','stock','complete'].forEach(name=>byId(name).disabled=true);
        try {
            if (action === 'preview') {
                invalidate();if (!rows) throw new Error('ابتدا فایل خروجی سایت را انتخاب کنید.');
                const result = await request('website_catalog_preview',{data:JSON.stringify(rows),complete:byId('complete').checked?'1':'0',update_price:byId('price').checked?'1':'0',update_stock:byId('stock').checked?'1':'0',price_unit:byId('price-unit').value});
                token = result.token;const c=result.counts;
                show(`پیش‌نمایش؛ هنوز ذخیره نشده\nتطبیق: ${c.matched} | نادیده‌گرفته‌شده: ${c.ignored} | تطبیق مبهم: ${c.conflicts}\nمحصول جدید: ۰ | غایب: ${c.missing} | باگ فنی: ${c.technical}\nقیمت: ${c.price} | موجودی صریح: ${c.stock} | عکس افزوده: ${c.images}\n`+(result.warnings.length?result.warnings.join('\n')+'\n':'')+result.changes.map(row=>`${row.name}: ${row.action}`).join('\n'));
                byId('apply').hidden = false;
            } else {
                if (!token) throw new Error('پیش‌نمایش جدید لازم است.');
                const result = await request('website_catalog_apply',{token});invalidate();show(result.msg);location.reload();
            }
        } catch(error) {invalidate();show(error.message);}
        finally {busy=false;byId('preview').disabled=false;byId('apply').disabled=false;['file','price-unit','price','stock','complete'].forEach(name=>byId(name).disabled=false);}
    }
    byId('preview').addEventListener('click',()=>perform('preview'));byId('apply').addEventListener('click',()=>perform('apply'));
    panel.querySelectorAll('.website-catalog-resolve').forEach(button => button.addEventListener('click',async()=>{
        button.disabled=true;
        try {const result=await request('website_catalog_resolve',{product_id:button.dataset.id});show(result.msg);location.reload();}
        catch(error){show(error.message);button.disabled=false;}
    }));
    function filter() {
        const query=byId('search').value.trim().toLocaleLowerCase();const queue=byId('queue').value;let count=0;
        panel.querySelectorAll('.website-catalog-row').forEach(row=>{
            const keys=row.dataset.queues.split(' ');const pending=keys.some(key=>key && key!=='resolved');
            const matches=(queue==='all'||(queue==='work'?pending:keys.includes(queue)))&&row.dataset.search.toLocaleLowerCase().includes(query);
            row.hidden=!matches;if(matches)count++;
        });
        byId('queue-count').textContent=`${count} محصول در این صف`;
    }
    byId('queue').addEventListener('change',filter);byId('search').addEventListener('input',filter);filter();
})();

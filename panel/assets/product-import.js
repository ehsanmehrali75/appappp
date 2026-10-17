(() => {
    'use strict';
    let running=false;
    const encoder=new TextEncoder();
    async function request(project, fields) {
        const response=await fetch('product_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project,...fields})});
        const result=await response.json();
        if(!response.ok||result.error)throw new Error(result.error||'ارتباط با سرور ناموفق بود.');
        return result;
    }
    window.importERPInBatches=async(project,items,mode) => {
        if(running)return;
        running=true;
        const key='erp-import:'+project;
        let job;
        try {
            // A single row is never split; keep requests well below typical shared-host POST limits.
            const chunks=[];let chunk=[],bytes=2;
            for(const item of items) {
                const size=encoder.encode(JSON.stringify(item)).length+1;
                if(size>450000)throw new Error('حجم یک ردیف بیش از حد مجاز است.');
                if(chunk.length&&(chunk.length>=100||bytes+size>450000)){chunks.push(chunk);chunk=[];bytes=2;}
                chunk.push(item);bytes+=size;
            }
            if(chunk.length)chunks.push(chunk);
            const signature=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',encoder.encode(JSON.stringify([mode,items])))),b=>b.toString(16).padStart(2,'0')).join('');
            const saved=JSON.parse(sessionStorage.getItem(key)||'null');
            if(saved?.signature===signature) {
                try {job=await request(project,{action:'status',job:saved.job});}catch {sessionStorage.removeItem(key);}
            }
            if(!job||job.state==='done')job=await request(project,{action:'begin',chunks:chunks.length,mode});
            sessionStorage.setItem(key,JSON.stringify({job:job.job,signature}));
            if(job.state==='uploading') {
                for(let index=job.uploaded;index<chunks.length;index++) {
                    showFullLoader(`ارسال فایل: ${index+1} از ${chunks.length} بخش`);
                    job=await request(project,{action:'upload',job:job.job,index,rows:chunks[index]});
                }
                showFullLoader('تجمیع کامل انبارها و آماده‌سازی تغییرات…');
                job=await request(project,{action:'prepare',job:job.job});
            }
            while(job.state!=='done') {
                showFullLoader(`ثبت محصولات: ${Math.min(job.cursor*50,job.total)} از ${job.total} — لطفاً صفحه را باز نگه دارید`);
                job=await request(project,{action:'apply',job:job.job});
            }
            sessionStorage.removeItem(key);hideFullLoader();
            alert('همگام‌سازی کامل شد. جدید: '+(job.summary?.new||0)+'، به‌روز شده: '+(job.summary?.updated||0));
            location.reload();
        } catch(error) {
            hideFullLoader();
            alert(error.message+'\nاگر ارتباط قطع شده، دوباره ثبت را بزنید؛ بخش‌های ثبت‌شده تکرار نمی‌شوند. پس از تازه‌سازی صفحه، همان فایل را انتخاب کنید.');
        } finally {running=false;}
    };
})();

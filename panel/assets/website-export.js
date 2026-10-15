(() => {
    const workspace=document.getElementById('website-export-workspace');
    if(!workspace)return;
    const catalog=document.getElementById('website-catalog-panel');
    const nav=document.createElement('nav');nav.className='we-main-nav';nav.setAttribute('aria-label','بخش‌های خروجی وب‌سایت');
    const anchor=catalog||workspace;anchor.before(nav);
    const panels={work:catalog,export:workspace},buttons={};
    Object.entries({work:'تطبیق محصولات و صف کار',export:'خروجی وب‌سایت (بهینه‌سازی تصاویر)'}).forEach(([key,label])=>{
        if(!panels[key])return;
        panels[key].classList.add('we-main-panel');const button=document.createElement('button');button.type='button';button.textContent=label;button.setAttribute('aria-controls',panels[key].id);button.addEventListener('click',()=>show(key));nav.append(button);buttons[key]=button;
    });
    function show(key){if(!panels[key])key='export';Object.entries(panels).forEach(([name,panel])=>{if(panel){panel.hidden=name!==key;buttons[name].setAttribute('aria-pressed',String(key===name));}});const url=new URL(location.href);url.searchParams.set('we_view',key);history.replaceState(null,'',url);}
    const actionBar=document.getElementById('btn-export-selected').parentElement;
    actionBar.classList.add('we-export-actions');
    const sizeTools=document.createElement('details');sizeTools.className='we-size-tools';
    const sizeSummary=document.createElement('summary');sizeSummary.textContent='ابزارهای جدول سایز';
    const sizeItems=document.createElement('div');sizeItems.className='we-size-tool-items';sizeTools.append(sizeSummary,sizeItems);
    ['btn-sizechart-incomplete-selected','btn-sizechart-complete-selected','btn-export-sizecharts-selected'].forEach(id=>{const button=document.getElementById(id);if(button)sizeItems.append(button);});
    actionBar.append(sizeTools);
    const subtabBar=document.getElementById('subtab-btn-tab1').parentElement;subtabBar.classList.add('we-export-subtabs');
    subtabBar.querySelectorAll('button').forEach(button=>{const spans=Array.from(button.children);button.replaceChildren(...spans);});
    const report=document.createElement('details');report.className='we-export-report';report.id='website-export-report';report.hidden=true;
    const reportTitle=document.createElement('summary');reportTitle.setAttribute('role','status');reportTitle.setAttribute('aria-live','polite');
    const reportText=document.createElement('pre');report.append(reportTitle,reportText);workspace.firstElementChild.after(report);
    window.websiteExportReport=(warnings,count)=>{report.hidden=false;reportTitle.textContent=`خروجی ${count.toLocaleString('fa-IR')} محصول آماده شد`+(warnings.length?` · ${warnings.length.toLocaleString('fa-IR')} مورد برای بررسی`:'');reportText.textContent=warnings.join('\n')||'فایل‌های آماده در ZIP قرار گرفتند. پس از بارگذاری در سایت، آپلود را تأیید کنید.';};
    const rows=Array.from(workspace.querySelectorAll('.product-export-row'));
    const toolbar=document.createElement('div');toolbar.className='we-pagination';
    const previous=document.createElement('button'),next=document.createElement('button'),all=document.createElement('button'),status=document.createElement('span');
    [previous,next,all].forEach(b=>{b.type='button';b.className='btn btn-outline';});previous.textContent='صفحهٔ قبل';next.textContent='صفحهٔ بعد';all.textContent='انتخاب همهٔ نتایج این صف';status.setAttribute('role','status');toolbar.append(all,previous,status,next);document.getElementById('export-products-list').after(toolbar);
    let page=1,filtered=[];const pageSize=20;
    const normalize=value=>value.toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,'');
    const clear=()=>{rows.forEach(r=>r.querySelector('.chk-export-item').checked=false);document.getElementById('chk-export-select-all').checked=false;};
    function render(){
        const query=normalize(document.getElementById('export-search-input').value.trim());const state=document.getElementById('export-filter-status').value;
        filtered=rows.filter(r=>{
            const matchTab=currentExportSubTab==='tab1'||r.dataset['isTab'+currentExportSubTab.slice(-1)]==='1';
            const matches=matchTab&&(state==='all'||r.dataset.siteStatus===state)&&normalize(r.dataset.name+' '+r.dataset.sku).includes(query);
            r.classList.toggle('hidden',!matches);r.dataset.underTab=matches?currentExportSubTab:'hidden';return matches;
        });
        const order=document.getElementById('export-sort-order').value;
        filtered.sort((a,b)=>order==='name_asc'?a.dataset.name.localeCompare(b.dataset.name,'fa'):order==='name_desc'?b.dataset.name.localeCompare(a.dataset.name,'fa'):order==='id_asc'?a.dataset.id.localeCompare(b.dataset.id):order==='id_desc'?b.dataset.id.localeCompare(a.dataset.id):Number(b.dataset.score)-Number(a.dataset.score));
        filtered.forEach(r=>document.getElementById('export-products-list').append(r));
        const pages=Math.max(1,Math.ceil(filtered.length/pageSize));page=Math.min(page,pages);
        rows.forEach(r=>r.hidden=true);filtered.slice((page-1)*pageSize,page*pageSize).forEach(r=>r.hidden=false);
        status.textContent=`${filtered.length.toLocaleString('fa-IR')} محصول · صفحهٔ ${page.toLocaleString('fa-IR')} از ${pages.toLocaleString('fa-IR')}`;
        previous.disabled=page===1;next.disabled=page===pages;all.disabled=!filtered.some(r=>!r.querySelector('input').disabled);
        updateSelectedExportCount();
    }
    window.switchExportSubTab=tab=>{
        if(!['tab1','tab2','tab3','tab4'].includes(tab))return;
        currentExportSubTab=tab;page=1;clear();
        document.getElementById('export-search-input').value='';document.getElementById('export-filter-status').value='all';
        ['tab1','tab2','tab3','tab4'].forEach(name=>document.getElementById('subtab-btn-'+name)?.setAttribute('aria-pressed',String(name===tab)));
        const url=new URL(location.href);url.searchParams.set('we_tab',tab);history.replaceState(null,'',url);render();
    };
    window.filterExportList=()=>{page=1;clear();render();};window.sortExportList=()=>{page=1;render();};
    window.toggleExportSelectAll=master=>{filtered.filter(r=>!r.hidden).forEach(r=>{const checkbox=r.querySelector('.chk-export-item');if(!checkbox.disabled)checkbox.checked=master.checked;});updateSelectedExportCount();};
    previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});all.addEventListener('click',()=>{filtered.forEach(r=>{const checkbox=r.querySelector('.chk-export-item');if(!checkbox.disabled)checkbox.checked=true;});updateSelectedExportCount();});
    document.getElementById('chk-export-select-all').setAttribute('aria-label','انتخاب محصولات همین صفحه');
    const initial=new URL(location.href).searchParams;show(initial.get('we_view')||(initial.has('wc_queue')?'work':'export'));window.switchExportSubTab(initial.get('we_tab')||'tab2');
})();

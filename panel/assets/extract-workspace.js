/* Keep the existing forms and workers; show one task workspace at a time. */
(() => {
    const root=document.getElementById('extract-tools-workspace');
    const catalog=document.getElementById('website-catalog-panel');
    if(!root)return;
    const children=Array.from(root.children).filter(el=>el.tagName!=='SCRIPT');
    const header=children[0], platform=children[1];
    const crawler=document.getElementById('extract-live-crawler');
    const central=document.getElementById('extract-central-erp');
    const legacy=document.getElementById('extract-legacy-tools');
    const debug=document.getElementById('image-debug-section');
    if(!crawler||!central||!legacy||!debug)return;
    const nav=document.createElement('nav');nav.className='extract-workspace-nav';nav.setAttribute('aria-label','بخش‌های استخراج و تطبیق');
    const intro=document.createElement('header');intro.className='extract-workspace-intro';
    const title=document.createElement('h1');title.textContent='استخراج و تکمیل محتوا';
    const subtitle=document.createElement('p');subtitle.textContent='ابتدا موجودی مرکزی، سپس تولید و تأیید محتوا و در پایان تطبیق انتشار در سایت.';intro.append(title,subtitle);
    (catalog||root).before(intro,nav);
    const panels=catalog?{work:catalog}:{};
    const labels={work:'تطبیق و صف کار',central:'موجودی مرکزی',site:'دریافت از سایت',advanced:'ابزارهای پیشرفته'};
    ['central','site','advanced'].forEach(key=>{const panel=document.createElement('section');panel.id=`extract-view-${key}`;panel.className='extract-workspace-panel';panel.setAttribute('aria-label',labels[key]);root.append(panel);panels[key]=panel;});
    panels.central.append(platform,central);
    panels.site.append(crawler);
    function disclose(node,label,parent){const details=document.createElement('details');details.className='extract-tool-disclosure';const summary=document.createElement('summary');summary.textContent=label;details.append(summary,node);parent.append(details);}
    disclose(debug,'بررسی و استخراج تصاویر از لینک محصولات',panels.advanced);
    disclose(legacy,'صف پردازش، ورود فایل قدیمی و گزارش‌های فنی',panels.advanced);
    children.filter(el=>el!==header&&el!==platform&&el!==crawler&&el!==central&&el!==legacy&&el!==debug&&el.id!=='image-debug-test-modal').forEach(el=>disclose(el,el.querySelector('h4,h3')?.textContent.trim()||'تنظیمات تکمیلی',panels.advanced));
    header.hidden=true;
    const buttons={};
    Object.entries(labels).forEach(([key,label])=>{if(!panels[key])return;const button=document.createElement('button');button.type='button';button.textContent=label;button.setAttribute('aria-controls',panels[key].id);button.addEventListener('click',()=>show(key,true));nav.append(button);buttons[key]=button;});
    function show(key,update=false){if(!panels[key])key=catalog?'work':'central';Object.entries(panels).forEach(([name,panel])=>{panel.hidden=name!==key;buttons[name].setAttribute('aria-pressed',String(name===key));});if(update){const url=new URL(location.href);url.searchParams.set('ex_view',key);url.hash='';history.replaceState(null,'',url);}}
    window.extractWorkspaceShow=key=>show(key,true);
    const initial=new URL(location.href).searchParams.get('ex_view')||'work';show(initial);
})();

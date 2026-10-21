(() => {
    const form=document.getElementById('project-settings-form');if(!form)return;
    const nav=document.createElement('nav');nav.className='settings-nav';nav.setAttribute('aria-label','بخش‌های تنظیمات پروژه');
    const intro=document.createElement('header');intro.className='settings-intro';
    const title=document.createElement('h1');title.textContent='تنظیمات پروژه';
    const subtitle=document.createElement('p');subtitle.textContent='بخش موردنظر را انتخاب کنید؛ تغییرات همهٔ بخش‌ها با دکمهٔ ذخیره ثبت می‌شوند.';
    const search=document.createElement('input');search.type='search';search.placeholder='جست‌وجو در تنظیمات…';search.setAttribute('aria-label','جست‌وجو در تنظیمات');intro.append(title,subtitle,search);form.before(intro,nav);
    const feedback=document.getElementById('settings-feedback');if(feedback)form.after(feedback);
    const sections=[];const headings=[...form.querySelectorAll('h2')];
    const labels=['مشخصات پروژه','ماژول‌ها و دسترسی','زمان‌بندی و مهلت‌ها','پیامک و امنیت','شبکه‌ها و اتصال‌ها','سرعت و حافظه','تأیید و تولید تصویر','پشتیبانی و گفتگو'];
    for(const [index,heading] of headings.entries()){
        const card=heading.closest('.card,.premium-card');if(!card||sections.some(s=>s.card===card))continue;
        const button=document.createElement('button');button.type='button';button.textContent=labels[index]||heading.textContent.trim();button.id='settings-tab-'+index;button.setAttribute('role','tab');
        card.id='settings-section-'+index;card.setAttribute('role','tabpanel');card.setAttribute('aria-labelledby',button.id);button.setAttribute('aria-controls',card.id);nav.append(button);sections.push({button,card,heading});
        button.onclick=()=>select(index,true);
    }
    const saveButton=form.querySelector('button[name="save_settings"]');
    const saveBar=saveButton?.parentElement;if(saveBar)form.append(saveBar);
    for(const section of sections)form.insertBefore(section.card,saveBar||null);
    const extraCandidates=[...form.querySelectorAll('.card,.premium-card,section')].filter(el=>{
        if(el.closest('[role=tabpanel]')||el.contains(saveButton))return false;
        const ancestor=el.parentElement.closest('.card,.premium-card,section');return !ancestor||!form.contains(ancestor)||ancestor.contains(saveButton);
    });
    const groups=new Map();
    for(const el of extraCandidates){if(el.textContent.includes('Desktop Scheduler Helper')||el.querySelector('[id^=g_auth_type_select_]')){sections[4].card.append(el);continue;}const owner=el.textContent.includes('حساب مدیر کل سامانه');const key=owner?'مدیر کل سامانه':'موجودی و تیام';let group=groups.get(key);if(!group){group=document.createElement('section');group.className='settings-supplement';groups.set(key,group);form.prepend(group);}if(el.querySelector('[name="tiam_vpn_server_ip"],[name="tiam_erp_server_ip"]')){const detail=document.createElement('details'),summary=document.createElement('summary');summary.textContent='تنظیمات پیشرفتهٔ اتصال قدیمی تیام';detail.append(summary,el);group.append(detail);}else group.append(el);}
    if(feedback)groups.set('بازخوردهای آماده',feedback);
    for(const [label,card] of groups){const index=sections.length,button=document.createElement('button');button.type='button';button.textContent=label;button.id='settings-tab-'+index;button.setAttribute('role','tab');card.id=card.id||'settings-section-'+index;card.setAttribute('role','tabpanel');card.setAttribute('aria-labelledby',button.id);button.setAttribute('aria-controls',card.id);nav.append(button);sections.push({button,card});button.onclick=()=>select(index,true);}
    nav.setAttribute('role','tablist');
    function select(index,update=false){sections.forEach((s,i)=>{s.card.hidden=i!==index;s.button.setAttribute('aria-selected',String(i===index));s.button.tabIndex=i===index?0:-1;});if(update){const u=new URL(location.href);u.searchParams.set('settings_section',String(index));history.replaceState(null,'',u);}search.value='';}
    nav.addEventListener('keydown',e=>{if(!['ArrowRight','ArrowLeft','Home','End'].includes(e.key))return;e.preventDefault();let i=sections.findIndex(s=>s.button===document.activeElement);i=e.key==='Home'?0:e.key==='End'?sections.length-1:(i+(e.key==='ArrowLeft'?1:-1)+sections.length)%sections.length;select(i,true);sections[i].button.focus();});
    search.oninput=()=>{const term=search.value.trim().toLocaleLowerCase();if(!term){select(Math.max(0,sections.findIndex(s=>s.button.getAttribute('aria-selected')==='true')));return;}sections.forEach(s=>{const match=!term||s.card.textContent.toLocaleLowerCase().includes(term)||[...s.card.querySelectorAll('input,select')].some(x=>x.name.toLowerCase().includes(term));s.card.hidden=!match;s.button.classList.toggle('settings-match',!!term&&match);});};
    form.addEventListener('invalid',e=>{const i=sections.findIndex(s=>s.card.contains(e.target));if(i>=0)select(i);},true);
    const selected=Number(new URL(location.href).searchParams.get('settings_section'));select(Number.isInteger(selected)&&selected>=0&&selected<sections.length?selected:0);
    const save=form.querySelector('button[name="save_settings"]');if(save){const bar=save.parentElement;bar.classList.add('settings-save-bar');const status=document.createElement('span');status.className='settings-dirty';status.textContent='تنظیمات ذخیره‌شده';status.setAttribute('role','status');bar.prepend(status);form.addEventListener('input',()=>status.textContent='تغییرات ذخیره نشده');form.addEventListener('change',()=>status.textContent='تغییرات ذخیره نشده');}
})();

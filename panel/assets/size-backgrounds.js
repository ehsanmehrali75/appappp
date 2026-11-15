/* Resolution: explicit preset override, assigned group, project default. */
function sizePresetGroupLabel(group) {
    return ({men:'مردانه',women:'زنانه',accessories:'اکسسوری',shoes:'کفش'})[group] || 'عمومی';
}
function sizeBackgroundForPreset(id,groupOverride) {
    const preset=(app_presets||[]).find(p=>String(p.id)===String(id||''));
    const group=groupOverride || preset?.preset_group || 'general';
    return (scs_settings.preset_backgrounds||{})[String(id||'')] || (scs_settings.group_backgrounds||{})[group] || scs_settings.bg_image || '';
}
function refreshPresetBackgroundOptions() {
    const select=document.getElementById('background-preset-select');if(!select)return;
    const chosen=Array.from(select.selectedOptions).map(o=>o.value);select.replaceChildren();
    for(const preset of app_presets||[]) {
        const option=document.createElement('option');option.value=String(preset.id);
        option.textContent=sizePresetGroupLabel(preset.preset_group)+' — '+(preset.category||'')+' — '+preset.name+((scs_settings.preset_backgrounds||{})[preset.id]?' (اختصاصی)':(scs_settings.group_backgrounds||{})[preset.preset_group]?' (بک‌گراند گروه)':' (پیش‌فرض پروژه)');
        option.selected=chosen.includes(option.value);select.append(option);
    }
    document.getElementById('preset-background-summary').textContent=Object.keys(scs_settings.preset_backgrounds||{}).length+' پریست دارای بک‌گراند اختصاصی است.';
}
function previewPresetBackgroundSelection() {
    const ids=Array.from(document.getElementById('background-preset-select').selectedOptions).map(o=>o.value);
    const values=[...new Set(ids.map(id=>(scs_settings.preset_backgrounds||{})[id]||''))];
    document.getElementById('preset-background-url').value=values.length===1?values[0]:'';
    applyBrandingStyleToTarget('#branding-live-preview',true,ids[0]||'');
}
function assignPresetBackground(clear) {
    const ids=Array.from(document.getElementById('background-preset-select').selectedOptions).map(o=>o.value);
    const url=document.getElementById('preset-background-url').value.trim();
    if(!ids.length||(!clear&&!url)){showToast('پریست‌ها و تصویر اختصاصی را مشخص کنید.','warning');return;}
    if(!clear&&!/^(https?:\/\/|(?:\.\.\/|\/panel\/|\/)?uploads\/)/i.test(url)){showToast('آدرس معتبر تصویر را وارد کنید.','error');return;}
    scs_settings.preset_backgrounds={...(scs_settings.preset_backgrounds||{})};
    for(const id of ids)if(clear)delete scs_settings.preset_backgrounds[id];else scs_settings.preset_backgrounds[id]=url;
    refreshPresetBackgroundOptions();applyBrandingChanges();showToast('اعمال شد؛ برای نگهداری تغییرات، تنظیمات را ذخیره کنید.','success');
}
function previewGroupBackground() {
    const group=document.getElementById('background-group-select').value;
    document.getElementById('group-background-url').value=(scs_settings.group_backgrounds||{})[group]||'';
    applyBrandingStyleToTarget('#branding-live-preview',true,'',group);
}
function assignGroupBackground(clear) {
    const group=document.getElementById('background-group-select').value;
    const url=document.getElementById('group-background-url').value.trim();
    if(!clear&&!/^(https?:\/\/|(?:\.\.\/|\/panel\/|\/)?uploads\/)/i.test(url)){showToast('تصویر یا آدرس معتبر بک‌گراند را تعیین کنید.','warning');return;}
    scs_settings.group_backgrounds={...(scs_settings.group_backgrounds||{})};
    if(clear)delete scs_settings.group_backgrounds[group];else scs_settings.group_backgrounds[group]=url;
    document.getElementById('group-background-summary').textContent=['men','women','accessories','shoes'].map(key=>sizePresetGroupLabel(key)+((scs_settings.group_backgrounds||{})[key]?': تنظیم شده':': پیش‌فرض پروژه')).join(' · ');
    refreshPresetBackgroundOptions();applyBrandingChanges();previewGroupBackground();
    showToast('بک‌گراند گروه اعمال شد؛ تنظیمات را ذخیره کنید.','success');
}
document.addEventListener('DOMContentLoaded',()=>{
    refreshPresetBackgroundOptions();
    const groupSelect=document.getElementById('background-group-select');
    if(groupSelect)document.getElementById('group-background-url').value=(scs_settings.group_backgrounds||{})[groupSelect.value]||'';
    document.getElementById('chart-preset-select')?.addEventListener('change',()=>applyBrandingStyleToTarget('#td-preview',false));
    // Presets can also be created or imported without reloading Studio.
    if(window.jQuery)$(document).ajaxComplete((e,xhr,options)=>{if(String(options.data||'').includes('preset'))refreshPresetBackgroundOptions();});
});

with open('panel/size/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

target1 = "if (!chart.image_path) return '';"
replacement1 = """var imgUrl = chart.image_path || chart.illus_url || '';
                    if (!imgUrl && Array.isArray(app_presets) && chart.preset_id) {
                        var pr = app_presets.find(p => String(p.id) === String(chart.preset_id));
                        if (pr && pr.illus_url) imgUrl = pr.illus_url;
                    }
                    var mediaHtml = imgUrl ?
                        `<img src="${resolveImageUrl(imgUrl)}" class="max-w-full rounded-lg" style="max-height: ${maxH}px; object-fit: ${objFit};" alt="Illustration" crossorigin="anonymous">` :
                        `<div class="w-full flex items-center justify-center mb-2"><span class="text-4xl text-slate-400">📏</span></div>`;"""

target1_full = """                illus: function() {
                    if (!chart.image_path) return '';
                    var maxH = parseInt((typeof scs_settings !== 'undefined' && scs_settings.illus_max_height) || 160, 10);
                    var objFit = (typeof scs_settings !== 'undefined' && scs_settings.illus_object_fit) || 'contain';
                    return `
                        <div id="preview-illus-card" data-scs-block="illus" class="scs-card relative group w-full bg-slate-50 rounded-2xl border border-slate-200 p-5 flex flex-col items-center justify-center text-center shadow-sm transition-all">
                            <div id="preview-illus-box" class="w-full flex items-center justify-center overflow-hidden mb-3 opacity-80" style="max-height: ${maxH}px;">
                                <img src="${resolveImageUrl(chart.image_path)}" class="max-w-full rounded-lg" style="max-height: ${maxH}px; object-fit: ${objFit};" alt="Illustration" crossorigin="anonymous">
                            </div>
                            <div class="text-[9px] text-slate-400 leading-relaxed font-black">
                                ابعاد اندازه‌گیری شده محصول مربوط به اندازه تکه‌ای کالا در حالتِ آزاد و غیرکشیده روی سطح است.
                            </div>
                        </div>
                    `;
                },"""

# Let's search by finding "illus: function() {" after "function buildOffscreenCanvasForChart"
pos = content.find("function buildOffscreenCanvasForChart")
if pos != -1:
    illus_pos = content.find("illus: function()", pos)
    end_illus = content.find("notes: function()", illus_pos)
    old_illus_chunk = content[illus_pos:end_illus]
    
    new_illus_chunk = """illus: function() {
                    var maxH = parseInt((typeof scs_settings !== 'undefined' && scs_settings.illus_max_height) || 160, 10);
                    var objFit = (typeof scs_settings !== 'undefined' && scs_settings.illus_object_fit) || 'contain';
                    var imgUrl = chart.image_path || chart.illus_url || '';
                    if (!imgUrl && Array.isArray(app_presets) && chart.preset_id) {
                        var pr = app_presets.find(p => String(p.id) === String(chart.preset_id));
                        if (pr && pr.illus_url) imgUrl = pr.illus_url;
                    }
                    var mediaHtml = imgUrl ?
                        `<img src="${resolveImageUrl(imgUrl)}" class="max-w-full rounded-lg" style="max-height: ${maxH}px; object-fit: ${objFit};" alt="Illustration" crossorigin="anonymous">` :
                        `<div class="w-full flex items-center justify-center mb-2"><span class="text-4xl text-slate-400">📏</span></div>`;
                    return `
                        <div id="preview-illus-card" data-scs-block="illus" class="scs-card relative group w-full bg-slate-50 rounded-2xl border border-slate-200 p-5 flex flex-col items-center justify-center text-center shadow-sm transition-all" style="box-sizing: border-box;">
                            <div id="preview-illus-box" class="w-full flex items-center justify-center overflow-hidden mb-3 opacity-90" style="max-height: ${maxH}px;">
                                ${mediaHtml}
                            </div>
                            <div class="text-[9px] text-slate-400 leading-relaxed font-black">
                                ابعاد اندازه‌گیری شده محصول مربوط به اندازه تکه‌ای کالا در حالتِ آزاد و غیرکشیده روی سطح است.
                            </div>
                        </div>
                    `;
                },
                """
    content = content[:illus_pos] + new_illus_chunk + content[end_illus:]

# Also fix the hasIllus check
old_check = "var hasIllus = order.includes('illus') && (typeof getBlockVisibility !== 'function' || getBlockVisibility('illus')) && !!chart.image_path;"
new_check = "var hasIllus = order.includes('illus') && (typeof getBlockVisibility !== 'function' || getBlockVisibility('illus'));"
if old_check in content:
    content = content.replace(old_check, new_check)
    print("Replaced hasIllus check successfully")
else:
    print("Warning: old_check not found")

with open('panel/size/index.php', 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated panel/size/index.php successfully")

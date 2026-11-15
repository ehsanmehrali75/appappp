"""Optional Chromium regression for Studio controls and persisted branding.

Requires Playwright and PHP/SQLite. CHROMIUM_PATH can select a browser;
otherwise system Chromium or Playwright's installed browser is used.
All requests and records use the disposable HTTP fixture.
"""
import json
import mimetypes
import os
from pathlib import Path
import shutil
import sys
import tempfile
from urllib.parse import unquote, urlparse

from playwright.sync_api import sync_playwright

sys.path.insert(0, str(Path(__file__).resolve().parent))
import test_http_security as http_fixture


http_fixture.HttpSecurity.setUpClass()
fixture = http_fixture.HttpSecurity('runTest')
fixture.setUp()
checks = []
errors = []


def check(condition, message):
    assert condition, message
    checks.append(message)


def route(request):
    if '/cron.php' in request.request.url:
        return request.fulfill(status=200, body='Fixture worker disabled')
    if request.request.url.startswith(fixture.base):
        asset = (fixture.app / unquote(urlparse(request.request.url).path).lstrip('/')).resolve()
        if asset.is_file() and (asset.is_relative_to(fixture.app / 'panel/assets') or asset.is_relative_to(fixture.app / 'assets')):
            return request.fulfill(status=200, path=str(asset), content_type=mimetypes.guess_type(asset)[0] or 'application/octet-stream')
        return request.continue_()
    image = request.request.resource_type == 'image'
    return request.fulfill(status=200, content_type='image/svg+xml' if image else 'text/plain', body='<svg xmlns="http://www.w3.org/2000/svg"/>' if image else '')


try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=os.environ.get('CHROMIUM_PATH') or shutil.which('chromium'), headless=True, args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000})
        context.route('**/*', route)
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(fixture.base + '/')
        page.evaluate('openLoginModal();switchLoginTab("admin")')
        page.fill('[name=user]', 'fixture_owner')
        page.fill('[name=pass]', 'Fixture-password-123!')
        page.click('button[name=login]')
        page.wait_for_load_state()
        page.goto(fixture.base+'/panel/workspace.php',wait_until='domcontentloaded');page.locator('button[value=p]').click();page.wait_for_load_state('domcontentloaded')
        page.goto(fixture.base + '/panel/size/?project=p')
        page.wait_for_function('typeof scs_settings !== "undefined" && typeof $ === "function"')
        page.locator('#chart-preset-select option[value="pants_simple"]').wait_for(state='attached')
        # Edit the real preset, then use the copy draft and save under a new identity/group.
        page.evaluate("loadCategoryPreset('pants_simple');$('#p-group').val('men')")
        with page.expect_response(lambda r:r.request.method=='POST' and 'action=td_scs_preset_save' in (r.request.post_data or '')) as saved:
            page.evaluate('saveCategoryPreset()')
        check(saved.value.json()['success'],'male group saves through the real preset endpoint')
        page.wait_for_function("app_presets.some(p=>p.id==='pants_simple'&&p.preset_group==='men')")
        page.evaluate("loadCategoryPreset('pants_simple');duplicateCategoryPreset();$('#p-group').val('women')")
        check(page.locator('#p-id').input_value()=='','copy is a draft with a new identity')
        with page.expect_response(lambda r:r.request.method=='POST' and 'action=td_scs_preset_save' in (r.request.post_data or '')) as copied:
            page.evaluate('saveCategoryPreset()')
        female_id=copied.value.json()['data']['id'];check(female_id!='pants_simple','copy gets a distinct stored id')
        page.wait_for_function("id=>app_presets.some(p=>p.id===id&&p.preset_group==='women')",arg=female_id)
        page.evaluate("window.femalePresetId="+json.dumps(female_id))
        check(page.evaluate("app_presets.find(p=>p.id==='pants_simple').preset_group")=='men','copy does not modify the original group')
        check(page.evaluate("JSON.stringify(app_presets.find(p=>p.id==='pants_simple').columns)===JSON.stringify(app_presets.find(p=>p.id===femalePresetId).columns)"),'copy preserves all sizing columns')
        page.evaluate("""() => {
            for(const group of ['men','women','accessories','shoes']){
                $('#background-group-select').val(group);$('#group-background-url').val('uploads/group-'+group+'.png');assignGroupBackground(false);
            }
            $('#chart-preset-select').val(femalePresetId);applyBrandingStyleToTarget('#td-preview',false);
        }""")
        check('group-women.png' in page.locator('#td-preview').evaluate('el=>el.style.backgroundImage'),'female preset uses the female group background')
        check(page.evaluate("sizeBackgroundForPreset('pants_simple')")=='uploads/group-men.png','male preset uses the male group background')
        page.evaluate("buildOffscreenCanvasForChart({preset_id:femalePresetId,title:'Fixture',columns:['Size'],rows:[['M']]})")
        check('group-women.png' in page.locator('#scs-batch-canvas').evaluate('el=>el.style.backgroundImage'),'batch output resolves the copied preset group')
        check('زنانه' in page.locator('#chart-preset-select option[value="'+female_id+'"]').inner_text(),'selection labels distinguish same-category presets by group')
        page.evaluate("""() => {
            $('#setting-bg-image').val('uploads/default.png');applyBrandingChanges();
            $('#background-preset-select').val(['pants_simple','pants_extended']);
            $('#preset-background-url').val('uploads/women.png');assignPresetBackground(false);
            $('#chart-preset-select').val('pants_simple');applyBrandingStyleToTarget('#td-preview',false);
        }""")
        check('women.png' in page.locator('#td-preview').evaluate('el=>el.style.backgroundImage'),'selected preset override renders in single canvas')
        page.evaluate("buildOffscreenCanvasForChart({preset_id:'pants_extended',title:'Fixture',columns:['Size'],rows:[['M']]})")
        check('women.png' in page.locator('#scs-batch-canvas').evaluate('el=>el.style.backgroundImage'),'batch canvas uses its own preset override')
        page.evaluate("buildOffscreenCanvasForChart({preset_id:'tops_extended',title:'Fixture',columns:['Size'],rows:[['M']]})")
        check('default.png' in page.locator('#scs-batch-canvas').evaluate('el=>el.style.backgroundImage'),'batch canvas without override uses project default without leaking last background')
        check(page.evaluate("JSON.parse(JSON.stringify(scs_settings.preset_backgrounds)).pants_simple")=='uploads/women.png','empty mapping serializes preset assignments correctly')
        for preset in ['persepolis', 'glass', 'minimal', 'dark_luxury', 'navy', 'transparent']:
            page.evaluate('preset => applyCustomThemePreset(preset)', preset)
            check(page.evaluate('scs_settings.style_mode') == 'custom', preset + ' preset callback applies')
        values = page.evaluate('''() => {
            $('#setting-padding-top,#setting-padding-bottom,#setting-padding-x,#setting-card-border-radius').val('0');
            applyBrandingChanges();
            return ['content_padding_top','content_padding_bottom','content_padding_x','card_border_radius'].map(key=>scs_settings[key]);
        }''')
        check(values == [0, 0, 0, 0], 'zero padding and square corners survive preview updates')
        check(page.locator('#branding-preview-content').evaluate('element=>getComputedStyle(element).paddingTop') == '0px', 'zero padding reaches rendered preview')
        check(page.locator('#bp-table-card').evaluate('element=>getComputedStyle(element).borderRadius') == '0px', 'square corners reach rendered preview')
        page.evaluate('''() => {
            $('#setting-table-text-align').val('left');
            $('#setting-table-row-alt-bg').val('#abcdef');
            $('#setting-table-bg-transparent,#setting-table-pure-data-overlay').prop('checked',false);
            applyBrandingChanges();
            testTableScaleRowCount(2);
        }''')
        check(page.locator('#bp-table-card tbody td').first.evaluate('element=>getComputedStyle(element).textAlign') == 'left', 'table alignment is not overridden by the base CSS')
        check(page.locator('#bp-table-card tbody tr').nth(1).evaluate('element=>getComputedStyle(element).backgroundColor') == 'rgb(171, 205, 239)', 'alternating row color is not overridden by the base CSS')
        row = page.locator('#branding-live-preview tbody tr').first
        page.evaluate("$('#setting-row-color-index').val('1');setIndividualRowColor('#ff0000')")
        check(row.evaluate('element=>getComputedStyle(element).backgroundColor') == 'rgb(255, 0, 0)', 'individual row color renders')
        page.evaluate('setIndividualRowColor(null)')
        check(row.evaluate('element=>getComputedStyle(element).backgroundColor') != 'rgb(255, 0, 0)', 'clearing a row restores the base style')
        page.evaluate("setIndividualRowColor('#123456')")
        with page.expect_response(lambda response: response.request.method == 'POST' and '/panel/size/' in response.url) as saved:
            page.evaluate('saveBrandingSettings()')
        result = saved.value.json()
        check(result.get('success') and result.get('project_id') == 'p', 'real branding save succeeds for the displayed project')
        page.reload()
        page.wait_for_function('typeof scs_settings !== "undefined" && typeof $ === "function"')
        for key in ['content_padding_top', 'content_padding_bottom', 'content_padding_x', 'card_border_radius']:
            check(page.evaluate('key=>scs_settings[key]', key) == 0, key + ' remains zero after reload')
        check(page.evaluate('scs_settings.group_backgrounds.shoes')=='uploads/group-shoes.png','all four group backgrounds persist after real save and reload')
        check(page.evaluate('id=>app_presets.find(p=>p.id===id)?.preset_group',female_id)=='women','copied preset group persists after reload')
        check(page.evaluate('scs_settings.preset_backgrounds.pants_extended') == 'uploads/women.png', 'preset backgrounds survive real save and reload')
        check(page.evaluate('getIndividualRowColor(0)') == '#123456', 'individual row color survives save and reload')
        output=Path(tempfile.mkdtemp(prefix='preset-groups-ui-'))
        for width in [1440,375]:
            page.set_viewport_size({'width':width,'height':1000})
            page.evaluate("switchTab('presets')")
            page.evaluate('id=>loadCategoryPreset(id)',female_id)
            panel=page.locator('#category-presets-form')
            check(panel.evaluate('el=>el.scrollWidth<=el.clientWidth+1'),'preset editor fits at '+str(width))
            panel.screenshot(path=str(output/('presets-'+str(width)+'.png')))
            page.evaluate("switchTab('branding')")
            panel=page.locator('#group-background-settings')
            check(panel.evaluate('el=>el.scrollWidth<=el.clientWidth+1'),'group background controls fit at '+str(width))
            panel.screenshot(path=str(output/('backgrounds-'+str(width)+'.png')))
        check(not errors, 'no JavaScript exceptions: ' + repr(errors))
        browser.close()
    print(json.dumps({'checks': len(checks), 'passed': checks, 'javascript_errors': errors, 'screenshots': str(output)}, ensure_ascii=False, indent=2))
finally:
    http_fixture.HttpSecurity.tearDownClass()

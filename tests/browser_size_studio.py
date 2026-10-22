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
        check(page.evaluate('getIndividualRowColor(0)') == '#123456', 'individual row color survives save and reload')
        check(not errors, 'no JavaScript exceptions: ' + repr(errors))
        browser.close()
    print(json.dumps({'checks': len(checks), 'passed': checks, 'javascript_errors': errors}, ensure_ascii=False, indent=2))
finally:
    http_fixture.HttpSecurity.tearDownClass()

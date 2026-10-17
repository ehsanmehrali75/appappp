"""Controlled browser regression for deferred product fields; no production requests."""
import asyncio
import html
import json
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

from crawler import scraper


PRODUCTS = ('late', 'attribute', 'ready', 'absent', 'empty-first', 'unsafe-first', 'mobile')


class Handler(BaseHTTPRequestHandler):
    requests = []
    request_lock = threading.Lock()

    def log_message(self, *_):
        pass

    def do_GET(self):
        path = urlparse(self.path).path
        with self.request_lock:
            self.requests.append(path)
        if path in ('/search', '/refresh-search'):
            products = PRODUCTS if path == '/search' else ('empty-first',)
            body = '<input id="total-page" value="1">' + ''.join(
                f'<div class="product-wrap"><div class="product-name">'
                f'<a href="/{name}">{name}</a></div></div>' for name in products
            )
        elif path == '/deferred-fields':
            body = '''<h1 class="product-name">Deferred</h1><span id="product-code">D1</span>
                <span id="sale-price"></span><a name="size-variant"></a>
                <div id="coloe-des"></div><nav class="breadcrumb"><a></a></nav>
                <div id="product-tab-description"></div>
                <div id="dv-product-gallery-main"><div class="product-single-carousel"><img></div></div>
                <script>setTimeout(() => {
                    document.querySelector('#sale-price').textContent = '250000';
                    document.querySelector('[name="size-variant"]').textContent = 'M';
                    document.querySelector('#coloe-des').textContent = 'Blue';
                    document.querySelector('.breadcrumb a').textContent = 'Clothing';
                    document.querySelector('#product-tab-description').textContent = 'Deferred description';
                    document.querySelector('.product-single-carousel img').setAttribute('data-zoom-image', '/deferred.jpg');
                }, 700)</script>'''
        elif path.lstrip('/') in PRODUCTS:
            name = path.lstrip('/')
            body = (f'<h1 class="product-name">{name}</h1>'
                    f'<span id="product-code">{name}</span>'
                    '<div id="product-tab-description">Description</div>')
            if name == 'late':
                body += '''<script>setTimeout(() => document.body.insertAdjacentHTML(
                    'beforeend', '<div class="container-sizeguide"><a id="a-size-guide" data-link="/content/media/chartsize/Chart_31543.jpg">Size</a></div>'),
                    2100)</script>'''
            elif name == 'attribute':
                body += '''<a id="a-size-guide" data-link=""></a><script>
                    setTimeout(() => document.querySelector('#a-size-guide')
                    .setAttribute('data-link', '/chart-attribute.jpg'), 2000)</script>'''
            elif name == 'ready':
                body += '<div class="container-sizeguide"><a data-link="/ready.jpg">Size</a></div>'
            elif name == 'empty-first':
                body += '''<a id="a-size-guide" data-link=""></a><script>
                    setTimeout(() => document.body.insertAdjacentHTML('beforeend',
                    '<div class="container-sizeguide"><a data-link="/fallback.jpg">Size</a></div>'),
                    1400)</script>'''
            elif name == 'unsafe-first':
                body += ('<a id="a-size-guide" data-link="javascript:alert(1)"></a>'
                         '<div class="container-sizeguide"><a data-link="/safe.jpg">Size</a></div>')
            elif name == 'mobile':
                body += ('<div class="mobile" style="display:none">'
                         '<a id="a-size-guide" data-link=""></a></div>'
                         '<div class="desktop"><a id="a-size-guide" '
                         'data-link="/desktop.jpg">Size</a></div>')
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.end_headers()
        self.wfile.write(body.encode())


def product_request_count():
    with Handler.request_lock:
        return sum(path.lstrip('/') in PRODUCTS for path in Handler.requests)


async def main(base, root):
    logs = []
    checks = []
    output = root / 'products.json'
    await scraper.run_scraper(
        base + '/search', 'fresh', ['name', 'description', 'size_guide'],
        logs.append, lambda _: None, use_system_proxy=False, output=str(output),
        size_guide_wait_ms=3200,
    )
    rows = {row['product_code']: row for row in json.loads(output.read_text(encoding='utf-8'))}
    expected = {
        'late': '/content/media/chartsize/Chart_31543.jpg',
        'attribute': '/chart-attribute.jpg',
        'ready': '/ready.jpg',
        'empty-first': '/fallback.jpg',
        'unsafe-first': '/safe.jpg',
        'mobile': '/desktop.jpg',
    }
    assert set(rows) == set(PRODUCTS), f'Unexpected product rows: {set(rows)}'
    for name, source in expected.items():
        assert rows[name]['size_guide'] == base + source, f'{name}: valid guide was missed'
        checks.append(name)
    assert rows['absent']['size_guide'] is None
    timeout_logs = [line for line in logs if 'no valid link' in line]
    assert len(timeout_logs) == 1 and '/absent' in timeout_logs[0], timeout_logs
    checks.append('only absent guide times out')

    for source in ('', '#guide', 'javascript:alert(1)', 'data:image/png;base64,AA==',
                   'file:///guide.jpg', 'mailto:guide@example.test'):
        parsed = scraper.parse_html(
            '<h1 class="product-name">Invalid</h1><a id="a-size-guide" '
            f'data-link="{html.escape(source, quote=True)}"></a>',
            base + '/invalid', ['size_guide'],
        )
        assert parsed['size_guide'] is None, f'Accepted unsafe guide: {source}'
    checks.append('unsafe URLs rejected')

    playwright, browser, page = await scraper.create_browser(False)
    try:
        started = time.monotonic()
        result = await scraper.parse_product_page(
            page, base + '/deferred-fields',
            ['name', 'price', 'sizes', 'color', 'category', 'description', 'gallery'],
            scraper.scraper_controller, logs.append, 10000, 1500,
        )
        assert result['description'] == 'Deferred description', result
        assert result['price'] == '250000' and result['sizes'] == ['M'], result
        assert result['color'] == 'Blue' and result['category'] == ['Clothing'], result
        assert result['gallery'] == [base + '/deferred.jpg'], result
        assert time.monotonic() - started < 2.5, 'Optional field wait exceeded its budget'
        checks.append('empty placeholders wait for deferred field contents')
        started = time.monotonic()
        result = await scraper.parse_product_page(
            page, base + '/absent', ['price', 'gallery'], scraper.scraper_controller,
            logs.append, 10000, 250, size_guide_wait_ms=5000,
        )
        assert result['price'] is None and result['gallery'] == [], result
        assert time.monotonic() - started < 4, 'Absent gallery exceeded its 3-second budget'
        checks.append('absent optional fields keep a bounded wait')
        started = time.monotonic()
        result = await scraper.parse_product_page(
            page, base + '/absent', ['name'], scraper.scraper_controller,
            logs.append, 10000, 800, size_guide_wait_ms=5000,
        )
        assert 'size_guide' not in result
        assert time.monotonic() - started < 2, 'Unselected guide incurred its wait'
        checks.append('unselected guide does not wait')
        for name in ('ready', 'unsafe-first', 'mobile'):
            started = time.monotonic()
            result = await scraper.parse_product_page(
                page, base + '/' + name, ['size_guide'], scraper.scraper_controller,
                logs.append, 10000, 800, size_guide_wait_ms=5000,
            )
            assert result['size_guide'] == base + expected[name]
            assert time.monotonic() - started < 2, f'{name}: ready guide did not exit early'
            checks.append(name + ' exits wait early')
    finally:
        try:
            await browser.close()
        finally:
            await playwright.stop()

    existing = {
        'url': base + '/empty-first', 'product_code': 'empty-first',
        'name': 'Preserved name', 'description': 'Preserved description',
        'price': '12345', 'size_guide': None,
    }
    refresh_output = root / 'refresh.json'
    refresh_output.write_text(json.dumps([existing]), encoding='utf-8')
    before = product_request_count()
    for mode in ('continue', 'update'):
        await scraper.run_scraper(
            base + '/refresh-search', mode, ['size_guide'], logs.append, lambda _: None,
            use_system_proxy=False, output=str(refresh_output), size_guide_wait_ms=3200,
        )
        assert json.loads(refresh_output.read_text(encoding='utf-8')) == [existing]
        assert product_request_count() == before, f'{mode}: existing product was fetched'
        checks.append(mode + ' skips stored blank guide')
    await scraper.run_scraper(
        base + '/refresh-search', 'update', ['size_guide'], logs.append, lambda _: None,
        use_system_proxy=False, output=str(refresh_output), size_guide_wait_ms=3200,
        refresh_existing=True,
    )
    expected_refresh = dict(existing, size_guide=base + '/fallback.jpg')
    assert json.loads(refresh_output.read_text(encoding='utf-8')) == [expected_refresh]
    assert product_request_count() == before + 1
    checks.append('true update fills blank guide and preserves unselected fields')
    print(f'{len(checks)} deferred size-guide checks passed: ' + ', '.join(checks))


if __name__ == '__main__':
    with tempfile.TemporaryDirectory(prefix='size-guide-check-') as temporary:
        with ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            server.daemon_threads = True
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                asyncio.run(main(f'http://127.0.0.1:{server.server_port}', Path(temporary)))
            finally:
                server.shutdown()
                thread.join(timeout=5)

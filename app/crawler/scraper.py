"""Bounded browser scraper compatible with the supplied PyQt5 Worker.

Existing calls remain valid. Optional keyword arguments tune concurrency, output,
checkpoints, timeouts, and genuine refreshing of previously collected products.
"""
import asyncio
import json
import os
import shutil
import tempfile
import threading
import time
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urljoin, urlparse

from bs4 import BeautifulSoup
from playwright.async_api import async_playwright

OUTPUT = 'output/products.json'
CHROME_PATHS = [
    r'C:\Program Files\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    r'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
    r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
]
SELECTORS = {
    'name': 'h1.product-name', 'price': '#sale-price',
    'sizes': 'a[name="size-variant"]', 'color': '#coloe-des',
    'category': '.breadcrumb a', 'description': '#product-tab-description',
    'size_guide': '#a-size-guide',
    'gallery': '#dv-product-gallery-main .product-single-carousel img',
}


class ScraperController:
    def __init__(self):
        self._paused = threading.Event()

    def pause(self):
        self._paused.set()

    def resume(self):
        self._paused.clear()

    def is_paused(self):
        return self._paused.is_set()

    async def wait_if_paused(self, log=None):
        announced = False
        while self.is_paused():
            if log and not announced:
                log('⏸ Scraper Paused — in-flight requests may finish')
                announced = True
            await asyncio.sleep(0.1)
        if announced and log:
            log('▶ Scraper Resumed')


scraper_controller = ScraperController()


def find_browser():
    explicit = os.environ.get('SCRAPER_BROWSER_PATH')
    if explicit:
        if not Path(explicit).is_file():
            raise RuntimeError('SCRAPER_BROWSER_PATH does not point to a browser')
        return explicit
    for path in CHROME_PATHS:
        if Path(path).is_file():
            return path
    for name in ('chromium', 'chromium-browser', 'google-chrome', 'msedge'):
        path = shutil.which(name)
        if path:
            return path
    return None  # Use Playwright's installed Chromium when no system browser exists.


async def create_browser(use_system_proxy=True, block_assets=True):
    playwright = await async_playwright().start()
    browser = None
    try:
        args = [] if use_system_proxy else ['--no-proxy-server', '--proxy-bypass-list=*']
        browser = await playwright.chromium.launch(
            executable_path=find_browser(), headless=True, args=args,
        )
        context = await browser.new_context(locale='fa-IR', viewport={'width': 1400, 'height': 900})

        async def route_request(route):
            # Keep scripts, CSS, XHR and fetch: they can populate product data.
            if block_assets and route.request.resource_type in ('image', 'media', 'font'):
                await route.abort()
            else:
                await route.continue_()
        await context.route('**/*', route_request)
        page = await context.new_page()
        return playwright, browser, page
    except BaseException:
        try:
            if browser:
                await browser.close()
        finally:
            await playwright.stop()
        raise


def load_old(output=None):
    path = Path(output or OUTPUT)
    if not path.exists():
        return []
    with path.open(encoding='utf-8-sig') as handle:
        data = json.load(handle)
    if not isinstance(data, list) or any(not isinstance(item, dict) or not item.get('url') for item in data):
        raise ValueError('Existing output must be a JSON array of products with URLs; it was not overwritten')
    return data


def atomic_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=path.name + '.', suffix='.tmp', dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w', encoding='utf-8') as handle:
            json.dump(value, handle, ensure_ascii=False, indent=2)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def save_products(products, output=None):
    atomic_json(output or OUTPUT, products)


class OutputStore:
    """One writer in the event loop; journal each result before batched snapshots."""
    def __init__(self, output, mode, checkpoint_every, log):
        self.path = Path(output)
        self.journal = Path(str(self.path) + '.journal.jsonl')
        self.mode = mode
        self.log = log
        self.every = checkpoint_every
        self.pending = 0
        self.started = False
        self.last_checkpoint = time.monotonic()
        self.items = {} if mode == 'fresh' else {item['url']: item for item in load_old(self.path)}
        if mode != 'fresh' and self.journal.exists():
            self.recover()

    def recover(self):
        lines = self.journal.read_bytes().splitlines(keepends=True)
        if not lines:
            return
        header = json.loads(lines[0])
        if header.get('format') != 'tanedorost-journal-v1' or header.get('mode') not in ('fresh', 'merge'):
            raise ValueError('Unrecognized recovery journal; output was not changed')
        if header['mode'] == 'fresh':
            self.items = {}
        recovered = 0
        for index, line in enumerate(lines[1:], start=1):
            try:
                item = json.loads(line)
            except (json.JSONDecodeError, UnicodeDecodeError):
                if index == len(lines) - 1 and not line.endswith(b'\n'):
                    self.log('Recovery: ignored an incomplete final journal entry')
                    break
                raise ValueError('Recovery journal is damaged; output was not changed')
            if not isinstance(item, dict) or not item.get('url'):
                raise ValueError('Recovery journal contains an invalid product')
            self.items[item['url']] = item
            recovered += 1
        self.log(f'Recovered journal entries => {recovered}')

    def reset_journal(self, mode):
        # Atomic replacement also handles a crash between snapshot and journal reset:
        # replay is idempotent, and fresh journals contain the entire first snapshot.
        self.journal.parent.mkdir(parents=True, exist_ok=True)
        descriptor, temporary = tempfile.mkstemp(prefix=self.journal.name + '.', dir=self.journal.parent)
        try:
            with os.fdopen(descriptor, 'w', encoding='utf-8') as handle:
                handle.write(json.dumps({'format': 'tanedorost-journal-v1', 'mode': mode}) + '\n')
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, self.journal)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def add(self, product):
        if not self.started:
            if self.mode == 'fresh' and self.path.exists():
                shutil.copy2(self.path, str(self.path) + '.bak')
            # Recovered values must be snapshotted before replacing their old journal.
            if self.mode != 'fresh':
                save_products(list(self.items.values()), self.path)
            self.reset_journal('fresh' if self.mode == 'fresh' else 'merge')
            self.started = True
        existing = self.items.get(product['url'], {})
        merged = dict(existing, **product)  # Keep fields not requested in this run.
        with self.journal.open('a', encoding='utf-8') as handle:
            handle.write(json.dumps(merged, ensure_ascii=False) + '\n')
            handle.flush()
            os.fsync(handle.fileno())
        self.items[product['url']] = merged
        self.pending += 1
        if self.pending >= self.every or time.monotonic() - self.last_checkpoint >= 5:
            self.checkpoint()

    def checkpoint(self):
        save_products(list(self.items.values()), self.path)
        self.reset_journal('merge')
        self.pending = 0
        self.last_checkpoint = time.monotonic()


class OutputLock:
    """OS lock; released automatically after a process crash, including Windows."""
    def __init__(self, output):
        self.path = Path(str(output) + '.lock')
        self.handle = None

    def __enter__(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.handle = self.path.open('a+b')
        self.handle.seek(0, os.SEEK_END)
        if not self.handle.tell():
            self.handle.write(b'0')
            self.handle.flush()
        self.handle.seek(0)
        try:
            if os.name == 'nt':
                import msvcrt
                msvcrt.locking(self.handle.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as error:
            self.handle.close()
            raise RuntimeError('Another scraper is writing to this output file') from error
        return self

    def __exit__(self, *_):
        if self.handle:
            self.handle.close()


def soup_from(html):
    return BeautifulSoup(html, 'lxml')


def product_links(html, base_url):
    soup = soup_from(html)
    return list(dict.fromkeys(
        urljoin(base_url, anchor['href'])
        for anchor in soup.select('.product-wrap .product-name a[href]')
        if urlparse(urljoin(base_url, anchor['href'])).scheme in ('http', 'https')
    ))


def category_urls(url, total):
    parsed = urlparse(url)
    query = parse_qs(parsed.query, keep_blank_values=True)
    for key, value in (('perpage', '24'), ('sortby', '1'), ('stock', '1')):
        query.setdefault(key, [value])
    pages = []
    for number in range(1, total + 1):
        query['pageno'] = [str(number)]
        pages.append(parsed._replace(query=urlencode(query, doseq=True), fragment='').geturl())
    return pages


async def navigate(page, url, selector, controller, log, timeout_ms=20000, retries=3):
    for attempt in range(retries):
        await controller.wait_if_paused(log)
        try:
            response = await page.goto(url, wait_until='domcontentloaded', timeout=timeout_ms)
            if response is None or response.status >= 400:
                code = response.status if response else 'no response'
                if code in (401, 403, 404):
                    raise ValueError(f'HTTP {code}: page is unavailable or access is denied')
                raise RuntimeError(f'HTTP {code}')
            await page.wait_for_selector(selector, state='attached', timeout=timeout_ms)
            return
        except ValueError:
            raise
        except Exception as error:
            if attempt == retries - 1:
                raise
            delay = min(2 ** attempt, 8)
            log(f'Retry {attempt + 1}/{retries - 1}: {url} ({type(error).__name__}); waiting {delay}s')
            await asyncio.sleep(delay)


async def detect_pages(page, url, log, controller=None, timeout_ms=20000):
    await navigate(page, category_urls(url, 1)[0], '.product-wrap, #total-page', controller or scraper_controller, log, timeout_ms)
    soup = soup_from(await page.content())
    total_input = soup.select_one('#total-page')
    total = max(1, int(total_input.get('value', '1'))) if total_input else 1
    if total > 10000:
        raise ValueError('Unexpected pagination count; please verify the category URL')
    log(f'Detected Pages => {total}')
    return category_urls(url, total)


def parse_html(html, url, fields):
    soup = soup_from(html)

    def text(selector):
        element = soup.select_one(selector)
        return element.get_text(strip=True) if element else None

    code = text('#product-code')
    if not code and not text('h1.product-name'):
        raise ValueError('Product markers missing; refusing to save a blank/challenge page')
    data = {'url': url, 'product_code': code}
    for field in fields:
        selector = SELECTORS[field]
        if field in ('name', 'price', 'color'):
            data[field] = text(selector)
        elif field in ('sizes', 'category'):
            data[field] = [node.get_text(strip=True) for node in soup.select(selector)]
        elif field == 'description':
            element = soup.select_one(selector)
            data[field] = element.get_text(' ', strip=True) if element else ''
        elif field == 'size_guide':
            element = soup.select_one(selector)
            data[field] = element.get('data-link') if element else None
        elif field == 'gallery':
            gallery = []
            for image in soup.select(selector):
                source = image.get('data-zoom-image') or image.get('data-src') or image.get('src')
                if source:
                    gallery.append(urljoin(url, source))
            data['gallery'] = list(dict.fromkeys(gallery))
            data['main_image'] = data['gallery'][0] if gallery else None
    return data


async def parse_product_page(page, url, fields, controller, log, timeout_ms, dynamic_wait_ms):
    await navigate(page, url, 'h1.product-name, #product-code', controller, log, timeout_ms)
    await page.wait_for_function(
        "() => ['h1.product-name','#product-code'].some(s => document.querySelector(s)?.textContent.trim())",
        timeout=timeout_ms,
    )
    # One bounded grace period for optional JS fields, rather than waiting for
    # every background request or imposing one fixed delay per field/product.
    if dynamic_wait_ms:
        selectors = [SELECTORS[field] for field in fields]
        try:
            await page.wait_for_function(
                'selectors => selectors.every(s => document.querySelector(s))',
                arg=selectors, timeout=dynamic_wait_ms,
            )
        except Exception as error:
            from playwright.async_api import TimeoutError as PlaywrightTimeoutError
            if not isinstance(error, PlaywrightTimeoutError):
                raise
    return parse_html(await page.content(), url, fields)


async def extract_links(browser, page_url):
    """Compatibility helper. The main runner uses persistent context pages."""
    context = browser.contexts[0]
    page = await context.new_page()
    try:
        await navigate(page, page_url, '.product-wrap, #total-page', scraper_controller, lambda _: None)
        return product_links(await page.content(), page_url)
    finally:
        await page.close()


async def parse_product(browser, url, fields):
    page = await browser.contexts[0].new_page()
    try:
        return await parse_product_page(page, url, fields, scraper_controller, lambda _: None, 20000, 800)
    finally:
        await page.close()


async def run_scraper(url, mode, fields, log, progress, batch_size=None,
                      controller=None, use_system_proxy=True, *, concurrency=4,
                      output=None, checkpoint_every=10, timeout_ms=20000,
                      dynamic_wait_ms=800, refresh_existing=False, block_assets=True):
    """Drop-in API. Legacy update/continue both skip already collected URLs.

    Set refresh_existing=True with mode='update' to actually refresh old URLs.
    Unrequested fields survive refresh. Batch size is a product limit, not a
    concurrency setting. Defaults cap active pages at four.
    """
    if mode not in ('fresh', 'continue', 'update'):
        raise ValueError('mode must be fresh, continue or update')
    if not fields or set(fields) - SELECTORS.keys():
        raise ValueError('Select known product fields')
    if urlparse(url).scheme not in ('https', 'http') or not urlparse(url).netloc:
        raise ValueError('Enter a complete HTTP/HTTPS category URL')
    if not 1 <= concurrency <= 8 or checkpoint_every < 1 or timeout_ms < 1 or dynamic_wait_ms < 0:
        raise ValueError('Use concurrency 1–8 and positive checkpoint/timeout settings')
    if batch_size is not None and batch_size < 0:
        raise ValueError('batch_size must be nonnegative')
    controller = controller or scraper_controller
    output = output or OUTPUT
    started = time.monotonic()
    progress(0)
    with OutputLock(output):
        store = OutputStore(output, mode, checkpoint_every, log)
        playwright = browser = None
        try:
            playwright, browser, page = await create_browser(use_system_proxy, block_assets)
            context = page.context
            log(f'Workers => {concurrency}; proxy => {"system" if use_system_proxy else "direct"}')
            log('Pause prevents new requests; requests already in flight may finish.')
            discover_started = time.monotonic()
            pages = await detect_pages(page, url, log, controller, timeout_ms)
            seen = set()
            links = []
            refresh = mode == 'update' and refresh_existing
            # Discovery is incremental: a 50-product batch does not crawl every
            # category page first. Continue still counts only unseen URLs.
            for number, category in enumerate(pages):
                await controller.wait_if_paused(log)
                if number:
                    await navigate(page, category, '.product-wrap, #total-page', controller, log, timeout_ms)
                found = product_links(await page.content(), category)
                for link in found:
                    if link in seen:
                        continue
                    seen.add(link)
                    if mode != 'fresh' and not refresh and link in store.items:
                        continue
                    links.append(link)
                    if batch_size and len(links) >= batch_size:
                        break
                log(f'Category {number + 1}/{len(pages)} => {len(found)} links; selected => {len(links)}')
                if batch_size and len(links) >= batch_size:
                    break
            log(f'Discovery => {time.monotonic() - discover_started:.2f}s; selected => {len(links)}')
            await page.close()
            if not links:
                if mode == 'fresh':
                    raise RuntimeError('No product links found; existing output was retained. Verify the category URL.')
                if mode != 'fresh':
                    store.checkpoint()  # Also commits recovered journal data.
                progress(100)
                log('No products to process. Existing output was retained.')
                return str(Path(output).resolve())
            queue = asyncio.Queue()
            for index, link in enumerate(links):
                queue.put_nowait((index, link))
            original_order = list(store.items)
            completed = successful = 0
            failures = []
            results = {}

            async def worker():
                nonlocal completed, successful
                worker_page = await context.new_page()
                while True:
                    await controller.wait_if_paused(log)
                    try:
                        index, link = queue.get_nowait()
                    except asyncio.QueueEmpty:
                        return
                    item_started = time.monotonic()
                    try:
                        product = await parse_product_page(worker_page, link, fields, controller, log, timeout_ms, dynamic_wait_ms)
                    except Exception as error:
                        failures.append(link)
                        log(f'FAILED => {link}: {type(error).__name__}: {str(error)[:250]}')
                    else:
                        # Storage failures are fatal, rather than silently
                        # reporting products as scraped when they were lost.
                        store.add(product)
                        results[index] = product
                        successful += 1
                        log(f'SAVED => {link} ({time.monotonic() - item_started:.2f}s)')
                    finally:
                        queue.task_done()
                    completed += 1
                    progress(int(completed * 100 / len(links)))

            tasks = [asyncio.create_task(worker()) for _ in range(min(concurrency, len(links)))]
            try:
                await asyncio.gather(*tasks)
            finally:
                for task in tasks:
                    if not task.done():
                        task.cancel()
                await asyncio.gather(*tasks, return_exceptions=True)
            if not successful:
                raise RuntimeError('All selected products failed; existing output was retained. Check logs.')
            # Deterministic output despite out-of-order worker completion.
            ordered = {link: store.items[link] for link in original_order}
            for index in sorted(results):
                item = results[index]
                ordered[item['url']] = store.items[item['url']]
            store.items = ordered
            store.checkpoint()
            log(f'Finished => {successful} saved, {len(failures)} failed; {time.monotonic() - started:.2f}s total')
            if failures:
                log('Choose Continue to retry failed/new URLs; failed rows were not replaced.')
            if batch_size:
                log('Product limit reached or category exhausted; Continue discovers the next unsaved URLs.')
            return str(Path(output).resolve())
        finally:
            try:
                if browser:
                    try:
                        await asyncio.wait_for(browser.close(), timeout=10)
                    except asyncio.TimeoutError:
                        log('Browser shutdown timed out; stopping Playwright driver')
            finally:
                if playwright:
                    await asyncio.wait_for(playwright.stop(), timeout=10)

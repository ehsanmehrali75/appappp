import os
import json
import asyncio
import threading

from urllib.parse import (
    urljoin,
    urlparse,
    parse_qs,
    urlencode
)

from bs4 import BeautifulSoup
from playwright.async_api import async_playwright


OUTPUT = "output/products.json"

CHROME_PATHS = [
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe"
]


# =========================================================
# PAUSE / RESUME CONTROLLER
# =========================================================

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

        pause_logged = False

        while self._paused.is_set():

            if log and not pause_logged:
                log("⏸ Scraper Paused")
                pause_logged = True

            await asyncio.sleep(0.5)

        if pause_logged and log:
            log("▶ Scraper Resumed")


# یک کنترلر مشترک
scraper_controller = ScraperController()


# =========================================================
# BROWSER
# =========================================================

def find_browser():

    for path in CHROME_PATHS:

        if os.path.exists(path):
            return path

    raise Exception(
        "Chrome/Edge not found"
    )


async def create_browser(use_system_proxy=True):

    playwright = await async_playwright().start()

    browser_args = [
        "--disable-blink-features=AutomationControlled",
        "--no-sandbox"
    ]

    # اگر پروکسی سیستم نباید استفاده شود
    if not use_system_proxy:
        browser_args.extend([
            "--no-proxy-server",
            "--proxy-bypass-list=*"
        ])

    browser = await playwright.chromium.launch(
        executable_path=find_browser(),
        headless=True,
        args=browser_args
    )

    context = await browser.new_context(
        locale="fa-IR",
        viewport={
            "width": 1400,
            "height": 900
        }
    )

    page = await context.new_page()

    return playwright, browser, page


# =========================================================
# FILE FUNCTIONS
# =========================================================

def load_old():

    if not os.path.exists(OUTPUT):
        return []

    try:

        with open(
            OUTPUT,
            "r",
            encoding="utf-8"
        ) as f:

            data = json.load(f)

            if isinstance(data, list):
                return data

    except Exception as e:

        print(
            "Could not read old file:",
            e
        )

    return []


def save_products(products):
    """
    ذخیره امن فایل.
    اول فایل temp ساخته می‌شود و سپس جای فایل اصلی می‌نشیند.
    """

    os.makedirs(
        os.path.dirname(OUTPUT),
        exist_ok=True
    )

    temp_file = OUTPUT + ".tmp"

    with open(
        temp_file,
        "w",
        encoding="utf-8"
    ) as f:

        json.dump(
            products,
            f,
            ensure_ascii=False,
            indent=2
        )

    os.replace(
        temp_file,
        OUTPUT
    )


# =========================================================
# DETECT CATEGORY PAGES
# =========================================================

async def detect_pages(
    page,
    url,
    log
):

    await page.goto(
        url,
        wait_until="networkidle"
    )

    soup = BeautifulSoup(
        await page.content(),
        "lxml"
    )

    total_pages = 1

    total_input = soup.select_one(
        "#total-page"
    )

    if total_input:

        try:

            total_pages = int(
                total_input.get("value")
            )

        except:
            pass

    log(
        f"Detected Pages => {total_pages}"
    )

    parsed = urlparse(url)

    query = parse_qs(
        parsed.query
    )

    pages = []

    for i in range(
        1,
        total_pages + 1
    ):

        query["pageno"] = [
            str(i)
        ]

        if "perpage" not in query:
            query["perpage"] = ["24"]

        if "sortby" not in query:
            query["sortby"] = ["1"]

        if "stock" not in query:
            query["stock"] = ["1"]

        new_query = urlencode(
            query,
            doseq=True
        )

        final_url = parsed._replace(
            query=new_query
        ).geturl()

        pages.append(
            final_url
        )

    return pages


# =========================================================
# EXTRACT PRODUCT LINKS
# =========================================================

async def extract_links(
    browser,
    page_url
):

    page = await browser.new_page()

    try:

        await page.goto(
            page_url,
            wait_until="networkidle"
        )

        soup = BeautifulSoup(
            await page.content(),
            "lxml"
        )

        links = set()

        for item in soup.select(
            ".product-wrap"
        ):

            a = item.select_one(
                ".product-name a"
            )

            if (
                a
                and a.get("href")
            ):

                links.add(
                    urljoin(
                        page_url,
                        a.get("href")
                    )
                )

        return list(
            links
        )

    finally:

        await page.close()


# =========================================================
# PARSE PRODUCT
# =========================================================

async def parse_product(
    browser,
    url,
    fields
):

    page = await browser.new_page()

    retries = 3

    try:

        while retries > 0:

            try:

                await page.goto(
                    url,
                    wait_until="networkidle"
                )

                soup = BeautifulSoup(
                    await page.content(),
                    "lxml"
                )

                def text(selector):

                    el = soup.select_one(
                        selector
                    )

                    return (
                        el.get_text(
                            strip=True
                        )
                        if el
                        else None
                    )

                data = {
                    "url": url
                }

                # PRODUCT CODE

                data["product_code"] = text(
                    "#product-code"
                )

                # NAME

                if "name" in fields:

                    data["name"] = text(
                        "h1.product-name"
                    )

                # PRICE

                if "price" in fields:

                    data["price"] = text(
                        "#sale-price"
                    )

                # SIZES

                if "sizes" in fields:

                    data["sizes"] = [
                        x.get_text(
                            strip=True
                        )
                        for x in soup.select(
                            'a[name="size-variant"]'
                        )
                    ]

                # COLOR

                if "color" in fields:

                    data["color"] = text(
                        "#coloe-des"
                    )

                # CATEGORY

                if "category" in fields:

                    data["category"] = [
                        x.get_text(
                            strip=True
                        )
                        for x in soup.select(
                            ".breadcrumb a"
                        )
                    ]

                # DESCRIPTION

                if "description" in fields:

                    desc = soup.select_one(
                        "#product-tab-description"
                    )

                    data["description"] = (
                        desc.get_text(
                            " ",
                            strip=True
                        )
                        if desc
                        else ""
                    )

                # SIZE GUIDE

                if "size_guide" in fields:

                    sg = soup.select_one(
                        "#a-size-guide"
                    )

                    data["size_guide"] = (
                        sg.get(
                            "data-link"
                        )
                        if sg
                        else None
                    )

                # GALLERY

                if "gallery" in fields:

                    gallery = []

                    for img in soup.select(
                        "#dv-product-gallery-main "
                        ".product-single-carousel img"
                    ):

                        src = (
                            img.get(
                                "data-zoom-image"
                            )
                            or img.get(
                                "data-src"
                            )
                            or img.get(
                                "src"
                            )
                        )

                        if src:

                            gallery.append(
                                urljoin(
                                    url,
                                    src
                                )
                            )

                    gallery = list(
                        dict.fromkeys(
                            gallery
                        )
                    )

                    data[
                        "gallery"
                    ] = gallery

                    data[
                        "main_image"
                    ] = (
                        gallery[0]
                        if gallery
                        else None
                    )

                return data

            except Exception as e:

                print(
                    "ERROR:",
                    url,
                    e
                )

                retries -= 1

                await asyncio.sleep(2)

        return None

    finally:

        await page.close()


# =========================================================
# MAIN SCRAPER
# =========================================================

async def run_scraper(
    url,
    mode,
    fields,
    log,
    progress,
    batch_size=None,
    controller=None,
    use_system_proxy=True
):

    """
    batch_size:

        50   = فقط 50 محصول
        100  = فقط 100 محصول
        None = همه محصولات
        0    = همه محصولات


    mode:

        fresh
            از اول شروع می‌کند

        update
            محصولات موجود را Skip می‌کند

        continue
            فایل قبلی را می‌خواند و فقط ادامه کار را انجام می‌دهد
    """

    if controller is None:
        controller = scraper_controller

    playwright = None
    browser = None

    try:

        playwright, browser, page = (
            await create_browser(
                use_system_proxy=use_system_proxy
            )
        )

        if use_system_proxy:
            log("🌐 Proxy Mode => System Proxy")
        else:
            log("🌐 Proxy Mode => Disabled / Direct Connection")

        # -------------------------------------------------
        # DETECT ALL CATEGORY PAGES
        # -------------------------------------------------

        pages = await detect_pages(
            page,
            url,
            log
        )

        await page.close()

        all_links = []

        # -------------------------------------------------
        # GET ALL PRODUCT LINKS
        # -------------------------------------------------

        for page_number, pg in enumerate(
            pages,
            start=1
        ):

            await controller.wait_if_paused(
                log
            )

            try:

                links = await extract_links(
                    browser,
                    pg
                )

                log(
                    f"Page {page_number}/{len(pages)} "
                    f"=> {len(links)} products"
                )

                all_links.extend(
                    links
                )

            except Exception as e:

                log(
                    f"PAGE ERROR => {e}"
                )

        # حفظ ترتیب و حذف تکراری

        all_links = list(
            dict.fromkeys(
                all_links
            )
        )

        log(
            f"Total Products Found => "
            f"{len(all_links)}"
        )

        # -------------------------------------------------
        # LOAD EXISTING DATA
        # -------------------------------------------------

        if mode in (
            "continue",
            "update"
        ):

            old = load_old()

        else:

            old = []

        # map محصولات قبلی

        old_map = {
            item["url"]: item
            for item in old
            if item.get("url")
        }

        # -------------------------------------------------
        # CONTINUE / UPDATE
        # -------------------------------------------------

        if mode in (
            "continue",
            "update"
        ):

            remaining_links = [
                link
                for link in all_links
                if link not in old_map
            ]

            log(
                f"Already Scraped => "
                f"{len(old_map)}"
            )

            log(
                f"Remaining => "
                f"{len(remaining_links)}"
            )

        else:

            remaining_links = (
                all_links.copy()
            )

        # -------------------------------------------------
        # BATCH SIZE
        # -------------------------------------------------

        if (
            batch_size
            and batch_size > 0
        ):

            current_batch = (
                remaining_links[
                    :batch_size
                ]
            )

        else:

            current_batch = (
                remaining_links
            )

        log(
            f"Current Batch => "
            f"{len(current_batch)} products"
        )

        # -------------------------------------------------
        # PREPARE FINAL DATA
        # -------------------------------------------------

        if mode in (
            "continue",
            "update"
        ):

            final = old.copy()

        else:

            final = []

            # در حالت fresh همان اول فایل قبلی پاک می‌شود

            save_products(
                final
            )

        total = len(
            current_batch
        )

        # چیزی باقی نمانده

        if total == 0:

            progress(100)

            log(
                "✅ No remaining products."
            )

            return os.path.abspath(
                OUTPUT
            )

        # -------------------------------------------------
        # SCRAPE PRODUCTS
        # -------------------------------------------------

        for i, link in enumerate(
            current_batch
        ):

            # PAUSE CHECK

            await controller.wait_if_paused(
                log
            )

            try:

                log(
                    f"[{i + 1}/{total}] "
                    f"SCRAPING => {link}"
                )

                product = (
                    await parse_product(
                        browser,
                        link,
                        fields
                    )
                )

                if product:

                    # اگر به هر دلیلی URL تکراری بود

                    existing_index = None

                    for index, item in enumerate(
                        final
                    ):

                        if (
                            item.get("url")
                            == link
                        ):

                            existing_index = index
                            break

                    if existing_index is None:

                        final.append(
                            product
                        )

                    else:

                        final[
                            existing_index
                        ] = product

                    # =====================================
                    # مهم:
                    # بعد از هر محصول فایل ذخیره می‌شود
                    # =====================================

                    save_products(
                        final
                    )

                    log(
                        f"✅ SAVED => {link}"
                    )

                else:

                    log(
                        f"❌ FAILED => {link}"
                    )

            except Exception as e:

                log(
                    f"ERROR => {link} => {e}"
                )

            # PROGRESS

            percentage = int(
                (
                    (i + 1)
                    / total
                )
                * 100
            )

            progress(
                percentage
            )

        # -------------------------------------------------
        # FINISH
        # -------------------------------------------------

        completed_urls = {
            item.get("url")
            for item in final
            if item.get("url")
        }

        remaining_after_batch = [
            x
            for x in all_links
            if x not in completed_urls
        ]

        log(
            f"Batch Finished."
        )

        log(
            f"Total Saved => "
            f"{len(final)}"
        )

        log(
            f"Still Remaining => "
            f"{len(remaining_after_batch)}"
        )

        if remaining_after_batch:

            log(
                "برای ادامه، حالت Continue "
                "را انتخاب کنید."
            )

        else:

            log(
                "✅ ALL PRODUCTS COMPLETED"
            )

        return os.path.abspath(
            OUTPUT
        )

    finally:

        if browser:

            try:
                await browser.close()
            except:
                pass

        if playwright:

            try:
                await playwright.stop()
            except:
                pass
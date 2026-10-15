"""Library pagination against an isolated PHP/SQLite fixture, never deployment data."""
from html.parser import HTMLParser
import re
import sqlite3
import unittest
import urllib.parse

import test_http_security as http_fixture


class PaginationMarkup(HTMLParser):
    def __init__(self, page):
        super().__init__()
        self.controls = []
        self.gaps = 0
        self.feed(re.search(r'<nav class="portal-pagination".*?</nav>', page, re.S)[0])

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if 'portal-pagination-link' in attrs.get('class', '').split():
            self.controls.append(attrs)
        if 'portal-pagination-gap' in attrs.get('class', '').split():
            self.gaps += 1


class PortalPagination(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        http_fixture.HttpSecurity.setUpClass()
        cls.fixture = http_fixture.HttpSecurity('runTest')

    @classmethod
    def tearDownClass(cls):
        http_fixture.HttpSecurity.tearDownClass()

    def setUp(self):
        self.fixture.setUp()
        self.opener, _ = self.fixture.login()
        self.filters = {
            'tab': 'library', 'project': 'p', 'library_status': 'ready',
            'f_q': 'کالای صفحه‌بندی', 'f_cat': 'سبز', 'f_price': 'under_1m',
            'f_stock': 'gt_20', 'f_stock_min': '20',
            'f_creator': 'fixture_creator', 'f_sort': 'oldest',
        }

    def seed_products(self, count):
        with sqlite3.connect(self.fixture.database) as db:
            db.executemany(
                'INSERT INTO products(id,project_id,sku,name,image,images_list,gallery,stock_total,status,color,price,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [(f'pager-{n:04d}', 'p', str(n), 'کالای صفحه‌بندی', 'uploads/original.png',
                  '[{"url":"uploads/original.png","source":"manual","approved":true}]',
                  '[]', '25', 'active', 'سبز', '500000', 'fixture_creator') for n in range(count)],
            )
            db.execute(
                'INSERT INTO products(id,project_id,sku,name,image,images_list,gallery,stock_total,status,color,price,uploaded_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                ('pager-pending', 'p', '0', 'کالای صفحه‌بندی', 'uploads/original.png',
                 '[{"url":"uploads/original.png","source":"manual","approved":false}]',
                 '[]', '25', 'pending', 'سبز', '500000', 'fixture_creator'),
            )
        self.clear_cache()

    def clear_cache(self):
        for path in (self.fixture.app / 'panel/db/cache').glob('*.cache'):
            path.unlink()

    def library(self, requested_page):
        path = '/panel/portal.php?' + urllib.parse.urlencode(dict(self.filters, p=requested_page))
        code, page = self.fixture.request(self.opener, path)
        self.assertEqual(code, 200)
        library = page.split('id="tab-library"', 1)[1].split('<!-- Tab 4: Out of Stock -->', 1)[0]
        ids = re.findall(r'data-product-id="([^"]+)"', library)
        return library, ids

    def test_page_two_keeps_filters_and_has_its_own_product_slice(self):
        # The pending queue has one page; it must not clamp the library to page 1.
        self.seed_products(240)
        first, first_ids = self.library(1)
        first_controls = PaginationMarkup(first).controls
        next_link = next(c for c in first_controls if c.get('rel') == 'next')
        target = urllib.parse.urlparse(next_link['href'])
        self.assertEqual(urllib.parse.parse_qs(target.query), {k: [v] for k, v in dict(self.filters, p='2').items()})
        code, second = self.fixture.request(self.opener, '/panel/' + next_link['href'])
        self.assertEqual(code, 200)
        second = second.split('id="tab-library"', 1)[1].split('<!-- Tab 4: Out of Stock -->', 1)[0]
        second_ids = re.findall(r'data-product-id="([^"]+)"', second)
        self.assertEqual(first_ids, [f'pager-{n:04d}' for n in range(12)])
        self.assertEqual(second_ids, [f'pager-{n:04d}' for n in range(12, 24)])
        self.filters['library_status'] = 'pending_approval'
        self.assertEqual(self.library(1)[1], ['pager-pending'])
        self.filters['library_status'] = 'ready'
        self.assertEqual(len([c for c in PaginationMarkup(second).controls if c.get('aria-current') == 'page']), 1)
        middle, _ = self.library(10)
        markup = PaginationMarkup(middle)
        self.assertEqual(markup.gaps, 2)
        self.assertEqual(len(markup.controls), 7)  # five numbers and previous/next
        for control in markup.controls:
            if 'href' in control:
                actual = urllib.parse.parse_qs(urllib.parse.urlparse(control['href']).query)
                self.assertEqual({k: actual[k][0] for k in self.filters}, self.filters)

    def test_page_bounds_and_short_ranges(self):
        self.seed_products(25)
        for value in ['0', '-3', 'invalid']:
            with self.subTest(page=value):
                page, ids = self.library(value)
                self.assertEqual(ids, [f'pager-{n:04d}' for n in range(12)])
                controls = PaginationMarkup(page).controls
                self.assertEqual(controls[0].get('aria-disabled'), 'true')
                self.assertEqual(PaginationMarkup(page).gaps, 0)
        page, ids = self.library(999)
        self.assertEqual(ids, ['pager-0024'])
        self.assertEqual(PaginationMarkup(page).controls[-1].get('aria-disabled'), 'true')
        with sqlite3.connect(self.fixture.database) as db:
            db.execute("DELETE FROM products WHERE id LIKE 'pager-%' AND CAST(sku AS INTEGER) >= 12")
        self.clear_cache()
        page, ids = self.library(1)
        self.assertEqual(len(ids), 12)
        self.assertNotIn('class="portal-pagination"', page)
        with sqlite3.connect(self.fixture.database) as db:
            db.execute("DELETE FROM products WHERE id LIKE 'pager-%'")
        self.clear_cache()
        page, ids = self.library(1)
        self.assertEqual(ids, [])
        self.assertNotIn('class="portal-pagination"', page)


if __name__ == '__main__':
    unittest.main()

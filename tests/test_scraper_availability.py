"""Supplied Sportland lead-size mismatch and mixed stock; no live site required."""
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('stock_scraper', ROOT/'app/crawler/scraper.py')
scraper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scraper)

class ScraperAvailability(unittest.TestCase):
    def test_supplied_product_group_and_disabled_lead_size(self):
        html = (ROOT/'app/fixtures/sportland-partial-stock.html').read_text()
        row = scraper.parse_html(html, 'https://www.sportland.ir/product/37975/example', ['name','color','sizes','gallery'])
        self.assertEqual(row['product_code'], '37975')
        self.assertEqual(row['color'], 'سفید')
        self.assertEqual(row['unavailable_sizes'], ['40'])
        self.assertEqual(row['available_sizes'], ['41','42','43','44'])
        self.assertTrue(row['website_available'])
        self.assertEqual(len(row['gallery']),4)
        self.assertEqual(row['category'], ['مردانه','کفش','کفش روزانه'])
        # JSON-LD also carries mixed availability; the top-level OutOfStock is the lead SKU.
        soup = scraper.soup_from(html)
        for node in soup.select('a[name="size-variant"]'): node.decompose()
        schema_only = scraper.parse_html(str(soup), row['url'], ['sizes','gallery'])
        self.assertEqual(schema_only['available_sizes'], row['available_sizes'])
        self.assertTrue(schema_only['website_available'])
        self.assertEqual(schema_only['gallery'], row['gallery'])

    def test_all_sold_out_unknown_and_active_dom(self):
        prefix='<h1 class="product-name">Fixture</h1><span id="product-code">1</span>'
        html=prefix+'<a name="size-variant" disabled><del>M</del></a><a name="size-variant" aria-disabled="true">L</a>'
        result=scraper.parse_html(html,'https://example.test/product/1',['sizes'])
        self.assertFalse(result['website_available'])
        self.assertEqual(result['unavailable_sizes'],['M','L'])
        self.assertIsNone(scraper.parse_html(prefix,'https://example.test/product/1',['sizes'])['website_available'])
        schema={'@type':'ProductGroup','sku':'1','hasVariant':[{'size':'M','offers':{'availability':'https://schema.org/OutOfStock'}}]}
        row=scraper.parse_html(prefix+'<a name="size-variant">M</a><script type="application/ld+json">'+json.dumps(schema)+'</script>','https://example.test/product/1',['sizes'])
        self.assertTrue(row['website_available'],'Current DOM overrides stale schema for the same size')

if __name__=='__main__': unittest.main()

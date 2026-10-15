"""Offscreen desktop configuration regression; the scraper is mocked, no network."""
import os
from unittest.mock import patch

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
from PyQt5.QtWidgets import QApplication, QMessageBox
import app as desktop


def main():
    qt = QApplication.instance() or QApplication([])
    window = desktop.Window()
    window.show()
    qt.processEvents()
    assert window.height() <= 850, "Run controls must fit a normal desktop window"
    assert window.start_btn.isVisible() and window.logs.isVisible()
    assert desktop.SCRAPER_VERSION in window.windowTitle()
    assert window.size_guide_wait.value() == 8
    window.mode.setCurrentIndex(window.mode.findData('update'))
    assert window.refresh_existing.isEnabled() and window.refresh_existing.isChecked()
    window.size_guide_wait.setValue(12)
    window.url.setText('https://shop.example/search')
    calls = []

    async def fake_scrape(**options):
        calls.append(options)
        return '/synthetic/products.json'

    with patch.object(desktop.Worker, 'start'), patch.object(desktop, 'run_scraper', fake_scrape), patch.object(QMessageBox, 'information'):
        window.start()
        window.worker.run()
    assert len(calls) == 1 and calls[0]['size_guide_wait_ms'] == 12000
    assert calls[0]['refresh_existing'] and calls[0]['mode'] == 'update'
    assert 'size_guide' in calls[0]['fields']
    window.checks['size_guide'].setChecked(False)
    assert not window.size_guide_wait.isEnabled()
    window.mode.setCurrentIndex(window.mode.findData('continue'))
    assert not window.refresh_existing.isEnabled()
    window.close()
    qt.processEvents()
    print('Desktop settings passed: version, configurable wait, true update forwarded, optional guide disabled')


if __name__ == '__main__':
    main()

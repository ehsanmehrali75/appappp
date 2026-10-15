import sys
import asyncio

from PyQt5.QtWidgets import *
from PyQt5.QtCore import *

from crawler.scraper import (
    run_scraper,
    scraper_controller
)


FIELDS = [
    ("name", "نام محصول"),
    ("price", "قیمت"),
    ("sizes", "سایز"),
    ("gallery", "گالری"),
    ("description", "توضیحات"),
    ("category", "دسته بندی"),
    ("color", "رنگ"),
    ("size_guide", "جدول سایز"),
]


class Worker(QThread):
    log_signal = pyqtSignal(str)
    progress_signal = pyqtSignal(int)
    done_signal = pyqtSignal(str)
    error_signal = pyqtSignal(str)

    def __init__(self, url, mode, fields, batch_size, use_system_proxy, concurrency=4, refresh_existing=False):
        super().__init__()
        self.url = url
        self.mode = mode
        self.fields = fields
        self.batch_size = batch_size
        self.use_system_proxy = use_system_proxy
        self.concurrency = concurrency
        self.refresh_existing = refresh_existing

    def run(self):
        try:
            result = asyncio.run(
                run_scraper(
                    url=self.url,
                    mode=self.mode,
                    fields=self.fields,
                    log=self.log_signal.emit,
                    progress=self.progress_signal.emit,
                    batch_size=self.batch_size,
                    controller=scraper_controller,
                    use_system_proxy=self.use_system_proxy,
                    concurrency=self.concurrency,
                    refresh_existing=self.refresh_existing,
                )
            )
            self.done_signal.emit(result)
        except Exception as e:
            self.error_signal.emit(str(e))


class Window(QWidget):
    def __init__(self):
        super().__init__()

        self.setWindowTitle("Tanedorost Scraper — Fast Edition")
        self.resize(900, 850)

        layout = QVBoxLayout()

        layout.addWidget(QLabel("لینک دسته بندی"))
        self.url = QLineEdit()
        self.url.setPlaceholderText("https://www.tanedorost.com/search/...")
        layout.addWidget(self.url)

        layout.addWidget(QLabel("حالت اجرا"))
        self.mode = QComboBox()
        self.mode.addItem("شروع جدید", "fresh")
        self.mode.addItem("ادامه از فایل قبلی", "continue")
        self.mode.addItem("آپدیت", "update")
        layout.addWidget(self.mode)

        layout.addWidget(QLabel("تعداد محصولات در هر اجرا"))
        self.batch = QComboBox()
        self.batch.addItem("50 محصول", 50)
        self.batch.addItem("100 محصول", 100)
        self.batch.addItem("همه محصولات", 0)
        layout.addWidget(self.batch)

        layout.addWidget(QLabel("اتصال اینترنت"))
        self.proxy = QComboBox()
        self.proxy.addItem("استفاده از پروکسی سیستم", True)
        self.proxy.addItem("بدون پروکسی - اتصال مستقیم", False)
        layout.addWidget(self.proxy)

        layout.addWidget(QLabel("تعداد دریافت هم‌زمان (مستقل از تعداد محصولات)"))
        self.concurrency = QSpinBox()
        self.concurrency.setRange(1, 8)
        self.concurrency.setValue(4)
        self.concurrency.setToolTip("از ۲ تا ۴ شروع کنید؛ در صورت خطای سایت کاهش دهید.")
        layout.addWidget(self.concurrency)
        self.refresh_existing = QCheckBox("در حالت آپدیت، اطلاعات محصولات قبلی هم تازه شود")
        self.refresh_existing.setChecked(False)
        layout.addWidget(self.refresh_existing)

        layout.addWidget(QLabel("فیلدهای مورد نیاز"))
        self.checks = {}
        grid = QGridLayout()
        row = 0
        col = 0

        for key, label in FIELDS:
            cb = QCheckBox(label)
            cb.setChecked(True)
            self.checks[key] = cb
            grid.addWidget(cb, row, col)
            col += 1
            if col > 1:
                col = 0
                row += 1

        layout.addLayout(grid)

        buttons = QHBoxLayout()
        self.start_btn = QPushButton("شروع استخراج")
        self.pause_btn = QPushButton("Pause")
        self.resume_btn = QPushButton("Resume")
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(False)
        buttons.addWidget(self.start_btn)
        buttons.addWidget(self.pause_btn)
        buttons.addWidget(self.resume_btn)
        layout.addLayout(buttons)

        self.progress = QProgressBar()
        self.progress.setValue(0)
        layout.addWidget(self.progress)

        layout.addWidget(QLabel("گزارش عملیات"))
        self.logs = QPlainTextEdit()
        self.logs.setMaximumBlockCount(2000)
        self.logs.setReadOnly(True)
        layout.addWidget(self.logs)

        copy_log = QPushButton("کپی گزارش عملیات")
        copy_log.clicked.connect(lambda: QApplication.clipboard().setText(self.logs.toPlainText()))
        layout.addWidget(copy_log)
        self.setLayout(layout)

        self.start_btn.clicked.connect(self.start)
        self.pause_btn.clicked.connect(self.pause_scraper)
        self.resume_btn.clicked.connect(self.resume_scraper)

    def start(self):
        url = self.url.text().strip()
        if not url:
            QMessageBox.warning(self, "خطا", "لینک وارد نشده")
            return

        fields = [key for key, checkbox in self.checks.items() if checkbox.isChecked()]
        if not fields:
            QMessageBox.warning(self, "خطا", "حداقل یک فیلد انتخاب کنید")
            return

        mode = self.mode.currentData()
        batch_size = self.batch.currentData()
        if batch_size == 0:
            batch_size = None

        use_system_proxy = self.proxy.currentData()
        scraper_controller.resume()

        self.logs.clear()
        self.progress.setValue(0)
        self.logs.appendPlainText("================================")
        self.logs.appendPlainText("🚀 Scraper Started")
        self.logs.appendPlainText(f"Mode => {mode}")
        self.logs.appendPlainText(f"Batch => {batch_size if batch_size else 'ALL'}")
        self.logs.appendPlainText(
            "Proxy => System Proxy" if use_system_proxy else "Proxy => Disabled / Direct"
        )
        self.logs.appendPlainText("================================")

        self.start_btn.setEnabled(False)
        self.pause_btn.setEnabled(True)
        self.resume_btn.setEnabled(False)

        self.worker = Worker(
            url=url,
            mode=mode,
            fields=fields,
            batch_size=batch_size,
            use_system_proxy=use_system_proxy,
            concurrency=self.concurrency.value(),
            refresh_existing=self.refresh_existing.isChecked(),
        )
        self.worker.log_signal.connect(self.logs.appendPlainText)
        self.worker.progress_signal.connect(self.progress.setValue)
        self.worker.done_signal.connect(self.done)
        self.worker.error_signal.connect(self.error)
        self.worker.finished.connect(self.worker_finished)
        self.worker.start()

    def pause_scraper(self):
        scraper_controller.pause()
        self.logs.appendPlainText("⏸ Pause Requested...")
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(True)

    def resume_scraper(self):
        scraper_controller.resume()
        self.logs.appendPlainText("▶ Resume Requested...")
        self.pause_btn.setEnabled(True)
        self.resume_btn.setEnabled(False)

    def done(self, path):
        self.start_btn.setEnabled(False)
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(False)
        self.progress.setValue(100)
        self.logs.appendPlainText("✅ Operation Finished")
        QMessageBox.information(self, "پایان", f"فایل ذخیره شد:\n{path}")

    def error(self, error_message):
        self.start_btn.setEnabled(False)
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(False)
        self.logs.appendPlainText(f"❌ ERROR => {error_message}")
        QMessageBox.critical(self, "خطا", error_message)


    def worker_finished(self):
        self.start_btn.setEnabled(True)
        self.pause_btn.setEnabled(False)
        self.resume_btn.setEnabled(False)

    def closeEvent(self, event):
        if hasattr(self, "worker") and self.worker.isRunning():
            QMessageBox.information(self, "استخراج فعال", "تا پایان اجرای فعلی پنجره را نبندید. نتایج دریافت‌شده در ژورنال بازیابی ذخیره می‌شوند.")
            event.ignore()
        else:
            event.accept()


if __name__ == "__main__":
    app = QApplication(sys.argv)
    window = Window()
    window.show()
    sys.exit(app.exec_())

"""Windows desktop launcher. Credentials stay in memory; only URL/project are saved."""
import json
import os
from pathlib import Path
import queue
import sys
import threading
import tkinter as tk
from tkinter import ttk, messagebox

if not getattr(sys, 'frozen', False):
    sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'panel'))
import tiam_inventory_agent as agent


class Launcher:
    def __init__(self, root):
        self.root = root
        self.thread = None
        self.stop_event = threading.Event()
        self.messages = queue.Queue()
        self.closing = False
        self.config_path = Path(os.environ.get('APPDATA', Path.home())) / 'Maarksho' / 'TiamAgent' / 'settings.json'
        try:
            settings = json.loads(self.config_path.read_text(encoding='utf-8'))
            if not isinstance(settings, dict): settings = {}
        except (OSError, ValueError):
            settings = {}
        root.title('Maarksho — Tiam Agent 1.0.0')
        root.geometry('760x560')
        root.minsize(580, 450)
        frame = ttk.Frame(root, padding=18)
        frame.pack(fill='both', expand=True)
        frame.columnconfigure(0, weight=1)
        frame.rowconfigure(8, weight=1)
        ttk.Label(frame, text='اتصال تیام به مارک‌شو', font=('Segoe UI', 17, 'bold')).grid(row=0, sticky='e', pady=(0,12))
        self.values = {}
        for row, (key, label, default) in enumerate([
            ('TIAM_PANEL_URL', 'آدرس اتصال پنل', 'https://maarksho.com/panel/tiam_inventory.php'),
            ('TIAM_PROJECT_ID', 'شناسهٔ پروژه', ''),
            ('TIAM_AGENT_TOKEN', 'توکن ایجنت همان پروژه — ذخیره نمی‌شود', ''),
        ], 1):
            ttk.Label(frame, text=label).grid(row=row*2-1, sticky='e')
            value = tk.StringVar(value=settings.get(key, default) if key != 'TIAM_AGENT_TOKEN' else '')
            self.values[key] = value
            entry = ttk.Entry(frame, textvariable=value, show='*' if key == 'TIAM_AGENT_TOKEN' else '')
            entry.grid(row=row*2, sticky='ew', pady=(3,9))
        self.status = tk.StringVar(value='از شبکه یا VPN فعلی سیستم استفاده می‌شود؛ تنظیم VPN در پنل باید خاموش باشد.')
        ttk.Label(frame, textvariable=self.status, wraplength=680).grid(row=7, sticky='e', pady=8)
        self.logs = tk.Text(frame, height=10, wrap='word', state='disabled')
        self.logs.grid(row=8, sticky='nsew')
        buttons = ttk.Frame(frame)
        buttons.grid(row=9, sticky='ew', pady=(12,0))
        self.start_button = ttk.Button(buttons, text='اتصال و شروع', command=self.start)
        self.start_button.pack(side='right')
        self.stop_button = ttk.Button(buttons, text='توقف', command=self.stop, state='disabled')
        self.stop_button.pack(side='right', padx=8)
        ttk.Button(buttons, text='کپی لاگ', command=self.copy_log).pack(side='left')
        ttk.Label(frame, text='پس از اتصال، تست SQL یا دریافت پیش‌نمایش را از پنل پروژه اجرا کنید. SQL به ODBC Driver 18 نیاز دارد.', wraplength=680).grid(row=10, sticky='e', pady=(12,0))
        root.protocol('WM_DELETE_WINDOW', self.close)
        root.after(100, self.drain)

    def emit(self, *items, **kwargs):
        self.messages.put(' '.join(str(item) for item in items))

    def start(self):
        if self.thread and self.thread.is_alive(): return
        config = {key:value.get().strip() for key,value in self.values.items()}
        try:
            parsed = agent.urllib.parse.urlsplit(config['TIAM_PANEL_URL'])
        except ValueError:
            messagebox.showerror('اطلاعات اتصال', 'آدرس پنل معتبر نیست.')
            return
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or not config['TIAM_PROJECT_ID'] or len(config['TIAM_AGENT_TOKEN']) < 32:
            messagebox.showerror('اطلاعات اتصال', 'آدرس HTTPS، شناسهٔ پروژه و توکن معتبر همان پروژه را وارد کنید.')
            return
        try:
            self.config_path.parent.mkdir(parents=True, exist_ok=True)
            self.config_path.write_text(json.dumps({key:value for key,value in config.items() if key != 'TIAM_AGENT_TOKEN'}), encoding='utf-8')
        except OSError:
            self.emit('Settings could not be saved; connection can still run.')
        self.stop_event.clear()
        self.start_button.configure(state='disabled')
        self.stop_button.configure(state='normal')
        self.status.set('در حال اتصال؛ نتیجه در لاگ نمایش داده می‌شود.')
        def run():
            try:
                agent.main([], configuration=config, stop_event=self.stop_event, log=self.emit)
            except (Exception, SystemExit) as exc:
                self.emit('Agent stopped:', type(exc).__name__)
            finally:
                config.clear()
                self.messages.put(None)
        self.thread = threading.Thread(target=run, daemon=True)
        self.thread.start()

    def stop(self):
        self.stop_event.set()
        self.stop_button.configure(state='disabled')
        self.status.set('در حال پایان درخواست جاری و ارسال نتیجه؛ سپس اتصال متوقف می‌شود.')

    def close(self):
        if self.thread and self.thread.is_alive():
            self.closing = True
            self.stop()
        else:
            self.root.destroy()

    def drain(self):
        try:
            while True:
                message = self.messages.get_nowait()
                if message is None:
                    self.start_button.configure(state='normal')
                    self.stop_button.configure(state='disabled')
                    self.status.set('اتصال متوقف شد.')
                    if self.closing:
                        self.root.destroy()
                        return
                else:
                    self.logs.configure(state='normal')
                    self.logs.insert('end', message+'\n')
                    if int(self.logs.index('end-1c').split('.')[0]) > 2000:
                        self.logs.delete('1.0', '200.0')
                    self.logs.see('end')
                    self.logs.configure(state='disabled')
        except queue.Empty:
            pass
        self.root.after(100, self.drain)

    def copy_log(self):
        self.root.clipboard_clear()
        self.root.clipboard_append(self.logs.get('1.0', 'end-1c'))


if __name__ == '__main__':
    window = tk.Tk()
    Launcher(window)
    window.mainloop()

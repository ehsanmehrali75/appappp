"""Real Tk clipboard/Entry smoke test on Windows, isolated from all SQL/panel calls."""
import os
import tempfile
import tkinter as tk
import json
from types import SimpleNamespace
from unittest.mock import patch
from launcher import Launcher

with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {'APPDATA':directory}):
    root=tk.Tk()
    app=Launcher(root)
    root.update()
    token='f'*64
    def copy(text):
        root.clipboard_clear();root.clipboard_append(text);root.update()
    copy(token)
    app.paste_token()
    assert app.values['TIAM_AGENT_TOKEN'].get()==token
    assert app.entries['TIAM_AGENT_TOKEN'].cget('show')=='*'
    app.values['TIAM_AGENT_TOKEN'].set('')
    app.entry_shortcut(SimpleNamespace(keycode=86,keysym='ر'),app.entries['TIAM_AGENT_TOKEN'])
    assert app.values['TIAM_AGENT_TOKEN'].get()==token
    profile={'version':1,'panel_url':'https://example.test/panel/tiam_inventory.php','project_id':'fixture-project','agent_token':token}
    copy(json.dumps(profile));app.paste_connection()
    assert app.values['TIAM_PROJECT_ID'].get()=='fixture-project'
    assert token not in app.logs.get('1.0','end')
    assert not app.config_path.exists()
    root.destroy()
print('Tk clipboard smoke test passed: direct token, Persian-layout shortcut, full profile and masked secret; no network used')

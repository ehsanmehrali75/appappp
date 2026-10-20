import importlib.util
from pathlib import Path
from types import SimpleNamespace
import json
import unittest

spec=importlib.util.spec_from_file_location('tiam_clipboard',Path(__file__).resolve().parents[1]/'app/tiam-agent/clipboard_input.py')
clipboard=importlib.util.module_from_spec(spec);spec.loader.exec_module(clipboard)

class ClipboardInput(unittest.TestCase):
    def test_plain_and_numeric_tokens(self):
        for token in ['a'*64,'7'*64]:
            self.assertEqual(clipboard.connection_input('  '+token+'\n'),{'agent_token':token})

    def test_full_connection_profile(self):
        profile={'version':1,'panel_url':'https://example.test/panel/tiam_inventory.php','project_id':'fixture','agent_token':'b'*64}
        self.assertEqual(clipboard.connection_input('\ufeff'+json.dumps(profile)),{k:v for k,v in profile.items() if k!='version'})

    def test_invalid_clipboards_fail_without_echoing_secrets(self):
        for text in ['', 'short', '{broken', 'a'*64+' '+ 'b'*32, '['+'a'*64+']',json.dumps({'version':1,'agent_token':'a'*64})]:
            with self.subTest(length=len(text)),self.assertRaises(ValueError):clipboard.connection_input(text)

    def test_persian_and_english_keyboard_shortcuts(self):
        self.assertTrue(clipboard.paste_shortcut(SimpleNamespace(keycode=86,keysym='ر')))
        self.assertTrue(clipboard.paste_shortcut(SimpleNamespace(keycode=55,keysym='v')))
        self.assertFalse(clipboard.paste_shortcut(SimpleNamespace(keycode=65,keysym='a')))

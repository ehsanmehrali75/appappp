"""Parse explicit user clipboard input without storing or logging its secret."""
import json


def connection_input(text):
    if not isinstance(text, str):
        raise ValueError('INVALID_CLIPBOARD')
    text = text.lstrip('\ufeff').strip()
    if text.startswith('{'):
        profile = json.loads(text)
        if not isinstance(profile, dict) or profile.get('version') != 1:
            raise ValueError('INVALID_PROFILE')
        for field in ('panel_url', 'project_id', 'agent_token'):
            if not isinstance(profile.get(field), str) or not profile[field].strip() or len(profile[field]) > 2048:
                raise ValueError('INVALID_PROFILE')
        result = {field: profile[field].strip() for field in ('panel_url','project_id','agent_token')}
    else:
        result = {'agent_token': text}
    token = result['agent_token']
    if not 32 <= len(token) <= 512 or any(c.isspace() for c in token) or any(c in token for c in '{}[]'):
        raise ValueError('INVALID_TOKEN')
    return result


def paste_shortcut(event):
    # Windows virtual-key V remains 86 when the active layout produces a Persian letter.
    return getattr(event, 'keycode', None) == 86 or str(getattr(event, 'keysym', '')).lower() == 'v'

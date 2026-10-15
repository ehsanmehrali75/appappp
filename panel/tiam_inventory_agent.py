#!/usr/bin/env python3
"""Tiam SQL bridge. Configure TIAM_PANEL_URL, TIAM_PROJECT_ID, TIAM_AGENT_TOKEN.
Requires Python 3.9+, pyodbc and Microsoft ODBC Driver 18 for SQL Server.
On Windows uses native L2TP/IPSec. On Linux uses a separately provisioned
per-project systemd VPN service; this script never installs a VPN on shared hosting.
"""
import argparse
import hashlib
import ipaddress
import json
import os
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from decimal import Decimal

MAX_ROWS = 50000
MAX_BYTES = 8 * 1024 * 1024


def dsn_value(value):
    return '{' + str(value).replace('}', '}}') + '}'


def identifier(value):
    if not isinstance(value, str) or not value or len(value) > 128 or not (value[0].isalpha() or value[0] == '_') or not all(c.isalnum() or c in '_ ' for c in value):
        raise ValueError('INVALID_SQL_IDENTIFIER')
    return '[' + value + ']'


def stage(logs, name, callback, message):
    start = time.monotonic()
    try:
        result = callback()
        logs.append({'stage': name, 'status': 'success', 'message': message, 'elapsed_ms': round((time.monotonic() - start) * 1000)})
        return result
    except Exception as exc:
        # Never return driver errors, DSNs, process output or credential values.
        code = str(exc.args[0]) if exc.args and re.fullmatch(r'[A-Z0-9_]{3,64}', str(exc.args[0])) else type(exc).__name__
        logs.append({'stage': name, 'status': 'error', 'message': 'Stage failed: ' + code, 'elapsed_ms': round((time.monotonic() - start) * 1000)})
        raise


def connect_vpn(cfg, project):
    if not cfg.get('vpn_enabled'):
        return
    if os.name == 'nt':
        if not all(cfg.get(k) for k in ('vpn_server', 'vpn_username', 'vpn_password', 'vpn_secret')):
            raise ValueError('MISSING_VPN_CREDENTIALS')
        profile = 'Maarksho-Tiam-' + hashlib.sha256(project.encode()).hexdigest()[:12]
        # JSON over stdin keeps secrets out of the PowerShell command text.
        script = r'''
$c = [Console]::In.ReadToEnd() | ConvertFrom-Json
$ErrorActionPreference = 'Stop'
try {
    $existing = Get-VpnConnection -Name $c.profile -ErrorAction SilentlyContinue
    if ($existing -and $existing.ConnectionStatus -eq 'Connected') { & rasdial $c.profile /disconnect | Out-Null }
    if ($existing) { Remove-VpnConnection -Name $c.profile -Force }
    Add-VpnConnection -Name $c.profile -ServerAddress $c.vpn_server -TunnelType L2tp -L2tpPsk $c.vpn_secret -AuthenticationMethod MSChapv2 -SplitTunneling -Force | Out-Null
    Add-VpnConnectionRoute -ConnectionName $c.profile -DestinationPrefix ($c.host + '/32') | Out-Null
} catch { exit 1 }
'''
        payload = {k: cfg.get(k, '') for k in ('vpn_server', 'vpn_secret', 'host')}
        ipaddress.IPv4Address(cfg['host'])
        payload['profile'] = profile
        cmd = ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', script]
        result = subprocess.run(cmd, input=json.dumps(payload), text=True, capture_output=True, timeout=60)
        if result.returncode:
            raise RuntimeError('VPN_CONFIGURATION_FAILED')
        result = subprocess.run(['rasdial', profile, cfg['vpn_username'], cfg['vpn_password']], capture_output=True, timeout=60)
        if result.returncode:
            raise RuntimeError('VPN_AUTHENTICATION_FAILED')
    else:
        service = cfg.get('vpn_service', '')
        if not re.fullmatch(r'maarksho-tiam-vpn@[A-Za-z0-9_-]+\.service', service):
            raise ValueError('VPN_SERVICE_NOT_CONFIGURED')
        # Run as a service account with permission for this exact service only.
        result = subprocess.run(['sudo', '-n', 'systemctl', 'start', service], capture_output=True, timeout=60)
        if result.returncode:
            raise RuntimeError('VPN_SERVICE_START_FAILED')
        result = subprocess.run(['systemctl', 'is-active', '--quiet', service], capture_output=True, timeout=10)
        if result.returncode:
            raise RuntimeError('VPN_SERVICE_NOT_ACTIVE')


def read_view(cfg, sample=False):
    import pyodbc
    security = cfg.get('sql_security', 'verified')
    if security not in ('verified', 'compatibility'):
        raise ValueError('INVALID_SQL_SECURITY_MODE')
    compatibility = security == 'compatibility'
    target = identifier(cfg.get('schema', 'dbo')) + '.' + identifier(cfg['view'])
    attrs = {'DRIVER': 'ODBC Driver 18 for SQL Server', 'SERVER': str(cfg['host']) + ',' + str(int(cfg.get('port', 1433))),
             'DATABASE': cfg['database'], 'UID': cfg['username'], 'PWD': cfg['password'],
             'Encrypt': 'no' if compatibility else 'yes', 'TrustServerCertificate': 'yes' if compatibility else 'no', 'APP': 'MaarkshoTiamInventory'}
    if not compatibility and cfg.get('certificate_hostname'):
        attrs['HostNameInCertificate'] = cfg['certificate_hostname']
    dsn = ';'.join(k + '=' + dsn_value(v) for k, v in attrs.items())
    with pyodbc.connect(dsn, timeout=15, autocommit=True) as conn:
        cursor = conn.cursor()
        cursor.timeout = 60
        # A single SELECT against the configured object. No free-form SQL accepted.
        cursor.execute('SELECT TOP (' + str(5 if sample else MAX_ROWS + 1) + ') * FROM ' + target)
        columns = [c[0] for c in cursor.description]
        if len(set(columns)) != len(columns):
            raise ValueError('DUPLICATE_VIEW_COLUMNS')
        rows = []
        for row in cursor:
            values = {}
            for key, value in zip(columns, row):
                if isinstance(value, Decimal):
                    value = str(value)
                elif value is not None and not isinstance(value, (str, bool, int, float)):
                    value = str(value)
                values[key] = value
            rows.append(values)
            if len(rows) > MAX_ROWS:
                raise ValueError('ROW_LIMIT_EXCEEDED')
        return columns, rows


def execute_job(job, project):
    cfg = job['config']
    logs = []
    try:
        def validate():
            required = ['host'] if job['kind'] == 'vpn_test' else ['host', 'database', 'view', 'username', 'password']
            if not all(cfg.get(k) for k in required):
                raise ValueError('MISSING_CONNECTION_FIELDS')
        stage(logs, 'configuration', validate, 'Required configuration fields present; credentials are not logged')
        if cfg.get('vpn_enabled'):
            stage(logs, 'vpn', lambda: connect_vpn(cfg, project), 'VPN client connection/service completed; TCP route is checked next')
        else:
            logs.append({'stage': 'vpn', 'status': 'skipped', 'message': 'Using current system network (LAN or existing VPN); agent does not change VPN connections', 'elapsed_ms': 0})
        def probe():
            with socket.create_connection((cfg['host'], int(cfg.get('port', 1433))), timeout=10):
                pass
        stage(logs, 'network', probe, 'SQL Server TCP port reachable')
        if job['kind'] == 'vpn_test':
            return {'success': True, 'logs': logs, 'rows': [], 'columns': []}
        columns, rows = stage(logs, 'sql_view', lambda: read_view(cfg, job['kind'] == 'view_test'),
                              'SQL authentication and view SELECT completed (security mode: ' + cfg.get('sql_security', 'verified') + ')')
        logs.append({'stage': 'schema', 'status': 'success', 'message': str(len(columns)) + ' columns; ' + str(len(rows)) + ' rows received', 'elapsed_ms': 0})
        return {'success': True, 'logs': logs, 'columns': columns, 'rows': rows}
    except Exception:
        return {'success': False, 'logs': logs, 'columns': [], 'rows': []}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Authenticated endpoint must not redirect')


def request(url, project, token, action, **data):
    payload = json.dumps(dict(data, project=project, action=action), ensure_ascii=False, allow_nan=False).encode('utf-8')
    if len(payload) > MAX_BYTES:
        raise ValueError('Payload exceeds server limit')
    req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json', 'X-Tiam-Token': token})
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=45) as response:
        result = json.load(response)
    if result.get('error'):
        raise RuntimeError('Panel rejected request')
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true', help='Poll once; can be called by Task Scheduler/cron')
    args = parser.parse_args()
    url, project, token = (os.environ.get(k, '') for k in ('TIAM_PANEL_URL', 'TIAM_PROJECT_ID', 'TIAM_AGENT_TOKEN'))
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or len(token) < 32 or not project:
        raise SystemExit('Configure an HTTPS TIAM_PANEL_URL, TIAM_PROJECT_ID and TIAM_AGENT_TOKEN in the service environment')
    print('Agent started; checking panel connection...', flush=True)
    last_idle_log = None
    while True:
        try:
            job = request(url, project, token, 'agent_poll')
            if job.get('status') == 'job':
                print(time.strftime('%Y-%m-%d %H:%M:%S'), 'job received; executing', flush=True)
                last_idle_log = None
                result = execute_job(job, project)
                try:
                    reply = request(url, project, token, 'agent_result', job=job['job'], **result)
                except ValueError:
                    reply = request(url, project, token, 'agent_result', job=job['job'], success=False,
                            logs=[{'stage': 'upload', 'status': 'error', 'message': 'Payload too large; no changes applied'}])
                print(time.strftime('%Y-%m-%d %H:%M:%S'), 'job finished:', 'ok' if result['success'] and reply.get('state') != 'error' else 'failed', flush=True)
            elif job.get('status') == 'idle':
                now = time.monotonic()
                if last_idle_log is None or now-last_idle_log >= 300:
                    print(time.strftime('%Y-%m-%d %H:%M:%S'), 'panel connected; waiting for a queued job', flush=True)
                    last_idle_log = now
            else:
                print('Panel returned an unexpected status; check endpoint configuration', flush=True)
        except urllib.error.HTTPError as exc:
            hints = {401: 'check saved agent token', 404: 'check endpoint and project ID', 405: 'check endpoint URL', 403: 'check host firewall or access rules'}
            print(time.strftime('%Y-%m-%d %H:%M:%S'), 'panel HTTP', exc.code, hints.get(exc.code, 'check panel/server status'), flush=True)
        except Exception as exc:
            # Printing exception text may leak headers, credentials or driver details.
            print(time.strftime('%Y-%m-%d %H:%M:%S'), 'connection failed:', type(exc).__name__, flush=True)
        if args.once:
            break
        time.sleep(30)


if __name__ == '__main__':
    main()

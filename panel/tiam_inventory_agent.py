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


class PayloadError(ValueError):
    """The local result cannot be sent within the endpoint's JSON contract."""


class InvalidPanelResponse(RuntimeError):
    pass


def dsn_value(value):
    return '{' + str(value).replace('}', '}}') + '}'


def identifier(value):
    if not isinstance(value, str) or not value or len(value) > 128 or not (value[0].isalpha() or value[0] == '_') or not all(c.isalnum() or c in '_ ' for c in value):
        raise ValueError('INVALID_SQL_IDENTIFIER')
    return '[' + value + ']'


def sql_diagnostics(exc):
    """Return fixed categories/codes only; never emit driver text or a connection string."""
    details = ' '.join(str(arg) for arg in exc.args).lower()
    rules = [
        ('certificate_untrusted', ('certificate chain was issued by an authority that is not trusted', 'certificate verify failed', 'self-signed certificate', 'untrusted root', '-2146893019'), 'SQL server certificate is not trusted; verify the saved security mode or install the trusted certificate.'),
        ('certificate_hostname', ('target principal name is incorrect', 'hostname mismatch', 'certificate subject name', '-2146893022'), 'The server name does not match its certificate.'),
        ('invalid_connection_attribute', ('invalid value specified for connection string attribute', 'invalid connection string attribute', 'invalid attribute value'), 'The ODBC driver rejected a connection attribute; inspect the selected driver and security mode.'),
        ('tls_handshake', ('ssl provider', 'tls', 'handshake', 'unsupported protocol', 'no common algorithm'), 'SQL TLS negotiation failed; check SQL Server TLS support and Windows/ODBC updates.'),
        ('login_failed', ('login failed', '18456'), 'SQL login was rejected; check the SQL account and authentication mode.'),
        ('database_access', ('cannot open database', '4060'), 'SQL login cannot open the configured database.'),
        ('driver_missing', ('data source name not found', 'no default driver specified', 'im002'), 'Microsoft ODBC Driver 18 for SQL Server is missing or unavailable to this application.'),
        ('sql_object_missing', ('invalid object name', '42s02'), 'The configured schema or view does not exist or is not accessible.'),
        ('sql_permission', ('permission was denied', 'permission denied'), 'The SQL account does not have permission to read the configured view.'),
        ('connection_timeout', ('timeout expired', 'hyt00', 'hyt01'), 'SQL connection or query timed out.'),
        ('connection_reset', ('forcibly closed', '10054', 'connection reset'), 'SQL Server or the network reset the SQL handshake connection.'),
    ]
    category, hint = 'sql_connection_failed', 'SQL connection failed; use the fixed diagnostic codes and compare with the direct PowerShell SQL diagnostic.'
    for name, patterns, message in rules:
        if any(pattern in details for pattern in patterns):
            category, hint = name, message
            break
    codes = [code for code in ('-2146893019', '-2146893022', '10054', '10060', '10061', '18456', '4060') if re.search(r'(?<![0-9])'+re.escape(code)+r'(?![0-9])', details)]
    return {'category': category, 'hint': hint, 'native_codes': codes}


def stage(logs, name, callback, message):
    start = time.monotonic()
    try:
        result = callback()
        logs.append({'stage': name, 'status': 'success', 'message': message, 'elapsed_ms': round((time.monotonic() - start) * 1000)})
        return result
    except Exception as exc:
        # Never return driver errors, DSNs, process output or credential values.
        safe_codes = {'INVALID_SQL_IDENTIFIER', 'INVALID_SQL_SECURITY_MODE', 'DUPLICATE_VIEW_COLUMNS', 'ROW_LIMIT_EXCEEDED', 'MISSING_CONNECTION_FIELDS', 'VPN_CONFIGURATION_FAILED'}
        first = str(exc.args[0]) if exc.args else ''
        code = first if first in safe_codes or re.fullmatch(r'(?:[0-9]{2}|HY|IM)[A-Z0-9]{3}', first) else type(exc).__name__
        entry = {'stage': name, 'status': 'error', 'message': 'Stage failed: ' + code, 'elapsed_ms': round((time.monotonic() - start) * 1000)}
        if name == 'sql_view':
            entry['diagnostic'] = sql_diagnostics(exc)
        logs.append(entry)
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
        logs.append({'stage': 'sql_settings', 'status': 'info', 'message': 'Agent 1.0.1; driver: ODBC Driver 18 for SQL Server; security mode: ' + ('compatibility' if cfg.get('sql_security', 'verified') == 'compatibility' else 'verified')})
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
    try:
        payload = json.dumps(dict(data, project=project, action=action), ensure_ascii=False, allow_nan=False).encode('utf-8')
    except (TypeError, ValueError) as exc:
        raise PayloadError('Result is not valid JSON') from exc
    if len(payload) > MAX_BYTES:
        raise PayloadError('Payload exceeds server limit')
    req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json', 'X-Tiam-Token': token})
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=45) as response:
        try:
            result = json.load(response)
        except (ValueError, UnicodeDecodeError) as exc:
            raise InvalidPanelResponse('Panel returned invalid JSON') from exc
    if not isinstance(result, dict):
        raise InvalidPanelResponse('Panel returned an unexpected response')
    if result.get('error'):
        raise RuntimeError('Panel rejected request')
    return result


def submit_result(url, project, token, job_id, result):
    """Retry the same result, never another SQL SELECT, after transport failures."""
    payload = result
    for attempt in range(3):
        try:
            try:
                reply = request(url, project, token, 'agent_result', job=job_id, **payload)
            except PayloadError:
                payload = {'success': False, 'logs': [{'stage': 'upload', 'status': 'error',
                    'message': 'Result exceeds JSON/server limits; no changes applied'}]}
                reply = request(url, project, token, 'agent_result', job=job_id, **payload)
            if reply.get('status') != 'received' or reply.get('state') not in ('done', 'error', 'preview', 'applied'):
                raise InvalidPanelResponse('Panel did not acknowledge the result')
            return reply
        except urllib.error.HTTPError as exc:
            if exc.code < 500 and exc.code not in (408, 429):
                raise
            if attempt == 2:
                raise
        except (OSError, InvalidPanelResponse):
            if attempt == 2:
                raise
        time.sleep(2 ** (attempt + 1))


def main(argv=None, configuration=None, stop_event=None, log=print):
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true', help='Poll once; can be called by Task Scheduler/cron')
    args = parser.parse_args(argv)
    configuration = os.environ if configuration is None else configuration
    url, project, token = (configuration.get(k, '') for k in ('TIAM_PANEL_URL', 'TIAM_PROJECT_ID', 'TIAM_AGENT_TOKEN'))
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or len(token) < 32 or not project:
        raise SystemExit('Configure an HTTPS TIAM_PANEL_URL, TIAM_PROJECT_ID and TIAM_AGENT_TOKEN in the service environment')
    log('Agent started; checking panel connection...', flush=True)
    last_idle_log = None
    pending = None
    while True:
        try:
            if pending is None:
                job = request(url, project, token, 'agent_poll')
                if job.get('status') == 'job':
                    log(time.strftime('%Y-%m-%d %H:%M:%S'), 'job received; executing', flush=True)
                    last_idle_log = None
                    pending = (job['job'], execute_job(job, project), time.monotonic() + 900)
                elif job.get('status') == 'idle':
                    now = time.monotonic()
                    if last_idle_log is None or now-last_idle_log >= 300:
                        log(time.strftime('%Y-%m-%d %H:%M:%S'), 'panel connected; waiting for a queued job', flush=True)
                        last_idle_log = now
                else:
                    log('Panel returned an unexpected status; check endpoint configuration', flush=True)
            if pending is not None:
                job_id, result, deadline = pending
                if time.monotonic() >= deadline:
                    log('Result delivery expired; create a new job in the panel', flush=True)
                    pending = None
                else:
                    reply = submit_result(url, project, token, job_id, result)
                    log(time.strftime('%Y-%m-%d %H:%M:%S'), 'job finished:', 'ok' if result['success'] and reply.get('state') != 'error' else 'failed', flush=True)
                    pending = None
        except urllib.error.HTTPError as exc:
            if exc.code < 500 and exc.code not in (408, 429):
                pending = None  # Cancelled/expired jobs and invalid credentials are not retried.
            hints = {401: 'check saved agent token', 404: 'check endpoint and project ID', 405: 'check endpoint URL', 403: 'check host firewall or access rules'}
            log(time.strftime('%Y-%m-%d %H:%M:%S'), 'panel HTTP', exc.code, hints.get(exc.code, 'check panel/server status'), flush=True)
        except Exception as exc:
            # Printing exception text may leak headers, credentials or driver details.
            log(time.strftime('%Y-%m-%d %H:%M:%S'), 'connection failed:', type(exc).__name__, flush=True)
        if pending is not None:
            log('Result delivery pending; retrying the saved result without querying SQL again', flush=True)
        if args.once:
            break
        if stop_event is not None:
            if stop_event.is_set() and pending is None:
                break
            # Finish delivery of an already queried result before stopping.
            if stop_event.is_set():
                time.sleep(2)
            else:
                stop_event.wait(30)
        else:
            time.sleep(30)


if __name__ == '__main__':
    main()

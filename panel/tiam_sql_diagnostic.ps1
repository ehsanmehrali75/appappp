# Read-only SQL diagnostic for Windows PowerShell 5.1+; uses your current network.
[CmdletBinding()]
param(
    [string]$Server = '192.168.2.215',
    [ValidateRange(1,65535)][int]$Port = 1433,
    [string]$Database = 'SplandDB',
    [string]$Username = 'ReportUser',
    [ValidatePattern('^[\p{L}_][\p{L}\p{N}_ ]{0,127}$')][string]$Schema = 'dbo',
    [ValidatePattern('^[\p{L}_][\p{L}\p{N}_ ]{0,127}$')][string]$View = 'Factinventory',
    [switch]$IncludeSample,
    [string]$OutputPath = (Join-Path (Get-Location) ('tiam-sql-report-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.json'))
)
$ErrorActionPreference = 'Stop'
$report = [ordered]@{
    diagnostic_version = 2
    generated_at = [DateTimeOffset]::Now.ToString('o')
    server = $Server; port = $Port; database = $Database; schema = $Schema; object = $View
    network_mode = 'existing_system_network'; vpn_changed = $false
    sample_requested = [bool]$IncludeSample
    success = $false; stages = @(); visible_objects = @(); columns = @(); sample = @()
}
$connection = $null; $reader = $null; $command = $null; $tcp = $null; $password = $null
$stage = 'runtime'
$failed = $false
function Convert-SampleValue($value) {
    if ($value -is [DBNull]) { return $null }
    if ($value -is [byte[]]) { return '[binary omitted]' }
    if ($value -is [DateTime] -or $value -is [DateTimeOffset]) { return $value.ToString('o') }
    if ($value -is [string] -and $value.Length -gt 200) { return $value.Substring(0,200) + ' [truncated]' }
    return $value
}
function New-DiagnosticConnectionString {
    param([string]$Server,[int]$Port,[string]$Database)
    $builder = [System.Data.SqlClient.SqlConnectionStringBuilder]::new()
    # PowerShell adapts this IDictionary: use SQL keywords, not CLR property names.
    $builder['Data Source'] = 'tcp:' + $Server + ',' + $Port
    $builder['Initial Catalog'] = $Database
    $builder['Encrypt'] = $true
    $builder['TrustServerCertificate'] = $false
    $builder['Connect Timeout'] = 15
    $builder['Application Name'] = 'MaarkshoReadOnlyDiagnostic'
    return $builder.ConnectionString
}
try {
    if ($env:OS -ne 'Windows_NT') { throw [PlatformNotSupportedException]::new('Run this tool on Windows PowerShell.') }
    if ([string]::IsNullOrWhiteSpace($Server) -or [string]::IsNullOrWhiteSpace($Database) -or [string]::IsNullOrWhiteSpace($Username)) { throw [ArgumentException]::new('Connection fields are required.') }
    Add-Type -AssemblyName System.Data
    $stage = 'network'
    Write-Host 'Checking SQL TCP port using the current LAN/VPN connection...'
    $tcp = [System.Net.Sockets.TcpClient]::new()
    $pending = $tcp.BeginConnect($Server,$Port,$null,$null)
    try {
        if (-not $pending.AsyncWaitHandle.WaitOne(5000)) { throw [TimeoutException]::new('SQL TCP timeout') }
        $tcp.EndConnect($pending)
    } finally { $pending.AsyncWaitHandle.Close(); $tcp.Close(); $tcp = $null }
    $report.stages += @{stage=$stage;status='success';message='TCP port reachable; SQL authentication is not yet verified'}
    Write-Host 'TCP port reachable. Enter the SQL login password (not your VPN or panel password).'
    $stage = 'sql_connection'
    $password = Read-Host 'SQL password' -AsSecureString
    $password.MakeReadOnly()
    $stage = 'sql_parameters'
    $connectionString = New-DiagnosticConnectionString -Server $Server -Port $Port -Database $Database
    $stage = 'sql_credentials'
    $connection = [System.Data.SqlClient.SqlConnection]::new($connectionString)
    $connection.Credential = [System.Data.SqlClient.SqlCredential]::new($Username,$password)
    $stage = 'sql_connection'
    $connection.Open()
    $report.stages += @{stage=$stage;status='success';message='Encrypted SQL connection and login verified'}
    $stage = 'object_catalog'
    $command = $connection.CreateCommand(); $command.CommandTimeout = 30
    # SQL Server metadata visibility limits this list to objects visible to this login.
    $command.CommandText = "SELECT TOP (500) s.name AS schema_name, o.name AS object_name, o.type_desc AS object_type FROM sys.objects o INNER JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE o.type IN ('U','V') AND o.is_ms_shipped=0 ORDER BY s.name,o.name"
    try {
        $reader = $command.ExecuteReader()
        while ($reader.Read()) {
            $report.visible_objects += @{schema=$reader.GetString(0);name=$reader.GetString(1);type=$reader.GetString(2)}
        }
        $report.stages += @{stage=$stage;status='success';message='Visible table/view names collected; list capped at 500'}
    } catch {
        # Catalog permissions must not prevent testing SELECT on the requested view.
        $report.stages += @{stage=$stage;status='skipped';message='Metadata catalog unavailable; selected object will still be tested'}
    } finally { if ($reader) { $reader.Dispose(); $reader=$null }; $command.Dispose(); $command=$null }
    $stage = 'view_schema'
    $command = $connection.CreateCommand(); $command.CommandTimeout = 60
    $target = '[' + $Schema + '].[' + $View + ']'
    $command.CommandText = $(if ($IncludeSample) { 'SELECT TOP (5) * FROM ' + $target } else { 'SELECT TOP (0) * FROM ' + $target })
    $reader = $command.ExecuteReader()
    $metadata = $reader.GetSchemaTable()
    for ($i=0; $i -lt $reader.FieldCount; $i++) {
        $column = [ordered]@{ordinal=$i+1;name=$reader.GetName($i);sql_type=$reader.GetDataTypeName($i);dotnet_type=$reader.GetFieldType($i).FullName}
        if ($metadata -and $metadata.Rows.Count -gt $i) {
            foreach ($property in @('AllowDBNull','ColumnSize','NumericPrecision','NumericScale')) {
                if ($metadata.Columns.Contains($property)) { $column[$property]=Convert-SampleValue $metadata.Rows[$i][$property] }
            }
        }
        $report.columns += $column
    }
    while ($IncludeSample -and $reader.Read()) {
        $row = [ordered]@{}
        for ($i=0; $i -lt $reader.FieldCount; $i++) {
            # Ordinal prefix also preserves duplicate SELECT column names in diagnostics.
            $row[([string]($i+1) + ':' + $reader.GetName($i))] = Convert-SampleValue $reader.GetValue($i)
        }
        $report.sample += $row
    }
    $report.stages += @{stage=$stage;status='success';message='Selected object SELECT and column types verified'}
    $report.success = $true
    Write-Host ('SQL inspection succeeded: ' + $report.columns.Count + ' columns. No data was changed.')
} catch {
    $failed = $true
    $errorInfo = [ordered]@{stage=$stage;status='error';exception_type=$_.Exception.GetType().FullName;hresult=$_.Exception.HResult;sql_numbers=@();causes=@()}
    $exception = $_.Exception
    while ($exception) {
        $errorInfo.causes += @{type=$exception.GetType().FullName;hresult=$exception.HResult}
        if ($exception.GetType().FullName -eq 'System.Data.SqlClient.SqlException') {
            foreach ($sqlError in $exception.Errors) { $errorInfo.sql_numbers += @{number=$sqlError.Number;state=$sqlError.State;class=$sqlError.Class} }
        }
        $exception = $exception.InnerException
    }
    # No raw exception text or connection string: passwords are never serialized.
    $errorInfo['message'] = 'Inspection failed at this stage. Use SQL error numbers and exception type for diagnosis.'
    $report.stages += $errorInfo
    Write-Host ('FAILED at ' + $stage + '. Safe error codes will be saved to the report.')
} finally {
    if ($reader) { $reader.Dispose() }
    if ($command) { $command.Dispose() }
    if ($connection) { $connection.Dispose() }
    if ($tcp) { $tcp.Close() }
    if ($password) { $password.Dispose() }
    $json = $report | ConvertTo-Json -Depth 12
    [System.IO.File]::WriteAllText($ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutputPath),$json,[System.Text.UTF8Encoding]::new($false))
    Write-Host ('Report saved: ' + $OutputPath)
}
if ($failed) { exit 1 }

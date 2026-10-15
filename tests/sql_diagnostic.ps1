$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot '../panel/tiam_sql_diagnostic.ps1'
$tokens=$null; $parseErrors=$null
$ast=[System.Management.Automation.Language.Parser]::ParseFile($scriptPath,[ref]$tokens,[ref]$parseErrors)
if($parseErrors.Count) { throw 'Diagnostic script has parse errors' }
$helper=$ast.Find({param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'Convert-SampleValue'},$true)
. ([scriptblock]::Create($helper.Extent.Text))
if($null -ne (Convert-SampleValue ([DBNull]::Value))) { throw 'SQL NULL must remain JSON null' }
if((Convert-SampleValue 0) -ne 0) { throw 'Zero stock must be preserved' }
if((Convert-SampleValue ([byte[]]@(1,2))) -ne '[binary omitted]') { throw 'Binary must not be exported' }
if((Convert-SampleValue ('x'*250)).Length -gt 220) { throw 'Large text must be bounded' }
if((Convert-SampleValue ([datetime]'2026-10-15T12:30:00')) -notmatch '^2026-10-15T12:30:00') { throw 'Dates must be inspectable ISO strings' }
Write-Host 'PowerShell parsing and five report-value checks passed'

Add-Type -AssemblyName System.Data
$builderHelper=$ast.Find({param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq 'New-DiagnosticConnectionString'},$true)
. ([scriptblock]::Create($builderHelper.Extent.Text))
$actual=New-DiagnosticConnectionString -Server '192.0.2.1' -Port 1433 -Database 'Example;DB'
$parsed=[System.Data.SqlClient.SqlConnectionStringBuilder]::new($actual)
if($parsed['Data Source'] -ne 'tcp:192.0.2.1,1433') { throw 'SQL data source must use a supported keyword' }
if($parsed['Initial Catalog'] -ne 'Example;DB') { throw 'Database values must remain escaped, not interpreted as connection options' }
if(-not $parsed['Encrypt'] -or $parsed['TrustServerCertificate']) { throw 'TLS certificate verification must remain enabled' }
Write-Host 'Three real SqlClient connection-string regression checks passed; no SQL server contacted'

$compat=New-DiagnosticConnectionString -Server '192.0.2.1' -Port 1433 -Database 'ExampleDB' -AllowInsecureConnection
$parsedCompat=[System.Data.SqlClient.SqlConnectionStringBuilder]::new($compat)
if($parsedCompat['Encrypt'] -or -not $parsedCompat['TrustServerCertificate']) { throw 'Explicit compatibility mode must disable required encryption and certificate verification' }
Write-Host 'Explicit SQL compatibility mode passed'

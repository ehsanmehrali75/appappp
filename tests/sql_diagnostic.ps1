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

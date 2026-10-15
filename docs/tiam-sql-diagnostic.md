# Inspect the real SQL structure from Windows

Download `/panel/tiam_sql_diagnostic.ps1` from the Tiam settings section (or GitHub). Run it on the Windows computer that already reaches SQL Server using the office LAN or a VPN you connected yourself. The tool does not manage VPN, need administrator rights, use the web queue or require Python/pyodbc.

In the folder containing the downloaded file:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tiam_sql_diagnostic.ps1 -Server "192.168.2.215" -Database "SplandDB" -Username "ReportUser" -Schema "dbo" -View "Factinventory"
```

The execution-policy option applies only to this PowerShell process. Parameters are examples for the supplied SQL connection; use each project's actual connection details. The script securely prompts for the SQL password, which must not be placed in the command or report.

The default run checks TCP accessibility, opens a certificate-verified encrypted SQL connection, lists up to 500 tables/views visible to that login, and performs `SELECT TOP (0)` on the configured object to obtain actual column names, SQL/.NET types, nullability, sizes and numeric precision/scale. Only fixed SELECT queries execute. No products or database records are changed, and no report is uploaded automatically. Metadata catalog access failure does not prevent testing the specified view.

A timestamped `tiam-sql-report-*.json` file is saved in the current folder even for connection failures. It contains stage names, safe exception types/HRESULT and SQL error numbers, with no password or connection string. Use this report to diagnose network vs TLS/login/permissions/object errors and implement field mappings based on actual metadata. The default contains no product row data.

If a few actual sample rows are needed and may be shared, explicitly add `-IncludeSample`. This reads at most five rows, omits binary content and truncates long strings to 200 characters. Review that report before sharing it. Each sample property is prefixed with its column ordinal to preserve duplicate names; actual names are also in `columns`.

TLS verification remains enabled. For certificate errors, use a reachable SQL hostname matching the server certificate and the trusted issuing CA on Windows; the tool does not bypass trust verification.

Validation in this task: PowerShell 7 parser, five value-conversion checks, expected structured rejection on Linux, and PHP syntax for the download link. A Windows PowerShell 5.1/SQL Server connection cannot be exercised from this Linux environment or establish connectivity to a private `192.168.*` server. Run the tool on the user's Windows machine and inspect the actual report before changing production mappings. Tests: `pwsh -NoProfile -File tests/sql_diagnostic.ps1`.

Version 2 fixes a reproduced PowerShell IDictionary adapter issue: `$builder.DataSource = ...` was interpreted as an unsupported connection-string key and threw `ArgumentException` before `Open()`. The builder now uses canonical SQL keywords (`Data Source`, `Initial Catalog`, etc.) through indexers. Real SqlClient regression checks verify the endpoint, escaped database values, encryption and certificate validation without contacting SQL. Failure stages distinguish `sql_parameters`, `sql_credentials` and the actual `sql_connection`. A version 1 report with TCP success and this ArgumentException does not establish a SQL authentication failure; rerun the version 2 script before diagnosing credentials or certificates.

param(
    [Parameter(Mandatory=$true)][string]$InputPath,
    [Parameter(Mandatory=$true)][string]$TemplatePath,
    [Parameter(Mandatory=$true)][string]$OutputPath
)
$ErrorActionPreference = 'Stop'
$connection = $null
$transaction = $null
try {
    $payload = Get-Content -LiteralPath $InputPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $allowed = @('SAI_Control','InstFeeders','Node','InstSection','InstPrimaryTransformers','Loads','SurveyHeader','SurveyRows','SurveySolar')
    if (Test-Path -LiteralPath $OutputPath) { throw 'Output file already exists.' }
    Copy-Item -LiteralPath $TemplatePath -Destination $OutputPath
    $connection = New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$OutputPath")
    $connection.Open()
    # Refuse a template containing any survey or model data.
    foreach ($table in $connection.GetSchema('Tables').Rows) {
        if ($table.TABLE_TYPE -ne 'TABLE') { continue }
        $command = $connection.CreateCommand()
        $command.CommandText = "SELECT COUNT(*) FROM [$($table.TABLE_NAME)]"
        if ([int]$command.ExecuteScalar() -ne 0) { throw 'MDB template must contain schema only.' }
        $command.Dispose()
    }
    foreach ($sql in @('CREATE TABLE SurveyHeader (SourceJson MEMO)', 'CREATE TABLE SurveyRows (RowNumber LONG, NodeId TEXT(255), SourceJson MEMO)', 'CREATE TABLE SurveySolar (SourceJson MEMO)')) {
        $command = $connection.CreateCommand(); $command.CommandText = $sql
        [void]$command.ExecuteNonQuery(); $command.Dispose()
    }
    $transaction = $connection.BeginTransaction()
    foreach ($table in $payload.tables.PSObject.Properties) {
        if ($allowed -notcontains $table.Name) { throw 'Unsupported export table.' }
        foreach ($row in $table.Value) {
            $command = $connection.CreateCommand(); $command.Transaction = $transaction
            $columns = @(); $placeholders = @()
            foreach ($column in $row.PSObject.Properties) {
                if ($column.Name -notmatch '^[A-Za-z][A-Za-z0-9_]*$') { throw 'Invalid column name.' }
                $columns += "[$($column.Name)]"; $placeholders += '?'
                $parameter = New-Object System.Data.OleDb.OleDbParameter
                $value = $column.Value
                if ($null -eq $value) { $parameter.OleDbType = [System.Data.OleDb.OleDbType]::VarWChar; $parameter.Value = [DBNull]::Value }
                elseif ($value -is [string]) { $parameter.OleDbType = [System.Data.OleDb.OleDbType]::LongVarWChar; $parameter.Value = $value }
                elseif ($value -is [bool]) { $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Integer; $parameter.Value = [int]$value }
                elseif ($value -is [int] -or $value -is [long]) { $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Integer; $parameter.Value = [int]$value }
                else { $parameter.OleDbType = [System.Data.OleDb.OleDbType]::Double; $parameter.Value = [double]$value }
                [void]$command.Parameters.Add($parameter)
            }
            $command.CommandText = "INSERT INTO [$($table.Name)] ($($columns -join ',')) VALUES ($($placeholders -join ','))"
            [void]$command.ExecuteNonQuery(); $command.Dispose()
        }
    }
    $transaction.Commit(); $transaction = $null
    # Verify the actual database row counts before returning success.
    foreach ($table in $payload.tables.PSObject.Properties) {
        $command = $connection.CreateCommand(); $command.CommandText = "SELECT COUNT(*) FROM [$($table.Name)]"
        if ([int]$command.ExecuteScalar() -ne @($table.Value).Count) { throw 'MDB row count verification failed.' }
        $command.Dispose()
    }
    $connection.Close(); $connection.Dispose(); $connection = $null
    Write-Output '{"status":"ok"}'
    exit 0
} catch {
    if ($transaction) { $transaction.Rollback() }
    if ($connection) { $connection.Close(); $connection.Dispose() }
    if (Test-Path -LiteralPath $OutputPath) { Remove-Item -LiteralPath $OutputPath -Force }
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}

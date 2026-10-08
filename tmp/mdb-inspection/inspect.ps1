$connection = New-Object System.Data.OleDb.OleDbConnection
$connection.ConnectionString = 'Provider=Microsoft.ACE.OLEDB.12.0;Data Source=W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data\Noor PurT-4221215879.mdb;Mode=Read;'
$connection.Open()
$tables = $connection.GetSchema('Tables')
foreach ($table in $tables.Rows) {
 if ($table.TABLE_TYPE -ne 'TABLE') { continue }
 $name = $table.TABLE_NAME
 Write-Output "TABLE: $name"
 $command = $connection.CreateCommand()
 $command.CommandText = "SELECT TOP 2 * FROM [$name]"
 $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command)
 $data = New-Object System.Data.DataTable
 [void]$adapter.Fill($data)
 foreach ($col in $data.Columns) { Write-Output "$($col.ColumnName): $($col.DataType.Name)" }
 foreach ($row in $data.Rows) { $values = [ordered]@{}; foreach ($col in $data.Columns) { $values[$col.ColumnName] = if ($row[$col] -is [DBNull]) { $null } else { $row[$col] } }; $values | ConvertTo-Json -Compress -Depth 3 }
}
$connection.Close()

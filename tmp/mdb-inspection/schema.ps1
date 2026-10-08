$connection = New-Object System.Data.OleDb.OleDbConnection('Provider=Microsoft.ACE.OLEDB.12.0;Data Source=W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data\Noor PurT-4221215879.mdb;Mode=Read;')
$connection.Open()
$result = [ordered]@{}
foreach ($table in $connection.GetSchema('Tables').Rows) {
 if ($table.TABLE_TYPE -ne 'TABLE') { continue }
 $name = $table.TABLE_NAME
 $command = $connection.CreateCommand(); $command.CommandText = "SELECT * FROM [$name]"
 $data = New-Object System.Data.DataTable
 $adapter = New-Object System.Data.OleDb.OleDbDataAdapter($command); [void]$adapter.Fill($data)
 $cols=@();foreach($col in $data.Columns){$cols+=@{name=$col.ColumnName;type=$col.DataType.Name;max_length=$col.MaxLength;nullable=$col.AllowDBNull}}
 $rows=@();foreach($row in $data.Rows){$values=[ordered]@{};foreach($col in $data.Columns){$values[$col.ColumnName]=if($row[$col] -is [DBNull]){$null}else{$row[$col]}};$rows+=$values}
 $result[$name]=@{columns=$cols;rows=$rows;count=$data.Rows.Count}
}
$result | ConvertTo-Json -Depth 10 | Set-Content -Encoding UTF8 'E:\xampp\htdocs\survey_progress_monitoring_system\tmp\mdb-inspection\schema.json'
$connection.Close()

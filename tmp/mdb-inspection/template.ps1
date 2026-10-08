$ErrorActionPreference='Stop'
$source='W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data\Noor PurT-4221215879.mdb'
$working='E:\xampp\htdocs\survey_progress_monitoring_system\tmp\mdb-inspection\cleared.mdb'
$target='E:\xampp\htdocs\survey_progress_monitoring_system\resources\mdb\synergee-empty.mdb'
Copy-Item -LiteralPath $source -Destination $working
$c=New-Object System.Data.OleDb.OleDbConnection("Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$working")
$c.Open()
foreach($table in $c.GetSchema('Tables').Rows){if($table.TABLE_TYPE -eq 'TABLE'){$cmd=$c.CreateCommand();$cmd.CommandText="DELETE FROM [$($table.TABLE_NAME)]";[void]$cmd.ExecuteNonQuery()}}
$c.Close();$c.Dispose()
$engine=New-Object -ComObject DAO.DBEngine.120
$engine.CompactDatabase($working,$target)
Write-Output (Get-Item -LiteralPath $target).Length

param([string]$Path)
$ErrorActionPreference='Stop'
$engine=New-Object -ComObject DAO.DBEngine.120
$db=$engine.OpenDatabase($Path,$false,$false)
try {
  if (@($db.TableDefs | ForEach-Object Name) -contains 'InstMeterDmd') { throw 'Table already exists' }
  $td=$db.CreateTableDef('InstMeterDmd')
  foreach($n in @('SectionId','Season','DayType')) {
    $size=switch($n){'SectionId'{50} default{32}}
    $f=$td.CreateField($n,10,$size);$f.Required=$true;$f.AllowZeroLength=$true;$td.Fields.Append($f)
  }
  foreach($group in @('PctKwLoad','PctKvarLoad')) { for($i=1;$i -le 24;$i++) { $n='{0}_Hour{1:D2}' -f $group,$i;$td.Fields.Append($td.CreateField($n,7)) } }
  $td.Fields.Append($td.CreateField('PctPf',7))
  foreach($group in @('PctKwDmd','PctKvarDmd')) { for($i=1;$i -le 24;$i++) { $n='{0}_Hour{1:D2}' -f $group,$i;$td.Fields.Append($td.CreateField($n,7)) } }
  $idx=$td.CreateIndex('{8295BFFE-7098-403E-A54D-DF88BD0BC8A2}');$idx.Fields.Append($idx.CreateField('SectionId'));$td.Indexes.Append($idx)
  $idx=$td.CreateIndex('Index_6738B462_8D1F_43EF');$idx.Primary=$true;$idx.Unique=$true;$idx.Required=$true
  foreach($n in @('SectionId','Season','DayType')){$idx.Fields.Append($idx.CreateField($n))};$td.Indexes.Append($idx)
  $db.TableDefs.Append($td)
  $rel=$db.CreateRelation('{8295BFFE-7098-403E-A54D-DF88BD0BC8A2}','InstMeterPoints','InstMeterDmd',4352)
  $rf=$rel.CreateField('SectionId');$rf.ForeignName='SectionId';$rel.Fields.Append($rf);$db.Relations.Append($rel)
  $db.Close();$db=$null
  Write-Output 'SCHEMA_ADDED'
} finally { if($db){$db.Close()} }

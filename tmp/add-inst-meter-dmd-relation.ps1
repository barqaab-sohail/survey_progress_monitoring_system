param([string]$Path)
$ErrorActionPreference='Stop';$engine=New-Object -ComObject DAO.DBEngine.120;$db=$engine.OpenDatabase($Path,$false,$false)
try{$td=$db.TableDefs.Item('InstMeterDmd');$td.Indexes.Delete('{8295BFFE-7098-403E-A54D-DF88BD0BC8A2}');$td.Indexes.Refresh();$rel=$db.CreateRelation('{8295BFFE-7098-403E-A54D-DF88BD0BC8A2}','InstMeterPoints','InstMeterDmd',4352);$f=$rel.CreateField('SectionId');$f.ForeignName='SectionId';$rel.Fields.Append($f);$db.Relations.Append($rel);$db.Close();$db=$null;Write-Output 'RELATION_ADDED'}finally{if($db){$db.Close()}}

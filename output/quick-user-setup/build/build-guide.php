<?php
declare(strict_types=1);
// Creates only the new quick guide. Original manuals are read-only inputs.
$root = dirname(__DIR__, 3);
$out = $root.'/output/manual/Quick_User_Setup_and_Assignment_Guide.docx';
$imageRoot = $root.'/output/quick-user-setup/screenshots/';
$escape = fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$images=[]; $markdown=[];
$run = function(string $s, bool $bold=false) use($escape):string {return '<w:r>'.($bold?'<w:rPr><w:b/></w:rPr>':'').'<w:t xml:space="preserve">'.$escape($s).'</w:t></w:r>';};
$p = function(string $s, string $style='Normal') use($escape,$run,&$markdown):string {
    $markdown[] = ($style==='Title'?'# ':($style==='Heading1'?'## ':($style==='Heading2'?'### ':''))).$s."\n";
    return '<w:p><w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>'.$run($s).'</w:p>';
};
$step = fn(int $n,string $s):string => $p($n.'. '.$s,'Step');
$check = fn(string $s):string => $p('☐ '.$s,'Check');
$warn = fn(string $s):string => $p($s,'Warning');
$break = fn():string => '<w:p><w:pPr><w:spacing w:after="0" w:before="0"/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>';
$fig = function(string $name,string $caption,float $width=6.9,?float $heightLimit=null) use(&$images,$escape,$p,$root,$imageRoot):string {
    $file = str_contains($name,'/') ? $root.'/'.$name : $imageRoot.$name.'.png';
    if (!is_file($file)) throw new RuntimeException('Missing actual screen capture: '.$file);
    [$pw,$ph]=getimagesize($file); $height=$width*$ph/$pw;
    if ($heightLimit && $height>$heightLimit) {$width*=$heightLimit/$height;$height=$heightLimit;}
    $id=count($images)+1;$images[]=['id'=>$id,'path'=>$file,'name'=>'screen'.$id.'.png'];
    $cx=(int)round($width*914400);$cy=(int)round($height*914400);
    $xml='<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="45" w:after="40"/><w:keepNext/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$id.'" name="Screen '.$id.'" descr="'.$escape($caption).'"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="'.$escape(basename($file)).'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="img'.$id.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    return $xml.$p($caption,'Caption');
};
$table = function(array $rows,array $widths=[3400,6700],bool $header=true) use($p):string {
    $xml='<w:tbl><w:tblPr><w:tblW w:w="10100" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders><w:insideH w:val="single" w:sz="4" w:color="DCE4EA"/><w:bottom w:val="single" w:sz="4" w:color="DCE4EA"/></w:tblBorders><w:tblCellMar><w:top w:w="55" w:type="dxa"/><w:left w:w="95" w:type="dxa"/><w:bottom w:w="55" w:type="dxa"/><w:right w:w="95" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
    foreach($widths as $w) $xml.='<w:gridCol w:w="'.$w.'"/>';
    $xml.='</w:tblGrid>';
    foreach($rows as $i=>$row) {
        $xml.='<w:tr><w:trPr><w:cantSplit/>'.($header&&$i===0?'<w:tblHeader/>':'').'</w:trPr>';
        foreach($row as $j=>$cell) $xml.='<w:tc><w:tcPr><w:tcW w:w="'.$widths[$j].'" w:type="dxa"/><w:vAlign w:val="top"/>'.($header&&$i===0?'<w:shd w:fill="EAF2F6"/>':'').'</w:tcPr>'.$p($cell,$header&&$i===0?'TableHead':'TableText').'</w:tc>';
        $xml.='</w:tr>';
    }
    return $xml.'</w:tbl>';
};
$columns = function(string $left,string $right,array $widths=[4400,5700]):string {
    return '<w:tbl><w:tblPr><w:tblW w:w="10100" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="50" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="'.$widths[0].'"/><w:gridCol w:w="'.$widths[1].'"/></w:tblGrid><w:tr><w:trPr><w:cantSplit/></w:trPr><w:tc><w:tcPr><w:tcW w:w="'.$widths[0].'" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>'.$left.'</w:tc><w:tc><w:tcPr><w:tcW w:w="'.$widths[1].'" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>'.$right.'</w:tc></w:tr></w:tbl><w:p><w:pPr><w:spacing w:after="0"/><w:rPr><w:sz w:val="2"/></w:rPr></w:pPr></w:p>';
};

// Page 1: minimum prerequisites and the three accounts.
$body=$p('Quick User Setup and Assignment Guide','Title');
$body.=$p('HAZECO • Administrator guide • 31 October 2026','Subtitle');
$body.=$p('Website: https://hazeco.barqaab.pk • Mobile app: HAZECO Field Survey','Path');
$body.=$p('1. Before you start','Heading1');
$body.=$p('Have an active Super Admin login, an active project, Survey Team and feeder in that project. Mobile users need the approved HAZECO Field Survey Android app and internet for sign-in/sync. For PDF work, have the matching source PDF and GPX.');
$body.=$fig('01-project-team','Check the project and Active Survey Team. Screens use training examples; use your approved records.',6.85,0.7);
$body.=$check('Administrator access, project, team and feeder are ready.');
$body.=$p('2. Create and activate the three accounts','Heading1');
$body.=$p('Menu: Administration → Users','Path');
$body.=$step(1,'In Add user, enter Name, unique Email, Role, Password and Confirm password. Use 10 or more password characters. Organization may remain None; phone and photo are optional.');
$body.=$table([['Person','Exact Role to select'],['Mobile collector','Surveyor'],['PDF entry person','Data Entry Operator'],['Independent reviewer','Data Verifier']],[3800,6300]);
$body.=$step(2,'Click Create user. Repeat for all three people. New accounts are Active. In Existing users, check Role and Status: Active; click Save user if you change either.');
$body.=$fig('02-create-user','Add user: enter the person’s details, select the exact Role, then click Create user.',6.85,3.0);
$body.=$step(3,'Give each person their own login securely. The app does not automatically send an account invitation. Keep entry and verification as different people/accounts.');
$body.=$check('Surveyor, Data Entry Operator and Data Verifier accounts exist and are Active.');

// Page 2: membership is distinct from direct mobile ownership.
$body.=$break().$p('3. Add users to the correct Survey Team','Heading1');
$body.=$p('Menu: Administration → Teams & Assignments','Path');
$body.=$step(1,'In Assign member, select Team type: Survey, the Team, and the User. Leave Survey team leader unchecked. Click Assign member. Repeat for all three people.');
$body.=$columns($fig('03-team-selection','Choose Survey and the correct Survey Team.',3.25,1.8),$fig('03-user-membership','Choose the user, then click Assign member.',3.25,1.8),[5050,5050]);
$body.=$step(2,'In Assign survey feeders, select the Survey team, Feeder selection: One feeder, Feeder and Start date → Assign feeders. Check Active feeder assignments. Dates must cover work and later sync.');
$body.=$warn('Membership does not assign a personal mobile task or PDF batch. Mobile teams need a clear team number, for example code 01 and name Survey Team 01.');
$body.=$check('All three users belong to the Survey Team; its feeder allocation is current.');
$body.=$p('4. Assign a personal Mobile App Survey task','Heading1');
$body.=$p('Menu: Administration → User Assignments','Path');
$body.=$step(1,'Find/select the Surveyor and click View assignments. Check All assignments first to avoid giving duplicate work.');
$body.=$step(2,'Choose Assignment type: Mobile App Survey → Choose type. Select Survey team and Project. Find tasks, then tick the intended feeder. Enter Start date, optional Deadline and Priority.');
$body.=$fig('04-mobile-assignment','Mobile App Survey: select the team/project and tick the feeder for this surveyor.',6.85,2.4);
$body.=$step(3,'Tick “I have checked the user, assignment type and selected tasks.” Click Review and assign, then Confirm and save. Check the success message and the new Direct task.');
$body.=$fig('04-confirm-assignment','Check the person, type and selection before Confirm and save.',4.7,0.85);
$body.=$warn('Leave overlapping work unchecked for ordinary allocation. Do not create another task merely because the first one is not yet downloaded.');
$body.=$check('The surveyor has a personal Mobile App Survey task, not just team membership.');

// Page 3: mobile collection ends before the separate PDF workflow begins.
$body.=$break().$p('4. Mobile app: download, collect and sync','Heading1');
$mobileSteps=$step(1,'While online, the Surveyor opens HAZECO Field Survey. Enter Email and Password, then tap Sign in and download assignments. Later, use the cloud Download assignments icon on Home.');
$mobileSteps.=$step(2,'Tap New survey. Choose the assigned Survey Team and feeder, then Create survey. Complete the actual transformer Details, S/E rows and required evidence.');
$mobileSteps.=$step(3,'Use Save draft when unfinished. When complete, tap Submit survey → Submit. On Home, tap Sync now and keep the app open until uploads finish.');
$mobileSteps.=$step(4,'Check that the form shows Synced and each photo shows Uploaded. The administrator checks receipt in Mobile Field Surveys.');
$mobileSteps.=$warn('A draft stays on the phone. Do not uninstall or clear app data before uploads finish. Synced is server receipt, not independent verification or proof that the entire feeder is complete.');
$body.=$columns($fig('docs/mobile/manual/screenshots/34-synced-clean.png','Existing Android training screen: Download assignments, Sync now and Synced. Example status only.',2.25,4.0),$mobileSteps,[3500,6600]);
$body.=$check('The surveyor can see the feeder, and the administrator can see the synced submission.');
$body.=$p('5. Start the separate PDF/GPX workflow','Heading1');
$body.=$warn('Mobile uploads do not automatically create a PDF/GPX batch. Obtain the matching source files separately.');
$body.=$p('Menu: MDB Creation & Validation → Create survey batch','Path');
$body.=$step(1,'Select Survey PDF and GPS GPX. Choose Project, Survey team, Feeder and Survey date. Click Create batch & start entry. Record the batch number and wait for both files to show Ready.');
$body.=$columns($fig('05-create-sources','Choose the matching source PDF and GPX.',3.3,1.2),$fig('05-create-details','Check scope/date, then Create batch & start entry.',3.3,2.6),[5050,5050]);
$body.=$check('A numbered PDF batch exists and both sources are Ready.');

// Page 4: first allocation uses the implemented stage-assignment screen.
$body.=$break().$p('5. PDF: assign entry, submit, then assign verification','Heading1');
$body.=$p('Admin menu: Workflow Task Assignments','Path');
$body.=$step(2,'Find the batch. Select Stage: Data entry and Assignee: the Data Entry Operator. Add a note if useful, then click Assign. Confirm Entry shows that person.');
$body.=$fig('05-entry-assignment','For the Draft batch, choose Data entry and Sara Entry, then Assign. Replace the example with your operator.',6.85,1.6);
$body.=$step(3,'The operator signs in to the website: MDB Creation & Validation → PDF Data Entry → Open batch. Save each transformer header and its complete S/E pairs against the PDF and GPX. Fill required values such as Inspector, Location and Service category. Check that the saved records appear.');
$body.=$step(4,'When all transformers are complete, open Review saved survey. In Survey workflow, click Submit for Verification. Resolve any validation errors. The whole batch becomes Pending Verification and entry is locked.');
$body.=$fig('05-submit-for-verification','Operator review: Submit for Verification sends the whole batch for review. Saving rows alone does not submit it.',6.85,1.75);
$body.=$p('Alternative: Submit Transformer checks one transformer; continue if more remain. After the last one, “No, go to MDB Creation & Validation” submits the batch.','Small');
$body.=$step(5,'The administrator returns to Workflow Task Assignments. For that Pending Verification batch, select Stage: Verification and Assignee: a different Data Verifier. Click Assign.');
$body.=$fig('05-verifier-assignment','Once Pending Verification, choose Verification and Nadia Verifier, then Assign.',6.85,1.6);
$body.=$warn('Anyone who entered the batch must not verify it. Changing the entry owner later does not remove this restriction. Assign existing batches; do not delete/recreate work to transfer it.');
$body.=$check('Entry is submitted; a different person is the verification assignee.');

// Page 5: reviewer decision, practical acceptance and likely problems only.
$body.=$break().$p('5. PDF: approve or return for correction','Heading1');
$body.=$p('Verifier menu: MDB Creation & Validation → Data Verification → Open batch','Path');
$body.=$step(6,'Check every transformer and its entered values against the source PDF/GPX. Review ticks are aids; they do not approve the batch. In Survey workflow, choose the correct decision below.');
$body.=$fig('05-review-decisions','Approve accepts the batch. For entry changes, write clear comments and choose Return for Correction.',6.85,2.15);
$body.=$p('Approve → Verified. Return for Correction → operator edits the same batch, saves and resubmits; verifier reviews it again. Identify the transformer, PDF page and error in return comments. Flag Discrepancy keeps entry locked; it is not a return.');
$body.=$warn('This decision applies to the whole PDF batch. Mobile uploads do not use this approval path; corrections to already synced mobile work need administrator/support assistance.');
$body.=$check('The PDF batch is Verified, or returned with clear correction instructions.');
$body.=$p('6. Final sign-in and assignment checklist','Heading1');
$body.=$p('☐ Surveyor signs in on Android, downloads and sees the intended feeder.','Checklist');
$body.=$p('☐ Entry operator signs in on the website and sees the assigned Draft/Returned batch.','Checklist');
$body.=$p('☐ Different verifier signs in and sees the submitted batch in Data Verification.','Checklist');
$body.=$p('☐ Admin checks Workflow Task Assignments for both owners and the correct batch status.','Checklist');
$body.=$fig('06-assignment-check','Current assignees confirms the two different owners on the same batch.',6.85,1.2);
$body.=$p('7. Common Problems','Heading1');
$body.=$table([['Problem','Quick fix'],['Cannot sign in','Check Email, Password and Active status. Admin can reset the password in Users.'],['Feeder/task missing','Check role, team, current dates and personal assignment. Download assignments on the phone; clear website filters.'],['PDF/GPX stays Queued','Ask hosting/support to check source processing. Avoid creating duplicate batches.'],['Verifier/batch unavailable','Submit first. Choose Data Verifier and a person who did not enter that batch.'],['Returned batch still locked','Use Return for Correction when entry changes are needed. Flag Discrepancy alone keeps it pending.']],[3000,7100]);
$body.=$check('Each person has tested their own login and can see the correct work.');

$ns='xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';
$styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:color w:val="243746"/><w:sz w:val="21"/><w:lang w:val="en-GB"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="65" w:line="255" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>';
foreach(['Normal'=>[21,'243746',false,0,65],'Title'=>[48,'17344E',true,0,100],'Subtitle'=>[20,'46687C',false,0,120],'Heading1'=>[29,'17344E',true,130,75],'Heading2'=>[24,'087F8C',true,90,50],'Step'=>[21,'243746',false,0,65],'Path'=>[20,'087F8C',true,0,65],'Caption'=>[17,'536775',false,0,75],'Small'=>[18,'536775',false,0,65],'Check'=>[20,'125E60',false,60,100],'Warning'=>[20,'77520D',false,55,85],'TableText'=>[19,'243746',false,0,30],'TableHead'=>[19,'17344E',true,0,30],'Checklist'=>[20,'243746',false,0,65]] as $name=>[$size,$color,$bold,$before,$after]) {
    $styles.='<w:style w:type="paragraph" w:styleId="'.$name.'"><w:name w:val="'.$name.'"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="'.$before.'" w:after="'.$after.'" w:line="250" w:lineRule="auto"/>'.(in_array($name,['Title','Subtitle','Heading1','Heading2','Path'])?'<w:keepNext/>':'').($name==='Check'?'<w:shd w:fill="EDF5F4"/><w:ind w:left="95" w:right="95"/>':'').($name==='Warning'?'<w:shd w:fill="FCF7EA"/><w:ind w:left="95" w:right="95"/>':'').'</w:pPr><w:rPr><w:sz w:val="'.$size.'"/><w:color w:val="'.$color.'"/>'.($bold?'<w:b/>':'').'</w:rPr></w:style>';
}
$styles.='</w:styles>';
$section='<w:sectPr><w:headerReference w:type="default" r:id="header"/><w:footerReference w:type="default" r:id="footer"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="720" w:right="850" w:bottom="720" w:left="850" w:header="300" w:footer="300"/></w:sectPr>';
$document='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document '.$ns.'><w:body>'.$body.$section.'</w:body></w:document>';
// XML text nodes cannot contain raw paragraph newlines; use explicit line breaks.
$document=preg_replace_callback('~<w:t([^>]*)>(.*?)</w:t>~s',fn($m)=>'<w:t'.$m[1].'>'.str_replace("\n",'</w:t><w:br/><w:t xml:space="preserve">',$m[2]).'</w:t>',$document);
$relations='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="header" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="footer" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
foreach($images as $image) $relations.='<Relationship Id="img'.$image['id'].'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$image['name'].'"/>';
$relations.='</Relationships>';
$zip=new ZipArchive();if($zip->open($out,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot create new guide.');
$zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
$zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="doc" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="core" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>');
$zip->addFromString('word/document.xml',$document);$zip->addFromString('word/styles.xml',$styles);$zip->addFromString('word/_rels/document.xml.rels',$relations);
$zip->addFromString('word/header1.xml','<?xml version="1.0" encoding="UTF-8"?><w:hdr '.$ns.'>'.$p('HAZECO  /  ADMINISTRATOR QUICK GUIDE','Caption').'</w:hdr>');
$zip->addFromString('word/footer1.xml','<?xml version="1.0" encoding="UTF-8"?><w:ftr '.$ns.'><w:p><w:pPr><w:jc w:val="right"/></w:pPr>'.$run('31 October 2026  •  Page ').'<w:fldSimple w:instr="PAGE"/>'.$run(' of ').'<w:fldSimple w:instr="NUMPAGES"/></w:p></w:ftr>');
$zip->addFromString('docProps/core.xml','<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Quick User Setup and Assignment Guide</dc:title><dc:creator>HAZECO Project</dc:creator><dc:description>Separate illustrated quick guide; original manuals preserved. Web screenshots use isolated training data.</dc:description></cp:coreProperties>');
foreach($images as $image)$zip->addFile($image['path'],'word/media/'.$image['name']);$zip->close();
file_put_contents($root.'/docs/QUICK_USER_SETUP_AND_ASSIGNMENT_GUIDE.md',implode("\n",$markdown));
$original=new ZipArchive();if($original->open($root.'/output/manual/HAZECO_Field_Survey_User_Manual.docx')===true){$xml=$original->getFromName('word/document.xml');$original->close();$dom=new DOMDocument();$dom->loadXML($xml);$xp=new DOMXPath($dom);$xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');$text=[];foreach($xp->query('//w:p') as $paragraph){$line='';foreach($xp->query('.//w:t',$paragraph) as $t)$line.=$t->textContent;$text[]=$line;}file_put_contents($root.'/output/quick-user-setup/build/original-docx-reviewed.txt',implode("\n",$text));}
echo 'Created '.$out.' with '.count($images)." actual screen images.\n";

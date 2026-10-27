import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';
const dir=path.dirname(fileURLToPath(import.meta.url));
const data=JSON.parse(await fs.readFile(path.join(dir,'analysis_data.json'),'utf8'));
const wb=Workbook.create();
const names=['Overview','Settings','Loads','Generation','Sections','Nodes','Field dictionary','All entries','Table inventory'];
const sheets=Object.fromEntries(names.map(n=>[n,wb.worksheets.add(n)]));
function col(n){let s='';for(n++;n>0;n=Math.floor((n-1)/26))s=String.fromCharCode(65+(n-1)%26)+s;return s;}
function safe(v){return typeof v==='string'&&v.startsWith('=')?"'"+v:v;}
const textFormats=[];
function grid(name,title,note,headers,rows,widths,tableName){
 const s=sheets[name],end=col(headers.length-1),last=rows.length+5;
 s.showGridLines=false;
 s.getRange(`A1:${end}${last}`).format.font={name:'Arial',size:10,color:'#26384A'};
 s.getRange('A2').values=[[title]];s.getRange('A2').format.font={name:'Arial',size:15,bold:true,color:'#183554'};
 s.getRange('A3').values=[[note]];s.getRange('A3').format.font={name:'Arial',size:10,italic:true,color:'#5C6C7F'};
 s.getRange(`A5:${end}5`).values=[headers];
 if(rows.length)s.getRange(`A6:${end}${last}`).values=rows.map(r=>r.map(safe));
 rows.forEach((r,i)=>r.forEach((v,j)=>{if(typeof v==='string'&&/^\d+(?:\.\d+)?$/.test(v))textFormats.push([s,`${col(j)}${i+6}`,`"${v}"`]);}));
 s.getRange(`A5:${end}5`).format={fill:'#203E60',font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},wrapText:true,horizontalAlignment:'center',verticalAlignment:'center',rowHeight:34};
 if(rows.length){s.getRange(`A6:${end}${last}`).format.verticalAlignment='top';s.getRange(`A6:${end}${last}`).format.rowHeight=28;}
 widths.forEach((w,i)=>s.getRange(`${col(i)}5:${col(i)}${last}`).format.columnWidth=w);
 if(rows.length){const t=s.tables.add(`A5:${end}${last}`,true,tableName);t.showFilterButton=true;}
 s.freezePanes.freezeRows(5);s.freezePanes.freezeColumns(name==='All entries'?3:1);
 return s;
}
const ids=data.summaries.map(s=>s.id);
const summaryRows=data.summaries.map(s=>[s.feeder,s.id,s.type,s.sections,s.loads,s.nodes,null,null,null,s.dg_count,null]);
const overview=grid('Overview','SynerGEE MDB entry analysis','7 databases | 475 records | 13,180 field values | 257 field definitions | Extracted 27 Oct 2026',
 ['Feeder','Transformer ID','Type label','Sections','Load records','Nodes','Customer allocation sum','Entered kVA sum','Model length (m)','Generation records','Generation kW input'],summaryRows,[19,20,15,12,13,10,19,18,18,17,20],'FileSummary');
overview.tabColor='#203E60';
const loadEnd=data.loads.length+5,sectionEnd=data.sections.length+5,genEnd=data.generators.length+5;
for(let i=0;i<summaryRows.length;i++){
 const r=i+6;
 overview.getRange(`G${r}:I${r}`).formulas=[[
 `=SUMIFS('Loads'!$U$6:$U$${loadEnd},'Loads'!$A$6:$A$${loadEnd},B${r})`,
 `=SUMIFS('Loads'!$V$6:$V$${loadEnd},'Loads'!$A$6:$A$${loadEnd},B${r})`,
 `=SUMIFS('Sections'!$G$6:$G$${sectionEnd},'Sections'!$A$6:$A$${sectionEnd},B${r})`]];
 overview.getRange(`K${r}`).formulas=[[`=SUMIFS('Generation'!$H$6:$H$${genEnd},'Generation'!$A$6:$A$${genEnd},B${r})`]];
}
overview.getRange('D6:G12').setNumberFormat('#,##0');overview.getRange('H6:I12').setNumberFormat('#,##0.00');overview.getRange('K6:K12').setNumberFormat('#,##0.00');
overview.getRange('A15').values=[['How to read the values']];overview.getRange('A15').format.font={bold:true,size:12,color:'#183554'};
const overviewNotes=[
 ['Entered kVA','Connected/load-allocation input; not measured coincident peak or calculated transformer loading.'],
 ['Customer allocation','Phase values can be fractional. The summary sums the entered R/Y/B allocation values.'],
 ['Generation','Four PVGEN-labelled records have nonzero kW inputs. Type/status are NULL; operating export is unverified.'],
 ['Frequency','All seven files store 60 Hz. Review for the intended 50 Hz Pakistan network.'],
 ['Voltage','NominalKvll=11; source BusVoltageLevel=11.37. PT=120 and LTC target=122 are control-related values.'],
 ['Loads','All customer-load kW/kvar inputs are zero; all load kWh inputs are NULL.'],
 ['Missing definitions','Equipment libraries and legacy enum/unit definitions are needed to verify ratios, ratings and some modes.'],
 ['Completeness','All 50 tables read per file. 9 tables populated across the set; 41 empty in all files.'],
 ['Value states','<NULL> = not entered; "" = empty text. Zero, blanks, absent phases and inactive options are distinct.'],
 ['Original files','Opened read-only. SHA-256 hashes unchanged after extraction.']];
overview.getRange('A17:B26').values=overviewNotes;overview.getRange('A17:B26').format.font={name:'Arial',size:10,color:'#26384A'};
overview.getRange('B17:K26').merge(true);overview.getRange('B17:K26').format.wrapText=true;overview.getRange('A17:K26').format.rowHeight=34;overview.getRange('A17:A26').format.font={bold:true,color:'#203E60'};

const settings=grid('Settings','Feeder, transformer, meter and model settings','Exact single-record values by file. IDs in column headings map to full filenames in Table inventory.',
 ['Source table','Field','Unit / form','Short meaning',...ids,'Interpretation / limitation'],data.settings,[25,33,24,65,...ids.map(()=>24),75],'SettingValues');
settings.getRange(`D6:D${data.settings.length+5}`).format.wrapText=true;settings.getRange(`L6:L${data.settings.length+5}`).format.wrapText=true;
data.settings.forEach((r,i)=>{const lines=Math.max(Math.ceil(r[3].length/78),Math.ceil(r[11].length/90));settings.getRange(`A${i+6}:L${i+6}`).format.rowHeight=Math.max(30,lines*15+8);});

const loadRows=data.loads.map(r=>[...r,null,null]);
const loads=grid('Loads','Per-phase customer-load inputs','Phase1=R, Phase2=Y, Phase3=B. <NULL> preserves missing/absent phase inputs. Totals exclude NULL; all active phases have numeric inputs.',
 ['Transformer ID','Section ID','IsSpotLoad','R customers','Y customers','B customers','R kVA input','Y kVA input','B kVA input','R kW','Y kW','B kW','R kvar','Y kvar','B kvar','R kWh','Y kWh','B kWh','Capacity factor','Spot placement code','Customer sum','Entered kVA sum'],loadRows,[20,25,13,...Array(15).fill(15),17,20,17,18],'LoadInputs');
for(let i=0;i<data.loads.length;i++){const r=i+6;loads.getRange(`U${r}:V${r}`).formulas=[[`=SUM(D${r}:F${r})`,`=SUM(G${r}:I${r})`]];}
loads.getRange(`D6:V${loadEnd}`).setNumberFormat('#,##0.0000');loads.getRange(`A6:C${loadEnd}`).setNumberFormat('@');

const generation=grid('Generation','Distributed-generation inputs','PVGEN names are stored labels. DGenType and DGenStatus are NULL. kW sums are model inputs, not measured export.',
 ['Transformer ID','Section ID','Generation label','YearNumber','R kW input','Y kW input','B kW input','Total kW input','R kvar','Y kvar','B kvar','Year capacity factor','Output percent','Voltage setting','DGenType','DGenStatus'],data.generators,[20,25,34,14,18,18,18,19,13,13,13,19,16,17,18,18],'GenerationInputs');
for(let i=0;i<data.generators.length;i++){const r=i+6;generation.getRange(`H${r}`).formulas=[[`=SUM(E${r}:G${r})`]];}
generation.getRange(`E6:H${genEnd}`).setNumberFormat('0.000000');

const sections=grid('Sections','Network sections and conductor references','Phase text and conductor-key whitespace are preserved. Length units supported by Metric setting and coordinate-distance checks.',
 ['Transformer ID','Section ID','From node','To node','Phases','Description','Length (m)','R conductor key','Y conductor key','B conductor key','Neutral key','Configuration key'],data.sections,[20,25,22,22,13,26,16,20,20,20,20,38],'SectionInputs');
sections.getRange(`G6:G${sectionEnd}`).setNumberFormat('0.0000');sections.getRange(`A6:F${sectionEnd}`).setNumberFormat('@');
const nodes=grid('Nodes','Network coordinates and connectivity','Declared CRS: WGS 1984 UTM Zone 43N. X/Y are projected coordinates. Verify CRS against survey before geographic use.',
 ['Transformer ID','Node ID','X / easting','Y / northing','Section use','IsPadMountGear'],data.nodes,[20,25,19,19,27,21],'NodeInputs');
nodes.getRange(`C6:D${data.nodes.length+5}`).setNumberFormat('0.000');

const dict=grid('Field dictionary','Field meanings and observed values','257 table-specific fields. Values are extracted facts; field meanings are interpretations with unresolved codes/units explicitly marked.',
 ['Table','Field','Unit / form','Short meaning / purpose','Interpretation / limitation','Meaning basis','Observed values (sample and occurrence counts)'],data.fields,[26,35,29,68,80,47,100],'FieldMeanings');
dict.getRange(`C6:G${data.fields.length+5}`).format.wrapText=true;
data.fields.forEach((r,i)=>{const lines=Math.max(Math.ceil(r[3].length/80),Math.ceil(r[4].length/96),Math.ceil(r[6].length/120));dict.getRange(`A${i+6}:G${i+6}`).format.rowHeight=Math.max(44,lines*15+10);});
const refs=[
 ['S1','ArcFM: SynerGEE load attributes','https://resources.arcfmsolution.com/10.2.1c/DesktopSDK/ESLoads.html','Per-phase load and connected-kVA allocation concepts. XML enums are not assumed identical to MDB flags.'],
 ['S2','ArcFM: SynerGEE equipment identifiers','https://resources.arcfmsolution.com/10.8.1/DesktopSDK/topic104.html','Separate equipment-library keys for transformers, phase conductors and configurations.'],
 ['S3','NEPRA Distribution Code 2005, SPR 2(b)','https://nepra.org.pk/Legislation/6-Codes/6.1%20Distribution%20Code%20%202005/Distribution%20Code%202005.pdf','50 Hz nominal basis for frequency review.'],
 ['S4','DNV technical-documentation catalogue','https://mysoftware.dnv.com/knowledge-centre/synergi-electric/tech-doc/','Catalogue only; legacy SynerGEE 5 enum and geometry-scale definitions were not established.']];
const rr=data.fields.length+9;dict.getRange(`A${rr}:D${rr+4}`).values=[['Ref','External reference','URL','Interpretation supported'],...refs];dict.getRange(`A${rr}:D${rr+4}`).format.wrapText=true;dict.getRange(`A${rr}:D${rr+4}`).format.rowHeight=60;

const all=grid('All entries','Complete stored field-value list','All 13,180 cells from 475 records. Filter source file, table, record ID and field. Raw numeric values retain precision; whitespace is preserved.',
 ['Source file','Table','Record ID','Row ordinal','Field','Stored value','Value state','Unit / form','Short meaning / purpose','Interpretation / limitation'],data.entries,[43,27,27,13,36,38,30,29,80,85],'AllStoredEntries');
all.getRange(`H6:J${data.entries.length+5}`).format.wrapText=true;all.getRange(`F6:F${data.entries.length+5}`).setNumberFormat('General');
data.entries.forEach((r,i)=>{const lines=Math.max(Math.ceil(r[7].length/34),Math.ceil(r[8].length/96),Math.ceil(r[9].length/102));all.getRange(`A${i+6}:J${i+6}`).format.rowHeight=Math.max(30,lines*15+8);});

const inv=grid('Table inventory','All source tables and record counts','Same 50-table schema in all files. Empty tables have no stored record values. Full source filenames and hashes appear below.',
 ['Table','Purpose',...ids],data.inventory,[30,95,...ids.map(()=>20)],'SourceTables');
inv.getRange('B6:B55').format.wrapText=true;inv.getRange('A6:I55').format.rowHeight=43;
inv.getRange('A59:E66').values=[['Transformer ID','Full source filename','Source directory','SHA-256','Integrity check'],...data.summaries.map(s=>[s.id,s.file,'C:\\Users\\sohai\\OneDrive\\Desktop\\HAZECO',s.hash,'Unchanged after read-only extraction'])];
inv.getRange('A59:E66').format.wrapText=true;inv.getRange('A59:E66').format.rowHeight=66;inv.getRange('A59:E59').format={fill:'#203E60',font:{color:'#FFFFFF',bold:true,name:'Arial',size:10},wrapText:true};

// Numeric-looking source IDs remain text. Literal formats also keep the renderer
// from displaying their long digits as scientific notation or rounding suffixes.
for(const [s,cell,fmt] of textFormats)s.getRange(cell).setNumberFormat(fmt);
wb.recalculate();
const vals=overview.getRange('G6:K12').values;
for(let i=0;i<data.summaries.length;i++){
 const s=data.summaries[i],expected=[s.customers,s.kva,s.length,s.dg_count,s.dg_kw];
 for(let j=0;j<expected.length;j++)if(typeof vals[i][j]!=='number'||Math.abs(vals[i][j]-expected[j])>1e-7)throw Error(`Summary mismatch ${i},${j}: ${vals[i][j]} != ${expected[j]}`);
}
console.log((await wb.inspect({kind:'table',range:'Overview!A5:K12',include:'values,formulas',tableMaxRows:8,tableMaxCols:11,maxChars:2600})).ndjson);
console.log((await wb.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!',options:{useRegex:true,maxResults:10},summary:'Final formula error scan',maxChars:1000})).ndjson);
await fs.mkdir(path.join(dir,'previews'),{recursive:true});
const regions={Overview:'A1:K26',Settings:'A1:F9',Loads:'A1:I11',Generation:'A1:H9',Sections:'A1:H11',Nodes:'A1:F12','Field dictionary':'A1:E10','All entries':'A1:H12','Table inventory':'A1:D12'};
for(const [sheetName,range] of Object.entries(regions)){
 const img=await wb.render({sheetName,range,scale:1,format:'png'});
 await fs.writeFile(path.join(dir,'previews',sheetName.replaceAll(' ','_')+'.png'),new Uint8Array(await img.arrayBuffer()));
 console.log('Rendered '+sheetName);
}
const out=await SpreadsheetFile.exportXlsx(wb);await out.save(path.join(dir,'Synergee_MDB_Entry_Reference.xlsx'));
console.log('Exported complete workbook.');

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';

const dir=path.dirname(fileURLToPath(import.meta.url));
const data=JSON.parse(await fs.readFile(path.join(dir,'requirements_data.json'),'utf8'));
const wb=Workbook.create();
const ink='#17365D', body='#243746', amber='#FFF2CC';
const widths=[46,205,285,180,245,195,195,275];
const layout={};

function init(sheet,title,subtitle,note,cols=8){
  sheet.showGridLines=false;
  sheet.getRangeByIndexes(0,0,5,cols).format.font={name:'Arial',size:10,color:body};
  sheet.getRange('A2').values=[[title]];
  sheet.getRange('A2').format.font={name:'Arial',size:15,bold:true,color:ink};
  sheet.getRange(`A2:${String.fromCharCode(64+cols)}2`).format.rowHeight=27;
  sheet.getRange(`A2:${String.fromCharCode(64+cols)}2`).format.borders={bottom:{style:'thin',color:ink}};
  sheet.getRange('A3').values=[[subtitle]];
  sheet.getRange('A4').values=[[note]];
  sheet.getRange('A3:A4').format.rowHeight=21;
  sheet.getRange('A3:A4').format.font={name:'Arial',size:10,color:'#536779'};
  sheet.tabColor=ink;
}
function estimateLines(value,px){
  if(value===null || value===undefined)return 1;
  return String(value).split('\n').reduce((a,t)=>a+Math.max(1,Math.ceil(t.length/(px/5.25))),0);
}
for (const [sheetName,level,sub] of [
  ['Mandatory','Mandatory','Required for the existing full engineering approval/export workflow.'],
  ['Conditional','Conditional','Required only when the stated demand method, device or data condition applies.'],
  ['Optional','Optional','Additional study/model settings; not universal blockers in the current generator.'],
]){
  const sheet=wb.worksheets.add(sheetName);
  const selected=data.rows.filter(r=>r.level===level);
  init(sheet,`${data.title} — ${sheetName}`,`HAZECO. Reviewed ${data.as_of}. ${selected.length} items. ${sub}`,'Fill the amber Actual Value column only. Reference examples require departmental confirmation. Source keys are explained on Evidence and Scope.');
  widths.forEach((w,i)=>sheet.getRangeByIndexes(0,i,selected.length+30,1).format.columnWidthPx=w);
  sheet.getRange('A5:H5').values=[data.headers];
  sheet.getRange('A5:H5').format={fill:ink,font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},horizontalAlignment:'center',verticalAlignment:'center',wrapText:true,rowHeight:43};
  let row=6,category='';
  const inputRows=[];
  for(const item of selected){
    if(item.category!==category){
      category=item.category;
      sheet.mergeCells(`A${row}:H${row}`);
      const band=sheet.getRange(`A${row}:H${row}`);
      sheet.getRange(`A${row}`).values=[[category]];
      band.format={fill:'#DFE7F1',font:{name:'Arial',size:10,bold:true,color:ink},verticalAlignment:'center',rowHeight:25};
      row++;
    }
    const values=[item.sr,item.name,item.purpose,item.format,item.sample,item.owner,null,item.remark];
    const range=sheet.getRange(`A${row}:H${row}`);
    range.values=[values];
    const height=Math.max(76,...values.map((v,i)=>estimateLines(v,widths[i]-20)*16+24));
    range.format={font:{name:'Arial',size:10,color:body},fill:inputRows.length%2 ? '#F6F8FB':'#FFFFFF',wrapText:true,verticalAlignment:'top',rowHeightPx:height,borders:{bottom:{style:'thin',color:'#DFE5EC'}}};
    sheet.getRange(`A${row}`).format.horizontalAlignment='center';
    sheet.getRange(`B${row}`).format.font={name:'Arial',size:10,bold:true,color:ink};
    sheet.getRange(`G${row}`).format={fill:amber,font:{name:'Arial',size:10,color:'#174EA6'},wrapText:true,verticalAlignment:'top'};
    inputRows.push(row);row++;
  }
  sheet.freezePanes.freezeRows(5);
  sheet.freezePanes.freezeColumns(2);
  layout[sheetName]={lastRow:row-1,lastCol:'H',inputRows};
}
const evidence=wb.worksheets.add('Evidence and Scope');
init(evidence,'Scope, classification and source evidence','HAZECO. Engineering Configuration Data Requirement Sheet. Read-only assessment dated '+data.as_of+'.','Evidence below documents availability and limits. It is not an engineering approval or a list of values already accepted for this project.',4);
const ew=[130,390,670,560];
ew.forEach((w,i)=>evidence.getRangeByIndexes(0,i,data.evidence.length+6,1).format.columnWidthPx=w);
evidence.getRange('A5:D5').values=[['Evidence type','Source / subject','Observed information','Limit / interpretation']];
evidence.getRange('A5:D5').format={fill:ink,font:{name:'Arial',size:10,bold:true,color:'#FFFFFF'},rowHeight:31,wrapText:true,horizontalAlignment:'center',verticalAlignment:'center'};
data.evidence.forEach((r,i)=>{
  const row=i+6, height=Math.max(48,...r.map((v,j)=>estimateLines(v,ew[j]-16)*15+10));
  evidence.getRange(`A${row}:D${row}`).values=[r];
  evidence.getRange(`A${row}:D${row}`).format={font:{name:'Arial',size:10,color:body},fill:i%2?'#F6F8FB':'#FFFFFF',wrapText:true,verticalAlignment:'top',rowHeightPx:height,borders:{bottom:{style:'thin',color:'#DFE5EC'}}};
});
evidence.freezePanes.freezeRows(5);
evidence.tabColor='#7D8B99';
layout['Evidence and Scope']={lastRow:data.evidence.length+5,lastCol:'D',inputRows:[]};
wb.recalculate();
for(const [name,info] of Object.entries(layout)){
  const sheet=wb.worksheets.getItem(name);
  for(const r of info.inputRows){
    const value=sheet.getRange(`G${r}`).values[0][0];
    if(value!==null && value!==undefined && value!=='')throw new Error(`Actual Value must be blank: ${name}!G${r}`);
  }
}
console.log((await wb.inspect({kind:'table',range:'Mandatory!A5:H9',include:'values,formulas',tableMaxRows:5,tableMaxCols:8,maxChars:1800})).ndjson);
console.log((await wb.inspect({kind:'match',searchTerm:'#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!',options:{useRegex:true,maxResults:20},summary:'Final error scan',maxChars:800})).ndjson);
await fs.writeFile(path.join(dir,'workbook_layout.json'),JSON.stringify(layout,null,2));
const output=await SpreadsheetFile.exportXlsx(wb);
await output.save(path.join(dir,'Engineering_Configuration_Data_Requirement_Sheet.xlsx'));
for(const [name,range] of [['Mandatory','A2:H10'],['Conditional','A5:H11'],['Optional','A5:H10'],['Evidence and Scope','A2:D10']]){
  const blob=await wb.render({sheetName:name,range,scale:1,format:'png'});
  await fs.writeFile(path.join(dir,`preview_${name.replaceAll(' ','_')}.png`),new Uint8Array(await blob.arrayBuffer()));
}
console.log(`Exported workbook. ${data.rows.length} requirements. ${Object.keys(layout).length} sheets. All Actual Value cells blank.`);

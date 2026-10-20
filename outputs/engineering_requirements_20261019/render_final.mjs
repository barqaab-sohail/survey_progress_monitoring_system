import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {FileBlob,SpreadsheetFile} from '@oai/artifact-tool';
const dir=path.dirname(fileURLToPath(import.meta.url));
const w=await SpreadsheetFile.importXlsx(await FileBlob.load(path.join(dir,'Engineering_Configuration_Data_Requirement_Sheet.xlsx')));
const layout=JSON.parse(await fs.readFile(path.join(dir,'workbook_layout.json'),'utf8'));
const end=layout.Conditional.inputRows.at(-1);
for(const [name,sheet,range] of [['mandatory_final','Mandatory','A2:H10'],['pv_final','Conditional',`A${end-5}:H${end}`]]){
 const png=await w.render({sheetName:sheet,range,scale:1,format:'png'});
 await fs.writeFile(path.join(dir,name+'.png'),new Uint8Array(await png.arrayBuffer()));
}
console.log((await w.inspect({kind:'sheet',include:'id,name',maxChars:600})).ndjson);
console.log('Final workbook reopened and rendered without changing the deliverable.');

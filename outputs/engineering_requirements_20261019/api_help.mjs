import {Workbook} from '@oai/artifact-tool';
const w=Workbook.create();
console.log(w.help('worksheet', {search:'page|print|freeze',include:'index,notes',maxChars:3500}).ndjson);

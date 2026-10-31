import fs from 'node:fs/promises';
import {createCanvas,DOMMatrix,ImageData,Path2D} from '../../../outputs/engineering_requirements_20261019/node_modules/@napi-rs/canvas/index.js';
globalThis.DOMMatrix=DOMMatrix;globalThis.ImageData=ImageData;globalThis.Path2D=Path2D;
const pdfjs=await import('../../../outputs/engineering_requirements_20261019/node_modules/pdfjs-dist/legacy/build/pdf.mjs');
const data=new Uint8Array(await fs.readFile('output/pdf/Quick_User_Setup_and_Assignment_Guide.pdf'));
const pdf=await pdfjs.getDocument({data,useSystemFonts:true}).promise;
const out='output/quick-user-setup/rendered';
const all=[];
for(let i=1;i<=pdf.numPages;i++) {
    const p=await pdf.getPage(i),v=p.getViewport({scale:1.5});
    const canvas=createCanvas(Math.ceil(v.width),Math.ceil(v.height));
    await p.render({canvasContext:canvas.getContext('2d'),viewport:v}).promise;
    await fs.writeFile(`${out}/page-${String(i).padStart(2,'0')}.png`,canvas.toBuffer('image/png'));
    all.push({page:i,text:(await p.getTextContent()).items.map(x=>x.str).join(' ')});
}
await fs.writeFile(`${out}/text-check.json`,JSON.stringify(all,null,2));
console.log(`Rendered ${pdf.numPages} PDF pages at 150% for inspection.`);

const {chromium}=require('./feeder-kmz-qa/node_modules/playwright-core');
const fs=require('fs');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 const page=await browser.newPage();
 let html=fs.readFileSync('tmp/field-correction-preview.html','utf8');
 html=html.replaceAll('http://localhost/', 'http://127.0.0.1:8000/');
 html=html.replace('</head>','<base href="http://127.0.0.1:8000/"></head>');
 for(const [name,width] of [['desktop',1600],['mobile',390]]){
  await page.setViewportSize({width,height:1000});
  await page.setContent(html,{waitUntil:'networkidle'});
  await page.screenshot({path:`tmp/field-correction-${name}.png`,fullPage:true});
  console.log(name,JSON.stringify(await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth,controlHeight:document.querySelector('[data-field="transformer_code"]').getBoundingClientRect().height,formWidth:document.querySelector('#field-correction-form').getBoundingClientRect().width}))));
 }
 await browser.close();
})();



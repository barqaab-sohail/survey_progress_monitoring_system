from playwright.sync_api import sync_playwright,expect
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
 page=b.new_page(viewport={'width':1440,'height':1000});page.goto('http://127.0.0.1:8012/login');page.locator('[name=email]').fill('mdb-ui@example.test');page.locator('[name=password]').fill('QaOnlyPassword123!');page.locator('button[type=submit]').click();page.wait_for_url('http://127.0.0.1:8012/');page.goto('http://127.0.0.1:8012/mdb-builder/2/edit')
 page.screenshot(path='tmp/mdb-inspection/form.png',full_page=True)
 assert page.locator('[data-row=gps_waypoint]').first.evaluate('(e)=>e.getBoundingClientRect().width')>=80
 print(page.locator('[data-row=gps_waypoint]').first.evaluate('(e)=>({rect:e.getBoundingClientRect().width,width:getComputedStyle(e).width,min:getComputedStyle(e).minWidth,max:getComputedStyle(e).maxWidth,padding:getComputedStyle(e).padding,value:e.value})'))
 page.set_viewport_size({'width':390,'height':844})
 expect(page.locator('.sidebar')).not_to_be_visible()
 assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
 page.screenshot(path='tmp/mdb-inspection/form-mobile.png',full_page=True,animations='disabled')
 print(page.locator('.sidebar').evaluate('(e)=>({cls:e.className,width:e.getBoundingClientRect().width,left:e.getBoundingClientRect().left,display:getComputedStyle(e).display,transform:getComputedStyle(e).transform,position:getComputedStyle(e).position})'))
 print(page.locator('.main').evaluate('(e)=>({width:e.getBoundingClientRect().width,left:e.getBoundingClientRect().left})'))
 print(page.locator('h1').evaluate('(e)=>({width:e.getBoundingClientRect().width,left:e.getBoundingClientRect().left})'))
 print(page.locator('.mdb-paper-table').evaluate('(e)=>({width:e.getBoundingClientRect().width})'))
 b.close()

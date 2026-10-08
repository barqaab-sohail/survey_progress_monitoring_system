from playwright.sync_api import sync_playwright,expect
from pathlib import Path
from pyproj import Transformer
import json
base=Path(r'E:\xampp\htdocs\survey_progress_monitoring_system')
samples=Path(r'W:\2120 HAZECO T&D Losses Project\HAZECO Data\MEPCO 1st Data Package\Sample Data')
with sync_playwright() as p:
 browser=p.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
 page=browser.new_page(viewport={'width':1440,'height':1000},accept_downloads=True)
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.goto('http://127.0.0.1:8012/login')
 page.locator('input[name=email]').fill('mdb-ui@example.test');page.locator('input[name=password]').fill('QaOnlyPassword123!')
 page.locator('button[type=submit]').click();page.wait_for_url('http://127.0.0.1:8012/')
 page.get_by_role('link',name='Create Transformer MDB',exact=True).click()
 page.get_by_role('link',name='Enter paper survey',exact=True).click()
 expect(page.locator('#mdb-rows tr')).to_have_count(2)
 page.locator('#mdb-feeder').select_option(index=1)
 page.locator('#mdb-code').fill('T-4221215879')
 page.locator('#mdb-date').fill('2025-08-23')
 page.locator('[data-header=capacity_kva]').fill('100')
 page.locator('[data-header=inspectors]').fill('QA transcription')
 page.locator('[data-header=transformer_make]').fill('SKYPOWER')
 page.locator('#mdb-pdf').set_input_files(str(samples/'B2308.pdf'))
 page.locator('#mdb-gpx').set_input_files(str(samples/'B2308.gpx'))
 page.locator('[data-setting=transformer_waypoints]').fill('878,879')
 lon,lat=Transformer.from_crs(32642,4326,always_xy=True).transform(780079,3373893)
 page.locator('[data-setting=transformer_latitude]').fill(str(lat));page.locator('[data-setting=transformer_longitude]').fill(str(lon));page.locator('[data-setting=utm_zone]').fill('42')
 page.locator('[data-rate=rs]').fill('3');page.locator('[data-rate=rl]').fill('3')
 page.locator('[data-setting=blank_consumers_zero]').check();page.locator('[data-setting=engineering_reviewed]').check()
 for i,(wp,se) in enumerate([('879','S'),('517','E')]):
  row=page.locator('#mdb-rows tr').nth(i);row.locator('[data-row=date]').fill('2025-08-23');row.locator('[data-row=gps_waypoint]').fill(wp)
  if se=='S':
   row.locator('[data-row=phase]').fill('R-Y-B')
   for c in ['r','y','b','neutral']:row.locator('[data-row=conductor_'+c+']').fill('W')
 page.get_by_role('button',name='Add S/E pair',exact=True).click()
 for i,(wp,se) in enumerate([('517','S'),('518','E')],start=2):
  row=page.locator('#mdb-rows tr').nth(i);row.locator('[data-row=date]').fill('2025-08-23');row.locator('[data-row=gps_waypoint]').fill(wp)
  if se=='S':
   row.locator('[data-row=phase]').fill('RYB')
   for c in ['r','y','b','neutral']:row.locator('[data-row=conductor_'+c+']').fill('W')
  else:row.locator('[data-consumer=rs]').fill('3');row.locator('[data-consumer=rl]').fill('1')
 page.locator('#save-mdb').click();page.wait_for_url('**/mdb-builder/*/edit')
 expect(page.locator('#mdb-rows tr')).to_have_count(4)
 expect(page.locator('#mdb-rows tr').nth(1).locator('[data-gpx-link]')).to_contain_text('30.464656')
 expect(page.locator('#mdb-rows tr').nth(3).locator('[data-consumer=rs]')).to_have_value('3')
 assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Desktop page overflow'
 page.screenshot(path=str(base/'tmp/mdb-inspection/form.png'),full_page=True)
 page.locator('#preview-mdb').click()
 expect(page.get_by_role('heading',name='Network ready to export')).to_be_visible()
 page.screenshot(path=str(base/'tmp/mdb-inspection/preview.png'),full_page=True)
 with page.expect_download() as dl:page.get_by_role('button',name='Create and download MDB').click()
 download=dl.value;download.save_as(str(base/'tmp/mdb-inspection/browser-generated.mdb'))
 print(json.dumps({'download':download.suggested_filename,'bytes':(base/'tmp/mdb-inspection/browser-generated.mdb').stat().st_size,'javascript_errors':errors,'pairs':page.locator('table tbody tr').count()}))
 page.get_by_role('link',name='Edit survey / fix links').click()
 page.set_viewport_size({'width':390,'height':844})
 assert page.evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Mobile page overflow'
 page.screenshot(path=str(base/'tmp/mdb-inspection/form-mobile.png'),full_page=True)
 browser.close()

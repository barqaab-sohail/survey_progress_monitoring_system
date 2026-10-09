import json, sqlite3
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).parent
errors=[]
with sync_playwright() as p:
    browser=p.chromium.launch(channel='chrome',headless=True)
    context=browser.new_context(viewport={'width':1440,'height':1000},accept_downloads=True,geolocation={'latitude':33.9,'longitude':73.4,'accuracy':12},permissions=['geolocation'])
    page=context.new_page()
    page.on('pageerror',lambda error: errors.append(str(error)))
    page.on('dialog',lambda dialog: dialog.accept())
    page.goto('http://127.0.0.1:8013/login')
    page.locator('[name=email]').fill('web-test@example.test')
    page.locator('[name=password]').fill('WebTest123!')
    page.locator('button[type=submit]').click()
    page.wait_for_url('http://127.0.0.1:8013/')
    page.get_by_role('link',name='Android Survey Web Test',exact=True).click()
    page.wait_for_function("!document.getElementById('new-survey').disabled")
    page.locator('#new-survey').click()
    page.get_by_role('button',name='Create survey',exact=True).click()
    page.locator('[data-path=transformer_code]').fill('WEB-TEST-0001')
    page.locator('[data-path="header.capacity_kva"]').fill('100')
    page.locator('[data-path="header.mounting"]').select_option('D.Pole')
    page.locator('[data-path="header.duty"]').select_option('General Duty')
    page.locator('#submit-survey').click()
    page.locator('#validation-errors').wait_for(state='visible')
    assert 'Add at least one' in page.locator('#validation-errors').inner_text()
    page.locator('[data-tab="1"]').click()
    page.get_by_role('button',name='Add S/E row',exact=True).click()
    page.locator('[data-path="rows.0.gps_waypoint"]').fill('0008')
    page.locator('[data-path="rows.0.group"]').fill('01')
    page.locator('[data-path="rows.0.conductor_r"]').fill('A')
    phase=page.locator('[data-path="rows.0.phase"]')
    assert phase.get_attribute('readonly') is not None
    assert phase.input_value()=='R'
    page.locator('[data-path="rows.0.conductor_y"]').fill('W')
    assert phase.input_value()=='RY'
    page.locator('[data-path="rows.0.conductor_b"]').fill('GN')
    assert phase.input_value()=='RYB'
    page.locator('[data-path="rows.0.conductor_neutral"]').fill('A')
    assert phase.input_value()=='RYB'
    page.locator('[data-path="rows.0.conductor_y"]').fill('')
    assert phase.input_value()=='RB'
    page.locator('[data-path="rows.0.conductor_b"]').fill('+')
    assert phase.input_value()=='R'
    page.locator('[data-path="rows.0.consumers.rs"]').fill('5')
    intersection=page.locator('[data-path="rows.0.intersection"]')
    assert intersection.get_attribute('type')=='checkbox'
    intersection.check()
    assert page.locator('[data-path="rows.0.consumers.rs"]').input_value()=='5'
    intersection.uncheck()
    intersection.check()
    page.get_by_role('button',name='Capture GPS',exact=True).click()
    page.wait_for_function("document.querySelector('[data-path=\"rows.0.latitude\"]').value === '33.9'")
    page.locator('[data-tab="2"]').click()
    page.get_by_role('button',name='Add solar installation',exact=True).click()
    page.locator('[data-path="solar.0.consumer_reference"]').fill('0000123')
    page.locator('[data-path="solar.0.installed_pv_kw"]').fill('5.5')
    page.locator('[data-tab="3"]').click()
    page.locator('#photo-upload').set_input_files({'name':'sketch.pdf','mimeType':'application/pdf','buffer':b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF'})
    page.wait_for_function("document.querySelector('.test-attachment') !== null")
    page.locator('#simulate-offline').check()
    page.locator('#submit-survey').click()
    page.wait_for_function("document.getElementById('editor-meta').textContent.includes('Waiting to sync')")
    page.reload()
    page.wait_for_function("document.querySelector('[data-open]') !== null")
    assert page.locator('#simulate-offline').is_checked()
    page.locator('[data-open]').first.click()
    assert page.locator('[data-path=transformer_code]').input_value()=='WEB-TEST-0001'
    page.locator('[data-tab="3"]').click()
    assert 'Waiting to upload' in page.locator('#survey-form').inner_text()
    page.locator('#simulate-offline').uncheck()
    page.wait_for_function("document.getElementById('editor-meta').textContent.includes('Synced')")
    assert 'Uploaded' in page.locator('#survey-form').inner_text()
    with page.expect_download() as download:
        page.locator('#export-payload').click()
    download.value.save_as(root/'android-payload.json')
    data=json.loads((root/'android-payload.json').read_text())
    assert data['rows'][0]['gps_waypoint']=='0008'
    assert data['rows'][0]['consumers']=={'rs':5}
    assert data['rows'][0]['intersection'] is True
    assert data['rows'][0]['phase']=='R'
    assert data['rows'][0]['latitude']==33.9
    assert data['solar'][0]['consumer_reference']=='0000123'
    assert data['base_revision']==1
    page.locator('[data-tab="1"]').click()
    page.evaluate('window.scrollTo(0,0)')
    page.screenshot(path=str(root/'desktop.png'),full_page=True)
    assert page.locator('#new-survey').is_hidden()
    assert page.locator('#load-server').is_hidden()
    page.locator('[data-path="rows.0.consumers.rs"]').fill('6')
    page.locator('#submit-survey').click()
    page.wait_for_function("document.getElementById('editor-meta').textContent.includes('revision 2')")
    page.set_viewport_size({'width':390,'height':844})
    page.wait_for_function("document.querySelector('.sidebar').getBoundingClientRect().right < 1")
    page.evaluate('window.scrollTo(0,0)')
    page.screenshot(path=str(root/'mobile.png'),full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    assert not errors, errors
    browser.close()
conn=sqlite3.connect(root/'field-web-isolated.sqlite')
assert conn.execute('select count(*) from field_survey_tests').fetchone()[0]>=1
assert conn.execute('select revision from field_survey_tests order by id desc limit 1').fetchone()[0]==2
assert conn.execute('select count(*) from field_survey_test_attachments').fetchone()[0]>=1
for table in ['field_surveys','field_survey_attachments','survey_daily_entries','mdb_daily_entries']:
    assert conn.execute(f'select count(*) from {table}').fetchone()[0]==0
print('PASS: validation, GPS, leading zeros, offline queue + reload, attachment retention/upload, JSON export, edit/resubmit, desktop/mobile layout and production isolation.')

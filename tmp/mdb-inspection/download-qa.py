from playwright.sync_api import sync_playwright,expect
from pathlib import Path
base=Path(r'E:\xampp\htdocs\survey_progress_monitoring_system')
with sync_playwright() as p:
 b=p.chromium.launch(executable_path=r'C:\Program Files\Google\Chrome\Application\chrome.exe',headless=True)
 page=b.new_page(accept_downloads=True)
 page.goto('http://127.0.0.1:8012/login');page.locator('[name=email]').fill('mdb-ui@example.test');page.locator('[name=password]').fill('QaOnlyPassword123!');page.locator('button[type=submit]').click();page.wait_for_url('http://127.0.0.1:8012/')
 page.goto('http://127.0.0.1:8012/mdb-builder/1/preview')
 with page.expect_download(timeout=20000) as dl:page.get_by_role('button',name='Create and download MDB').click()
 d=dl.value;d.save_as(str(base/'tmp/mdb-inspection/browser-generated.mdb'));print('Downloaded',d.suggested_filename,(base/'tmp/mdb-inspection/browser-generated.mdb').stat().st_size)
 b.close()

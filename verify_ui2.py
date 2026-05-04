import os
from playwright.sync_api import sync_playwright

def run_cuj(page):
    page.goto("http://127.0.0.1:8000/admin/configuracoes.php")
    page.wait_for_timeout(1000)
    page.screenshot(path="/home/jules/verification/screenshots/config.png")
    page.wait_for_timeout(500)

if __name__ == "__main__":
    os.makedirs("/home/jules/verification/screenshots", exist_ok=True)
    os.makedirs("/home/jules/verification/videos", exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            record_video_dir="/home/jules/verification/videos"
        )
        page = context.new_page()
        try:
            run_cuj(page)
        finally:
            context.close()
            browser.close()

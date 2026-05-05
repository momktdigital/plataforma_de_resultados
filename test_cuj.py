from playwright.sync_api import sync_playwright
import os

def run_cuj(page):
    page.goto("http://localhost:8000/admin/login.php")
    page.wait_for_timeout(1000)

    # Capturar a tela de login inicial
    page.screenshot(path="/tmp/screenshot1.png")
    page.wait_for_timeout(500)

    # Login (nós não configuramos hCaptcha no db ainda, então deve renderizar normal ou nada)
    page.get_by_label("Usuário").fill("admin")
    page.get_by_label("Senha").fill("admin")
    page.wait_for_timeout(500)

    page.get_by_role("button", name="Entrar no Sistema").click()
    page.wait_for_timeout(1000)

    # Capturar o dashboard administrativo (ou a tela se falhar)
    page.screenshot(path="/tmp/screenshot2.png")
    page.wait_for_timeout(500)

    # Ir para configurações
    page.goto("http://localhost:8000/admin/configuracoes.php")
    page.wait_for_timeout(1000)
    page.screenshot(path="/tmp/screenshot3.png")

if __name__ == "__main__":
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            record_video_dir="/tmp/videos"
        )
        page = context.new_page()
        try:
            run_cuj(page)
        finally:
            context.close()
            browser.close()

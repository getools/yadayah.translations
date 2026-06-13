#!/usr/bin/env python3
"""
bootstrap_word_online_auth.py — RUNS ON THE LINUX SERVER.

Headless sign-in to Microsoft 365 as the service account, capturing
the SharePoint session cookies to /opt/yada-www/secrets/word-online-auth.json.
The conversion script (docx_to_pdf_via_word_online.py) then uses that
saved auth state for every subsequent PDF render — no human in the loop.

Requirements on the tenant:
  - api@yada0001.onmicrosoft.com has NO MFA configured
  - Security Defaults are disabled
  - No conditional-access policies blocking unmanaged-device sign-in

Requirements on prod:
  - /opt/yada-www/secrets/word-online-creds.env contains:
       WORD_ONLINE_USER=api@yada0001.onmicrosoft.com
       WORD_ONLINE_PASSWORD=<current password>
  - chromium installed via `playwright install chromium`

Usage:
  bootstrap_word_online_auth.py                # uses defaults
  bootstrap_word_online_auth.py --debug        # screenshots each step
  bootstrap_word_online_auth.py --headed       # show the browser
                                               # (only works with $DISPLAY set)

The saved auth state file is good for ~30-90 days depending on tenant
policy. A weekly cron re-runs this script proactively (idempotent —
just refreshes the cookies).
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from pathlib import Path

try:
    from playwright.sync_api import (
        sync_playwright, TimeoutError as PlaywrightTimeoutError,
    )
except ImportError:
    sys.stderr.write("Missing dependency: pip install playwright; playwright install chromium\n")
    sys.exit(1)


CREDS_PATH = "/opt/yada-www/secrets/word-online-creds.env"
AUTH_STATE_PATH = "/opt/yada-www/secrets/word-online-auth.json"
DEBUG_DIR = "/tmp/word-online-auth-debug"

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
    "AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/130.0.0.0 Safari/537.36"
)

# A stable signed-in destination — once we land here we know auth succeeded.
SHAREPOINT_HOME = "https://yada0001-my.sharepoint.com"


def load_creds():
    if not os.path.exists(CREDS_PATH):
        sys.stderr.write(f"Creds file missing: {CREDS_PATH}\n")
        sys.exit(1)
    user = passwd = None
    with open(CREDS_PATH) as f:
        for ln in f:
            ln = ln.strip()
            if not ln or ln.startswith("#") or "=" not in ln:
                continue
            k, v = ln.split("=", 1)
            k, v = k.strip(), v.strip()
            if k == "WORD_ONLINE_USER":
                user = v
            elif k == "WORD_ONLINE_PASSWORD":
                passwd = v
    if not user or not passwd:
        sys.stderr.write(f"Creds file missing WORD_ONLINE_USER or WORD_ONLINE_PASSWORD\n")
        sys.exit(1)
    return user, passwd


def shot(page, debug, name):
    """Take a debug screenshot if --debug was passed."""
    if not debug:
        return
    Path(DEBUG_DIR).mkdir(parents=True, exist_ok=True)
    path = f"{DEBUG_DIR}/{int(time.time())}-{name}.png"
    try:
        page.screenshot(path=path, full_page=True)
        sys.stderr.write(f"  [debug] screenshot: {path}\n")
    except Exception as e:
        sys.stderr.write(f"  [debug] screenshot failed: {e}\n")


def sign_in(user, passwd, headed=False, debug=False):
    with sync_playwright() as p:
        # --no-sandbox is required when running as root on Linux.
        # --disable-blink-features=AutomationControlled hides the
        # navigator.webdriver=true marker that Microsoft uses to detect bots.
        launch_args = [
            "--no-sandbox",
            "--disable-dev-shm-usage",
            "--disable-blink-features=AutomationControlled",
        ]
        browser = p.chromium.launch(headless=not headed, args=launch_args)
        context = browser.new_context(
            viewport={"width": 1600, "height": 1000},
            user_agent=USER_AGENT,
            locale="en-US",
            timezone_id="America/St_Thomas",
        )

        # Further stealth — remove navigator.webdriver entirely
        context.add_init_script(
            "Object.defineProperty(navigator, 'webdriver', "
            "{get: () => undefined});"
        )

        page = context.new_page()

        sys.stderr.write(f"[bootstrap] navigating to {SHAREPOINT_HOME}\n")
        page.goto(SHAREPOINT_HOME, wait_until="domcontentloaded", timeout=60_000)
        shot(page, debug, "01-initial")

        # 1. Microsoft asks for the email (input[name="loginfmt"]).
        try:
            page.wait_for_selector('input[name="loginfmt"]', timeout=30_000)
        except PlaywrightTimeoutError:
            shot(page, debug, "01b-no-email-field")
            sys.stderr.write(f"[bootstrap] email field not found. URL: {page.url}\n")
            browser.close()
            sys.exit(1)

        sys.stderr.write(f"[bootstrap] entering user: {user}\n")
        page.fill('input[name="loginfmt"]', user)
        page.click('input[type="submit"]')
        shot(page, debug, "02-after-email")

        # 2. Password page (input[name="passwd"]).
        try:
            page.wait_for_selector('input[name="passwd"]', timeout=30_000)
        except PlaywrightTimeoutError:
            shot(page, debug, "02b-no-password-field")
            sys.stderr.write(f"[bootstrap] password field not found. URL: {page.url}\n")
            sys.stderr.write(f"[bootstrap] body excerpt: {page.locator('body').inner_text()[:500]}\n")
            browser.close()
            sys.exit(1)

        sys.stderr.write(f"[bootstrap] entering password\n")
        page.fill('input[name="passwd"]', passwd)
        page.click('input[type="submit"]')
        shot(page, debug, "03-after-password")

        # 3. Possible interstitials:
        #    (a) "Action Required: set up security info" — try to skip
        #    (b) "Stay signed in?" — click No or Yes (either works)
        #    (c) Direct redirect to SharePoint — done
        time.sleep(3)
        shot(page, debug, "04-after-submit")

        # Wait for the "Stay signed in?" page. The KMSI checkbox is its
        # unique marker — no other page in the flow has it. If we don't
        # see it, we're either stuck on an MFA challenge or hit an error
        # page; either way the bootstrap can't continue without help.
        sys.stderr.write("[bootstrap] waiting for Stay-signed-in? prompt...\n")
        try:
            page.wait_for_selector('input[id="KmsiCheckboxField"]', timeout=30_000)
            shot(page, debug, "05-kmsi-prompt")
        except PlaywrightTimeoutError:
            shot(page, debug, "05-no-kmsi")
            sys.stderr.write(
                "[bootstrap] never saw the Stay-signed-in? prompt — likely "
                "MFA challenge or unexpected page. "
                f"URL: {page.url[:200]}\n"
            )
            try:
                body = page.locator("body").inner_text()[:800]
                sys.stderr.write(f"[bootstrap] body: {body}\n")
            except Exception:
                pass
            browser.close()
            sys.exit(1)

        # 4. Click Yes (idSIButton9) and wait for navigation off the
        #    login domain. This is the explicit pattern that the
        #    diagnostic script confirmed works.
        sys.stderr.write("[bootstrap] clicking Yes on Stay-signed-in?...\n")
        with page.expect_navigation(timeout=60_000, wait_until="domcontentloaded"):
            page.click('input[id="idSIButton9"]')
        shot(page, debug, "06-after-yes")

        # Give SharePoint a moment to settle any post-redirect setup.
        time.sleep(5)
        shot(page, debug, "07-final")

        url = page.url
        if "login.microsoftonline.com" in url:
            sys.stderr.write(f"[bootstrap] still on login page after submit — auth FAILED. URL: {url}\n")
            body = page.locator("body").inner_text()[:1000]
            sys.stderr.write(f"[bootstrap] body excerpt: {body}\n")
            browser.close()
            sys.exit(1)

        if "sharepoint.com" not in url:
            sys.stderr.write(f"[bootstrap] unexpected post-auth URL: {url}\n")
            browser.close()
            sys.exit(1)

        sys.stderr.write(f"[bootstrap] signed in. Final URL: {url}\n")

        # Wait a touch longer so any deferred cookies are set
        time.sleep(3)

        # 5. Save auth state.
        Path(AUTH_STATE_PATH).parent.mkdir(parents=True, exist_ok=True)
        context.storage_state(path=AUTH_STATE_PATH)
        os.chmod(AUTH_STATE_PATH, 0o600)
        sz = os.path.getsize(AUTH_STATE_PATH)
        sys.stderr.write(f"[bootstrap] auth state saved: {AUTH_STATE_PATH} ({sz} bytes)\n")

        browser.close()


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.strip().splitlines()[0])
    ap.add_argument("--debug", action="store_true",
                    help=f"Write a screenshot for each step to {DEBUG_DIR}")
    ap.add_argument("--headed", action="store_true",
                    help="Run browser headed (requires $DISPLAY)")
    args = ap.parse_args(argv)

    user, passwd = load_creds()
    sign_in(user, passwd, headed=args.headed, debug=args.debug)
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""
docx_to_pdf_via_word_online.py — Headless Playwright fallback for
DOCX→PDF when Microsoft Graph hits its 60-second WRS render timeout.

The Microsoft Graph format=pdf endpoint is limited to ~60s of internal
render time by Microsoft's Word Conversion Service. Larger / more
complex DOCXes exceed this and fail with HTTP 500 / Timeout_TaskCanceled.

Word for the Web does NOT have this 60s cap — its UI waits as long as
the render takes, even multiple minutes. So we automate it:

  1. Upload the DOCX to the service account's OneDrive (via Graph — same
     code path used by docx_to_pdf_via_graph.py).
  2. Open the file in Word for the Web in a headless Chromium browser,
     pre-authenticated via the saved storage state.
  3. Trigger File → Save As → Download as PDF (UI automation).
  4. Wait for the PDF to download (no time cap on our side; we cap at 15 min).
  5. Save the downloaded PDF to the output path.
  6. Delete the temporary OneDrive copy.

Auth state lifecycle:
  - One-time bootstrap: run refresh_word_online_auth.py ON A WINDOWS
    MACHINE, sign in interactively, SCP the resulting word-online-auth.json
    to /opt/yada-www/secrets/ on prod.
  - Auth state expires every 30-90 days (depending on tenant policy).
    Symptom: this script's run errors with "redirected to sign-in page".
  - When that happens, re-run refresh_word_online_auth.py and replace
    the secrets file on prod.

Usage:
  docx_to_pdf_via_word_online.py --input book.docx --output book.pdf

Exit codes:
  0   success
  1   generic failure
  2   PDF validation failed (producer != Word, or empty)
  3   auth state expired / missing — re-bootstrap required
"""

from __future__ import annotations

import argparse
import os
import shutil
import sys
import time
from pathlib import Path

import requests

# Reuse the auth + upload code we already battle-tested.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import docx_to_pdf_via_graph as graph  # type: ignore

try:
    from playwright.sync_api import (
        sync_playwright, TimeoutError as PlaywrightTimeoutError,
    )
except ImportError:
    sys.stderr.write("Missing dependency: pip install playwright; playwright install chromium\n")
    sys.exit(1)

AUTH_STATE_PATH = "/opt/yada-www/secrets/word-online-auth.json"
DOWNLOAD_DIR = "/tmp/word-online-downloads"

# How long we'll let Word for the Web grind on a render before giving up.
PDF_DOWNLOAD_TIMEOUT_MS = 15 * 60 * 1000   # 15 minutes
PAGE_LOAD_TIMEOUT_MS = 90 * 1000           # 90 seconds for initial load
FILE_MENU_TIMEOUT_MS = 180 * 1000          # 180 seconds to find File menu (large docs need time to render)


# ── SharePoint web URL resolution ─────────────────────────────────────

def _get_file_web_url(item_id: str) -> str:
    """Fetch the SharePoint webUrl (the user-facing URL for the file)."""
    graph._load_env()
    user = graph._require("GRAPH_SERVICE_USER")
    headers = graph._headers()
    r = requests.get(
        f"{graph.GRAPH_ROOT}/users/{user}/drive/items/{item_id}",
        headers=headers, timeout=30,
    )
    r.raise_for_status()
    return r.json()["webUrl"]


# ── Word for the Web automation ───────────────────────────────────────

def _get_word_frame(page, timeout_ms=PAGE_LOAD_TIMEOUT_MS):
    """Word for the Web's actual editor UI lives inside an iframe named
    'WacFrame_Word_0' pointing at word-edit.officeapps.live.com. We need
    to switch into that frame to interact with the ribbon/menus."""
    sys.stderr.write("[word-online] waiting for WacFrame_Word_0 iframe...\n")
    page.wait_for_selector("iframe[name='WacFrame_Word_0']", timeout=timeout_ms)
    deadline = time.time() + (timeout_ms / 1000)
    while time.time() < deadline:
        for f in page.frames:
            if f.name == "WacFrame_Word_0":
                # Make sure the frame's URL has actually settled at word-edit
                if "word-edit.officeapps.live.com" in f.url:
                    sys.stderr.write(f"[word-online] found Word editor frame: {f.url[:120]}\n")
                    return f
        time.sleep(1)
    raise RuntimeError("Word editor iframe didn't reach word-edit.officeapps.live.com")


def _dismiss_overlays(frame, page=None):
    """Word for the Web shows a 'Your privacy option' dialog (and possibly
    other one-time dialogs) on first sign-in or after cookies are cleared.
    The dialog has a WACDialogOverlay div that intercepts pointer events,
    blocking any clicks on the ribbon. Close any open dialogs before
    proceeding.

    Pass page= to also sweep all page frames (e.g. when the dialog may
    render in a sibling frame rather than in the Word editor iframe)."""
    dismissed = 0
    candidates = [
        "button#WACDialogActionButton",   # generic Close on WAC dialog
        # The privacy dialog container often lacks role="dialog", so match
        # the button text directly — observed in production (May/Jun 2026).
        "button:has-text('Close')",
        "button:has-text('Got it')",
        "[role='dialog'] button:has-text('Close')",
        "[role='dialog'] button:has-text('OK')",
        "[role='dialog'] button:has-text('Got it')",
        "[role='dialog'] button:has-text('Accept')",
        "[role='alertdialog'] button:has-text('Close')",
        "[role='alertdialog'] button:has-text('OK')",
        "[role='alertdialog'] button:has-text('Got it')",
        "[role='alertdialog'] button:has-text('Accept')",
    ]
    frames_to_check = [frame]
    if page:
        for _f in page.frames:
            if _f is not frame:
                frames_to_check.append(_f)
    # Try multiple cycles in case dialogs queue up
    for _ in range(3):
        any_clicked = False
        for _fr in frames_to_check:
            for sel in candidates:
                try:
                    loc = _fr.locator(sel).first
                    if loc.is_visible(timeout=1500):
                        loc.click()
                        dismissed += 1
                        any_clicked = True
                        sys.stderr.write(f"[word-online] dismissed dialog via {sel!r}\n")
                        time.sleep(1)
                        break
                except (PlaywrightTimeoutError, Exception):
                    continue
            if any_clicked:
                break
        if not any_clicked:
            break
    if dismissed:
        sys.stderr.write(f"[word-online] dismissed {dismissed} overlay(s)\n")


def _trigger_pdf_download(page) -> str:
    """Navigate File menu inside the Word for the Web iframe and trigger
    Download as PDF. Returns the path to the downloaded PDF."""
    frame = _get_word_frame(page)
    # Give Word for the Web ~10s to finish rendering the ribbon and any
    # first-time dialogs that pop on top of it.
    sys.stderr.write("[word-online] giving Word for the Web 10s to settle...\n")
    time.sleep(10)
    # Dismiss any first-time / privacy / consent dialogs that the frame
    # shows on top of the ribbon — they block pointer events. Sweep all
    # frames so the privacy dialog is caught regardless of where it renders.
    _dismiss_overlays(frame, page=page)
    # The frame itself needs to finish booting — wait for the ribbon to
    # appear (File tab is the universal first ribbon item).
    file_candidates = [
        "button:has-text('File')",
        "[role='menuitem']:has-text('File')",
        "[aria-label='File']",
        "button[name='File']",
        "div.ribbonTabContainer:has-text('File')",
        # WAC ribbon tabs are <a> elements, not buttons
        "a:has-text('File')",
        "[role='tab']:has-text('File')",
        "li:has-text('File')",
        "[data-automationid='FileMenuButton']",
        "[aria-label='File Tab']",
        "td:has-text('File')",
    ]
    sys.stderr.write("[word-online] waiting for File button inside editor frame...\n")
    clicked_file = False
    deadline = time.time() + (FILE_MENU_TIMEOUT_MS / 1000)
    while time.time() < deadline and not clicked_file:
        for sel in file_candidates:
            try:
                loc = frame.locator(sel).first
                if loc.is_visible(timeout=2000):
                    # Try the click — if an overlay intercepts, dismiss
                    # it and try once more.
                    try:
                        loc.click(timeout=5000)
                        clicked_file = True
                        sys.stderr.write(f"[word-online] clicked File via {sel!r}\n")
                        break
                    except PlaywrightTimeoutError as e:
                        if "intercepts pointer events" in str(e) or "WACDialog" in str(e):
                            sys.stderr.write("[word-online] click intercepted; dismissing overlay\n")
                            _dismiss_overlays(frame, page=page)
                            # Retry click
                            try:
                                loc.click(timeout=5000)
                                clicked_file = True
                                sys.stderr.write(f"[word-online] clicked File via {sel!r} (after dismiss)\n")
                                break
                            except (PlaywrightTimeoutError, Exception):
                                continue
                        else:
                            continue
            except PlaywrightTimeoutError:
                continue
            except Exception:
                continue
        if not clicked_file:
            # Coordinate-based native click — page.mouse.click() is isTrusted=true.
            # JS dispatchEvent is isTrusted=false; Word Online's WAC ribbon requires
            # a trusted event to navigate the File backstage. This is the primary fix
            # for "clicked File via JS but backstage never opened" failures.
            try:
                coords = frame.evaluate(
                    "() => {"
                    " var tags=['a','button','span','div','li','td'];"
                    " for(var i=0;i<tags.length;i++){"
                    "  var els=document.querySelectorAll(tags[i]);"
                    "  for(var j=0;j<els.length;j++){"
                    "   var t=(els[j].innerText||els[j].textContent||'').trim();"
                    "   if(t==='File'){"
                    "    var r=els[j].getBoundingClientRect();"
                    "    if(r.width>0&&r.height>0&&r.top>=0&&r.top<500)"
                    "     return{x:r.left+r.width/2,y:r.top+r.height/2};"
                    "   }"
                    "  }"
                    " } return null;}"
                )
                if coords:
                    iframe_el = page.query_selector("iframe[name='WacFrame_Word_0']")
                    if iframe_el:
                        bb = iframe_el.bounding_box()
                        if bb:
                            px = bb['x'] + coords['x']
                            py = bb['y'] + coords['y']
                            page.mouse.move(px, py)
                            time.sleep(0.3)
                            page.mouse.click(px, py)
                            clicked_file = True
                            sys.stderr.write('[word-online] clicked File via native coordinate click\n')
                            break
            except Exception as _coord_e:
                sys.stderr.write(f'[word-online] coordinate click: {_coord_e}\n')
        if not clicked_file:
            # JS fallback: WAC <a> tabs pass visibility but fail css checks
            try:
                result = frame.evaluate(
                    "() => {"
                    " var tags=['a','button','li','td','div','span'];"
                    " for(var i=0;i<tags.length;i++){"
                    "  var els=document.querySelectorAll(tags[i]);"
                    "  for(var j=0;j<els.length;j++){"
                    "   var t=(els[j].innerText||els[j].textContent||'').trim();"
                    "   if(t==='File'){"
                    "    try{els[j].focus();}catch(e){}"
                    "    ['mouseenter','mouseover','mousedown','mouseup','click'].forEach(function(tp){"
                    "     els[j].dispatchEvent(new MouseEvent(tp,{bubbles:true,cancelable:true,view:window,buttons:1}));"
                    "    });"
                    "    return true;"
                    "   }"
                    "  }"
                    " } return false;}"
                )
                if result:
                    clicked_file = True
                    sys.stderr.write('[word-online] clicked File via JS (full mouse events)\n')
                    break
            except Exception as _js_e:
                sys.stderr.write(f'[word-online] JS fallback: {_js_e}\n')
            # Maybe the dialog has reappeared — try dismissing again (all frames)
            _dismiss_overlays(frame, page=page)
            time.sleep(2)
    if not clicked_file:
        for _f in page.frames:
            try:
                sys.stderr.write(f'[word-online] frame: {_f.name!r} {_f.url[:80]!r}\n')
            except Exception:
                pass
        path = Path(DOWNLOAD_DIR) / "debug-no-file-menu.png"
        try:
            page.screenshot(path=str(path), full_page=True, timeout=5000)
        except Exception:
            pass
        raise RuntimeError(f"could not find File menu in editor frame; screenshot at {path}")

    # File pane opens. The path is usually "Export" → "Download as PDF",
    # but sometimes the direct "Download as PDF" button is visible. Look
    # for and click the final PDF action.
    time.sleep(15)
    # Re-dismiss any dialog that appeared *after* File was clicked (e.g. the
    # "Your privacy option" overlay which re-surfaces mid-session and blocks
    # backstage pointer events). Also sweep all frames in case the privacy
    # dialog renders in a sibling frame.
    _dismiss_overlays(frame, page=page)
    download_pdf_candidates = [
        "button:has-text('Download as PDF')",
        "div[role='menuitem']:has-text('Download as PDF')",
        "[aria-label='Download as PDF']",
        "[aria-label*='Download as PDF']",
        "button:has-text('Download PDF')",
        "[role='menuitem']:has-text('Download a copy')",
        "button:has-text('Download a PDF')",
        "[role='menuitem']:has-text('Download as PDF')",
        "[role='menuitem']:has-text('Download a PDF')",
        "[aria-label*='PDF']",
        "button:has-text('Save as PDF')",
        "[role='menuitem']:has-text('Save as PDF')",
        # New Word Online format-picker UI (post-2024): after clicking
        # "Download a copy" the backstage shows a format picker with
        # radio/list items labelled "PDF (.pdf)" rather than a direct
        # "Download as PDF" action button.
        "li:has-text('PDF (.pdf)')",
        "[role='radio']:has-text('PDF (.pdf)')",
        "[role='option']:has-text('PDF (.pdf)')",
        "[role='radio']:has-text('PDF')",
        "[role='option']:has-text('PDF')",
        "button:has-text('PDF (.pdf)')",
        "[aria-label*='.pdf']",
    ]
    submenu_candidates = [
        "button:has-text('Export')",
        "[role='menuitem']:has-text('Export')",
        "button:has-text('Save As')",
        "[role='menuitem']:has-text('Save As')",
        "button:has-text('Save a Copy')",
        "[role='menuitem']:has-text('Save a Copy')",
        "button:has-text('Save a copy')",
        "[role='menuitem']:has-text('Save a copy')",
        "button:has-text('Download')",
        "[role='menuitem']:has-text('Download')",
    ]

    clicked_pdf = False
    # Try the direct one-click first — search both WacFrame and main page
    # (post-2024 Word Online may render the backstage outside the iframe).
    for ctx in [frame, page]:
        for sel in download_pdf_candidates:
            try:
                loc = ctx.locator(sel).first
                if loc.is_visible(timeout=3000):
                    loc.click()
                    clicked_pdf = True
                    ctx_label = "frame" if ctx is frame else "page"
                    sys.stderr.write(f"[word-online] clicked '{sel}' direct ({ctx_label})\n")
                    break
            except PlaywrightTimeoutError:
                continue
            except Exception:
                continue
        if clicked_pdf:
            break

    if not clicked_pdf:
        # Two-step: open Export/Save-As submenu first, then PDF.
        # Search both WacFrame and main page for the submenu entry.
        opened_submenu = False
        for ctx in [frame, page]:
            for save_sel in submenu_candidates:
                try:
                    loc = ctx.locator(save_sel).first
                    if loc.is_visible(timeout=3000):
                        loc.click()
                        ctx_label = "frame" if ctx is frame else "page"
                        sys.stderr.write(f"[word-online] opened submenu '{save_sel}' ({ctx_label})\n")
                        opened_submenu = True
                        break
                except (PlaywrightTimeoutError, Exception):
                    continue
            if opened_submenu:
                break
        # Wait longer for the format picker to appear — Word Online's
        # "Download a copy" picker takes 5-10s to render.
        time.sleep(8)
        for ctx in [frame, page]:
            for sel in download_pdf_candidates:
                try:
                    loc = ctx.locator(sel).first
                    if loc.is_visible(timeout=5000):
                        loc.click()
                        clicked_pdf = True
                        ctx_label = "frame" if ctx is frame else "page"
                        sys.stderr.write(f"[word-online] clicked '{sel}' from submenu ({ctx_label})\n")
                        break
                except (PlaywrightTimeoutError, Exception):
                    continue
            if clicked_pdf:
                break

    if not clicked_pdf:
        # JS fallback: scan all page frames AND the main page for the submenu
        # and PDF download element by innerText. Post-2024 Word Online may render
        # the backstage/format-picker in the main page context (outside WacFrame).
        def _js_click_by_text(page, frame, text):
            text_lower = text.lower()
            js = (
                "() => { var tags=['a','button','li','td','div','span','p'];"
                " for(var ti=0;ti<tags.length;ti++){"
                "  var els=document.querySelectorAll(tags[ti]);"
                "  for(var ei=0;ei<els.length;ei++){"
                "   var raw=(els[ei].innerText||els[ei].textContent||'').trim();"
                "   if(raw.length===0||raw.length>300) continue;"
                "   var t=raw.toLowerCase();"
                f"   if(t.indexOf({text_lower!r})!==-1){{els[ei].click();return raw;}}"
                "  }} return null;}"
            )
            # Search main page first (format picker may render outside iframes),
            # then WacFrame, then other iframes.
            search_pool = [('__main__', page), (frame.name, frame)] + [
                (ff.name, ff) for ff in page.frames if ff is not frame
            ]
            for ctx_name, ctx in search_pool:
                try:
                    hit = ctx.evaluate(js)
                    if hit:
                        return (ctx_name, hit)
                except Exception:
                    continue
            return None

        for sm_text in ['Export', 'Save As', 'Save a Copy', 'Save a copy', 'Download', 'download a copy']:
            r = _js_click_by_text(page, frame, sm_text)
            if r:
                sys.stderr.write(f"[word-online] JS opened submenu {r[1]!r} in frame {r[0]!r}\n")
                time.sleep(8)
                break

        for pdf_text in ['Download as PDF', 'Download PDF', 'Download a copy', 'Download a PDF',
                         'Save as PDF', 'as pdf', 'pdf (.pdf)', '.pdf']:
            r = _js_click_by_text(page, frame, pdf_text)
            if r:
                sys.stderr.write(f"[word-online] JS clicked PDF download {r[1]!r} in frame {r[0]!r}\n")
                clicked_pdf = True
                break

    if not clicked_pdf:
        # Alt+F keyboard shortcut as last resort to open File backstage.
        try:
            sys.stderr.write("[word-online] trying Alt+F keyboard shortcut for File backstage...\n")
            try:
                frame.click("body", timeout=3000)
            except Exception:
                pass
            time.sleep(1)
            page.keyboard.press("Alt+F")
            time.sleep(5)
            _dismiss_overlays(frame, page=page)
            for ctx in [frame, page]:
                for _sel in download_pdf_candidates:
                    try:
                        _loc = ctx.locator(_sel).first
                        if _loc.is_visible(timeout=3000):
                            _loc.click()
                            clicked_pdf = True
                            ctx_label = "frame" if ctx is frame else "page"
                            sys.stderr.write(f"[word-online] Alt+F + {_sel!r} clicked ({ctx_label})\n")
                            break
                    except Exception:
                        continue
                if clicked_pdf:
                    break
            if not clicked_pdf:
                for _sm in ['Export', 'Save As', 'Save a Copy', 'Download']:
                    _r = _js_click_by_text(page, frame, _sm)
                    if _r:
                        sys.stderr.write(f"[word-online] Alt+F opened submenu {_r[1]!r}\n")
                        time.sleep(8)
                        break
                for _pt in ['Download as PDF', 'Download PDF', 'Download a copy', 'Save as PDF',
                            'as pdf', 'pdf (.pdf)', '.pdf']:
                    _r = _js_click_by_text(page, frame, _pt)
                    if _r:
                        clicked_pdf = True
                        sys.stderr.write(f"[word-online] Alt+F+JS clicked PDF {_r[1]!r}\n")
                        break
        except Exception as _altf_e:
            sys.stderr.write(f"[word-online] Alt+F fallback: {_altf_e}\n")

    if not clicked_pdf:
        path = Path(DOWNLOAD_DIR) / "debug-no-pdf-button.png"
        try:
            page.screenshot(path=str(path), full_page=True, timeout=5000)
        except Exception:
            pass
        # Dump backstage frame text to diagnose which UI elements are present
        # (include main page and all iframes for full picture)
        for _dbg_ctx_name, _dbg_ctx in [('__main__', page)] + [
            (_f.name, _f) for _f in page.frames
        ]:
            try:
                _txt = _dbg_ctx.evaluate("() => document.body ? document.body.innerText.slice(0, 3000) : ''")
                if _txt and len(_txt.strip()) > 10:
                    sys.stderr.write(f"[word-online] backstage frame {_dbg_ctx_name!r} text: {_txt[:800]!r}\n")
            except Exception:
                pass
        raise RuntimeError(f"could not find Download-as-PDF button; screenshot at {path}")

    # If we clicked a format-picker radio button (e.g. "PDF (.pdf)") rather than
    # a direct action button, the picker shows a "Download" confirm button.
    # Try to click it immediately so the PDF conversion starts; the "wait for
    # ready dialog" loop below handles the case where no confirm is needed.
    time.sleep(3)
    for ctx in [page, frame]:
        for confirm_sel in [
            "[role='dialog'] button:has-text('Download')",
            "button.ms-Button--primary:has-text('Download')",
            "[data-is-focusable] button:has-text('Download')",
        ]:
            try:
                loc = ctx.locator(confirm_sel).first
                if loc.is_visible(timeout=2000):
                    loc.click()
                    ctx_label = "page" if ctx is page else "frame"
                    sys.stderr.write(f"[word-online] clicked format confirm {confirm_sel!r} ({ctx_label})\n")
                    break
            except Exception:
                continue

    # ── Wait for the "Your document is ready" dialog ──
    # Word for the Web renders the PDF server-side (3-10 min typical),
    # then shows a dialog with a Download button that triggers the
    # actual browser download. The dialog text varies; we look for a
    # button labelled "Download" inside any visible dialog.
    sys.stderr.write(
        f"[word-online] waiting for 'Document ready' dialog "
        f"(up to {PDF_DOWNLOAD_TIMEOUT_MS // 60_000} min for render)...\n"
    )
    deadline = time.time() + (PDF_DOWNLOAD_TIMEOUT_MS / 1000)
    ready_clicked = False
    while time.time() < deadline:
        # Look for any "Download" button inside a dialog — search both
        # main page and WacFrame since the ready dialog may render outside
        # the iframe in newer Word Online versions.
        for sel in [
            "[role='dialog'] button:has-text('Download')",
            "[role='dialog'] [aria-label='Download']",
            "button:has-text('Your document is ready')",  # rare
        ]:
            for ctx in [page, frame]:
                try:
                    loc = ctx.locator(sel).first
                    if loc.is_visible(timeout=2000):
                        # Capture the download as we click
                        with page.expect_download(timeout=120_000) as dl_info:
                            loc.click()
                            ctx_label = "page" if ctx is page else "frame"
                            sys.stderr.write(
                                f"[word-online] clicked Download via {sel!r} ({ctx_label}) — "
                                f"after {(deadline - time.time() + PDF_DOWNLOAD_TIMEOUT_MS/1000):.0f}s of waiting\n"
                            )
                        download = dl_info.value
                        suggested = download.suggested_filename
                        dl_path = Path(DOWNLOAD_DIR) / suggested
                        download.save_as(str(dl_path))
                        sys.stderr.write(f"[word-online] download saved to {dl_path}\n")
                        return str(dl_path)
                except PlaywrightTimeoutError:
                    continue
                except Exception:
                    continue
        # Also handle the case where render fails with an error dialog
        for ctx in [page, frame]:
            try:
                err = ctx.locator(
                    "[role='dialog']:has-text('error'), "
                    "[role='dialog']:has-text('failed'), "
                    "[role='dialog']:has-text('unable')"
                ).first
                if err.is_visible(timeout=1000):
                    err_text = err.inner_text()[:300]
                    try:
                        page.screenshot(path=str(Path(DOWNLOAD_DIR) / "debug-render-error.png"), timeout=5000)
                    except Exception:
                        pass
                    raise RuntimeError(f"Word for the Web reported an error: {err_text!r}")
            except PlaywrightTimeoutError:
                pass
            except RuntimeError:
                raise
            except Exception:
                pass
        time.sleep(10)

    try:
        page.screenshot(path=str(Path(DOWNLOAD_DIR) / "debug-render-timeout.png"), timeout=5000)
    except Exception:
        pass
    raise RuntimeError(
        f"PDF render didn't complete within {PDF_DOWNLOAD_TIMEOUT_MS // 60_000} min"
    )


def _auth_state_present() -> bool:
    return os.path.exists(AUTH_STATE_PATH) and os.path.getsize(AUTH_STATE_PATH) > 0


def _signed_in(page) -> bool:
    """Heuristic: signed in if we're on a SharePoint URL and not redirected
    to login.microsoftonline.com."""
    return "login.microsoftonline.com" not in page.url and "sharepoint.com" in page.url


def convert_one(docx_path: Path, pdf_path: Path) -> None:
    if not _auth_state_present():
        sys.stderr.write(
            f"[word-online] auth state missing at {AUTH_STATE_PATH}. "
            "Run refresh_word_online_auth.py on a Windows machine and SCP the result.\n"
        )
        sys.exit(3)

    Path(DOWNLOAD_DIR).mkdir(parents=True, exist_ok=True)

    # SharePoint holds file locks for 5-30 min after a session ends.
    # Re-running this script with the same DOCX name commonly hits 423
    # Locked. Avoid that by uploading with a unique timestamped name; the
    # render is name-agnostic.
    import shutil as _shutil, tempfile as _tempfile
    sys.stderr.write(f"[word-online] uploading {docx_path.name} to SharePoint via Graph (timestamped)...\n")
    graph._load_env()
    folder_id = graph._ensure_folder()
    ts_name = f"{docx_path.stem}__{int(time.time())}__{os.getpid()}{docx_path.suffix}"
    tmp_docx = Path(_tempfile.gettempdir()) / ts_name
    _shutil.copy2(docx_path, tmp_docx)
    try:
        size = tmp_docx.stat().st_size
        if size < graph.LARGE_UPLOAD_THRESHOLD:
            item_id = graph._upload_small(tmp_docx, folder_id)
        else:
            item_id = graph._upload_large(tmp_docx, folder_id)
        sys.stderr.write(f"[word-online] uploaded as {ts_name}; item_id={item_id}\n")
    finally:
        try: tmp_docx.unlink()
        except Exception: pass

    try:
        web_url = _get_file_web_url(item_id)
        sys.stderr.write(f"[word-online] file webUrl: {web_url}\n")

        with sync_playwright() as p:
            browser = p.chromium.launch(
                headless=True,
                args=["--no-sandbox", "--disable-dev-shm-usage"],
            )
            context = browser.new_context(
                storage_state=AUTH_STATE_PATH,
                viewport={"width": 1600, "height": 1000},
                accept_downloads=True,
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/130.0.0.0 Safari/537.36"
                ),
            )
            page = context.new_page()

            # Open in Word for the Web. The ?action=default param on a
            # .docx in SharePoint opens it in the Word web app.
            url = web_url + ("&" if "?" in web_url else "?") + "action=default"
            sys.stderr.write(f"[word-online] navigating to {url}\n")
            page.goto(url, wait_until="domcontentloaded", timeout=PAGE_LOAD_TIMEOUT_MS)

            if not _signed_in(page):
                browser.close()
                sys.stderr.write(
                    "[word-online] redirected to sign-in — auth state expired. "
                    "Re-bootstrap via refresh_word_online_auth.py on Windows.\n"
                )
                sys.exit(3)

            # _trigger_pdf_download handles waiting for the editor iframe
            # to load, finding File inside it, and triggering the PDF
            # download. No outer wait needed here.
            tmp_pdf = _trigger_pdf_download(page)
            browser.close()

        # Move into final location with validation.
        pdf_path.parent.mkdir(parents=True, exist_ok=True)
        shutil.move(tmp_pdf, pdf_path)
        problems = graph.validate_pdf(pdf_path)
        if problems:
            for p_msg in problems:
                sys.stderr.write(f"[word-online] VALIDATION: {p_msg}\n")
            sys.exit(2)
        sys.stderr.write(f"[word-online] OK: {pdf_path} ({pdf_path.stat().st_size} bytes)\n")
    finally:
        try:
            graph._delete_item(item_id)
        except Exception:
            pass


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.strip().splitlines()[0])
    ap.add_argument("--input", "-i", required=True, help="Path to .docx")
    ap.add_argument("--output", "-o", required=True, help="Path to write .pdf")
    args = ap.parse_args(argv)
    convert_one(Path(args.input), Path(args.output))
    return 0


if __name__ == "__main__":
    sys.exit(main())

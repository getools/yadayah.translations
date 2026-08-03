#!/usr/bin/env python3
"""
docx_to_pdf_via_graph.py — Convert a .docx to PDF using the Microsoft Graph
API (i.e., Word's own cloud rendering engine). Bit-for-bit identical to
desktop Word's "Save As PDF". Replaces OnlyOffice / LibreOffice / Word-COM
as the canonical PDF generator for the book pipeline.

Why this script exists:
    OnlyOffice's PDF exporter collapses condensed-spacing justified lines
    into single positionally-kerned glyph streams without explicit space
    characters. PyMuPDF can't recover the spaces. Snake and Satanic each
    had 200+ paragraphs of run-on text in search results. Word's PDF
    exporter never does this — it always emits explicit spaces. The
    Microsoft Graph API exposes Word's exporter as a REST endpoint.

Usage:
    python3 docx_to_pdf_via_graph.py --input book.docx --output book.pdf [--validate]
    python3 docx_to_pdf_via_graph.py --batch <dir_of_docx> --output-dir <dir>

Env vars (loaded from /opt/yada-www/secrets/graph.env if present):
    GRAPH_TENANT_ID      — Azure AD tenant GUID
    GRAPH_CLIENT_ID      — App registration client ID
    GRAPH_CLIENT_SECRET  — App registration client secret
    GRAPH_SERVICE_USER   — UPN of the M365 service account (owns the
                           OneDrive used as a temp workspace)

Exit codes:
    0  — success
    1  — generic failure (network, auth, etc.)
    2  — validation failure (producer != Word, or run-on tokens > threshold)
"""

from __future__ import annotations

import argparse
import os
import random
import re
import sys
import time
from pathlib import Path

import requests
try:
    import msal
except ImportError:
    sys.stderr.write("Missing dependency: pip install msal\n")
    sys.exit(1)

try:
    import fitz  # PyMuPDF, for post-conversion validation
except ImportError:
    fitz = None


# ── Config ────────────────────────────────────────────────────────────

SECRETS_PATH = "/opt/yada-www/secrets/graph.env"
GRAPH_ROOT = "https://graph.microsoft.com/v1.0"
CONVERSIONS_FOLDER = "yada-pipeline-conversions"  # in the service user's OneDrive

# Upload chunk size for large files (must be multiple of 320 KB)
LARGE_UPLOAD_THRESHOLD = 4 * 1024 * 1024   # 4 MB — Graph small-upload limit
UPLOAD_CHUNK = 5 * 320 * 1024              # 1.6 MB chunks

# Post-upload settle delay before requesting conversion. SharePoint's
# indexer needs a moment after a large-file upload session completes
# before the media-transform service can see the file. Without this,
# the convert request often 500s on freshly-uploaded large DOCXes.
POST_UPLOAD_SETTLE_SEC = 15

# Per-request network timeouts. (connect_timeout, read_timeout). Generous
# so Microsoft's media render isn't cut short, bounded so we detect
# true hangs.
UPLOAD_TIMEOUT  = (30, 600)    # 10 min read — large session chunks
CONVERT_TIMEOUT = (30, 900)    # 15 min read — actual Word rendering
DELETE_TIMEOUT  = (30, 60)

# Validator thresholds
MAX_RUNON_PER_SAMPLE = 3   # max run-on tokens per sampled page
SAMPLE_PAGE_COUNT    = 50  # how many pages to sample for run-on check


# ── Env loader ────────────────────────────────────────────────────────

def _load_env():
    """Populate os.environ from /opt/yada-www/secrets/graph.env if present."""
    if not os.path.exists(SECRETS_PATH):
        return
    with open(SECRETS_PATH, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip('"').strip("'")
            os.environ.setdefault(k, v)


def _require(name: str) -> str:
    val = os.environ.get(name)
    if not val:
        raise RuntimeError(
            f"Missing env var {name}. Set it in {SECRETS_PATH} or the shell."
        )
    return val


# ── Auth ──────────────────────────────────────────────────────────────

_token_cache = {"token": None, "expires_at": 0.0}

def get_access_token() -> str:
    """Acquire (and cache) an app-only bearer token via client_credentials."""
    now = time.time()
    if _token_cache["token"] and now < _token_cache["expires_at"] - 60:
        return _token_cache["token"]

    tenant = _require("GRAPH_TENANT_ID")
    client_id = _require("GRAPH_CLIENT_ID")
    client_secret = _require("GRAPH_CLIENT_SECRET")

    app = msal.ConfidentialClientApplication(
        client_id=client_id,
        client_credential=client_secret,
        authority=f"https://login.microsoftonline.com/{tenant}",
    )
    result = app.acquire_token_for_client(
        scopes=["https://graph.microsoft.com/.default"]
    )
    if "access_token" not in result:
        raise RuntimeError(
            f"Token acquisition failed: {result.get('error')!r} "
            f"{result.get('error_description')!r}"
        )
    _token_cache["token"] = result["access_token"]
    _token_cache["expires_at"] = now + result.get("expires_in", 3600)
    return _token_cache["token"]


def _headers(extra: dict | None = None) -> dict:
    h = {"Authorization": f"Bearer {get_access_token()}"}
    if extra:
        h.update(extra)
    return h


# ── Graph file operations ─────────────────────────────────────────────

def _user_drive_root() -> str:
    user = _require("GRAPH_SERVICE_USER")
    return f"{GRAPH_ROOT}/users/{user}/drive"


def _ensure_folder() -> str:
    """Create the conversions folder if missing. Returns its DriveItem id."""
    root = _user_drive_root()
    # Try to fetch by path first.
    r = requests.get(f"{root}/root:/{CONVERSIONS_FOLDER}", headers=_headers())
    if r.status_code == 200:
        return r.json()["id"]
    # Create it.
    r = requests.post(
        f"{root}/root/children",
        headers=_headers({"Content-Type": "application/json"}),
        json={
            "name": CONVERSIONS_FOLDER,
            "folder": {},
            "@microsoft.graph.conflictBehavior": "replace",
        },
    )
    r.raise_for_status()
    return r.json()["id"]


def _upload_small(docx_path: Path, folder_id: str) -> str:
    """Upload a <4MB DOCX in one PUT. Returns DriveItem id."""
    root = _user_drive_root()
    name = docx_path.name
    url = f"{root}/items/{folder_id}:/{name}:/content"
    with open(docx_path, "rb") as f:
        r = requests.put(
            url,
            headers=_headers({
                "Content-Type": (
                    "application/vnd.openxmlformats-officedocument."
                    "wordprocessingml.document"
                )
            }),
            data=f,
            timeout=UPLOAD_TIMEOUT,
        )
    r.raise_for_status()
    return r.json()["id"]


def _upload_large(docx_path: Path, folder_id: str) -> str:
    """Upload a >=4MB DOCX via upload session."""
    root = _user_drive_root()
    name = docx_path.name
    session_url = f"{root}/items/{folder_id}:/{name}:/createUploadSession"
    session_body = {"item": {"@microsoft.graph.conflictBehavior": "replace",
                             "name": name}}

    # 1. Create upload session. If Graph returns 409 it means a stale
    #    upload session exists for this file path (a previous failed attempt
    #    that never committed). Delete the existing OneDrive item to cancel
    #    it, then retry once -- this is the only reliable recovery path.
    def _try_create_session() -> requests.Response:
        return requests.post(
            session_url,
            headers=_headers({"Content-Type": "application/json"}),
            json=session_body,
        )

    r = _try_create_session()
    if r.status_code == 409:
        sys.stderr.write(
            "[graph] 409 on createUploadSession -- stale session detected; "
            "deleting existing item and retrying\n"
        )
        try:
            item_r = requests.get(
                f"{root}/items/{folder_id}:/{name}",
                headers=_headers(),
                timeout=30,
            )
            if item_r.status_code == 200:
                _delete_item(item_r.json()["id"])
                sys.stderr.write("[graph] deleted stale item; retrying session\n")
        except Exception as de:
            sys.stderr.write(f"[graph] pre-delete attempt failed: {de}\n")
        r = _try_create_session()
    r.raise_for_status()
    upload_url = r.json()["uploadUrl"]

    # 2. Stream chunks
    size = docx_path.stat().st_size
    with open(docx_path, "rb") as f:
        offset = 0
        while offset < size:
            chunk = f.read(UPLOAD_CHUNK)
            end = offset + len(chunk) - 1
            cr = requests.put(
                upload_url,
                headers={
                    "Content-Length": str(len(chunk)),
                    "Content-Range": f"bytes {offset}-{end}/{size}",
                },
                data=chunk,
                timeout=UPLOAD_TIMEOUT,
            )
            if cr.status_code in (200, 201):
                return cr.json()["id"]
            if cr.status_code != 202:
                cr.raise_for_status()
            offset += len(chunk)
    raise RuntimeError("Upload session ended without a final 201/200")


def _convert_to_pdf(item_id: str, output_path: Path) -> None:
    """Stream the PDF rendering of the uploaded DOCX to output_path."""
    root = _user_drive_root()
    url = f"{root}/items/{item_id}/content?format=pdf"
    # The endpoint typically returns a 302 redirect to the rendered blob;
    # requests follows redirects by default, so we just stream the result.
    # Generous read timeout — Word rendering of a complex 6-7 MB docx
    # can legitimately take minutes inside Microsoft's media service.
    r = requests.get(
        url, headers=_headers(),
        stream=True, allow_redirects=True,
        timeout=CONVERT_TIMEOUT,
    )
    r.raise_for_status()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with open(output_path, "wb") as f:
        for chunk in r.iter_content(chunk_size=1024 * 1024):
            if chunk:
                f.write(chunk)


def _delete_item(item_id: str) -> None:
    root = _user_drive_root()
    try:
        requests.delete(f"{root}/items/{item_id}", headers=_headers(),
                        timeout=DELETE_TIMEOUT)
    except Exception:
        pass  # Best-effort — don't raise on cleanup failures.


# ── Validator ─────────────────────────────────────────────────────────

def validate_pdf(pdf_path: Path) -> list[str]:
    """Return list of validation problems. Empty list = pass."""
    problems: list[str] = []
    if fitz is None:
        problems.append("PyMuPDF not installed; cannot validate")
        return problems

    doc = fitz.open(pdf_path)
    try:
        meta = doc.metadata or {}
        producer = (meta.get("producer") or "") + " " + (meta.get("creator") or "")
        if "Microsoft" not in producer and "Word" not in producer:
            problems.append(
                f"producer/creator does not mention Microsoft/Word: {producer!r}"
            )

        if len(doc) == 0:
            problems.append("PDF has zero pages")
            return problems

        # Blank PDF check: Word Online rendering an empty DOCX produces a
        # 1-page blank PDF that passes the creator check but has no text.
        # Sample the first 5 pages; real books have many characters per page.
        first_pages = min(5, len(doc))
        sampled_text = "".join(doc[p].get_text("text", sort=True) for p in range(first_pages))
        if len(sampled_text.strip()) < 100:
            problems.append(
                f"PDF appears blank: {len(doc)} pages with only "
                f"{len(sampled_text.strip())} chars across first {first_pages} pages "
                f"— likely rendered from an empty/truncated DOCX"
            )

        n = min(SAMPLE_PAGE_COUNT, len(doc))
        sample = random.sample(range(len(doc)), n)
        runon_re = re.compile(r"[a-z][.,][A-Z][a-z]")
        total_runons = 0
        for pn in sample:
            text = doc[pn].get_text("text", sort=True)
            total_runons += len(runon_re.findall(text))
        threshold = MAX_RUNON_PER_SAMPLE * n
        if total_runons > threshold:
            problems.append(
                f"{total_runons} run-on tokens across {n} sampled pages "
                f"(threshold {threshold}) — likely a PDF rendering regression"
            )
    finally:
        doc.close()
    return problems


# ── High-level conversion ─────────────────────────────────────────────

def _is_retryable_http(exc: Exception) -> bool:
    """5xx and 429 are worth retrying; 4xx/auth issues are not."""
    if isinstance(exc, requests.HTTPError):
        sc = exc.response.status_code if exc.response is not None else 0
        return sc in (429, 500, 502, 503, 504, 507, 509)
    # ConnectionError / Timeout / ChunkedEncodingError — transient by nature.
    if isinstance(exc, (requests.ConnectionError, requests.Timeout,
                        requests.exceptions.ChunkedEncodingError)):
        return True
    return False


def convert_one(docx_path: Path, pdf_path: Path, *, do_validate: bool = True,
                max_retries: int = 4) -> None:
    """Upload → settle → render → download → delete. Differentiates
    retryable (5xx, timeout, connection) from fatal (auth, 4xx) errors.
    Backoff sequence designed for Microsoft's media-transform service
    which routinely needs 30-600s to recover from a transient 500 on
    larger files. After upload, waits POST_UPLOAD_SETTLE_SEC for
    SharePoint to index before requesting conversion — without this,
    the convert request often 500s immediately on freshly-uploaded
    larger DOCXes.

    Backoffs by attempt:  20s, 45s, 90s (capped). Total worst-case wait:
    ~2.5 min before giving up. Time-to-completion for a healthy file:
    ~15-30 seconds.

    The ladder is deliberately short: this converter is the *primary*
    path, but the book-pipeline worker has a working Word-for-the-Web
    fallback (~28 min/render). During a sustained Graph media-transform
    outage (persistent 500s), grinding through a ~70-min retry ladder
    per book before failing over just serialized ~1 extra hour onto every
    volume in the queue for no gain. A few short retries still ride out a
    genuinely transient single 5xx; anything longer belongs in the
    fallback, not here.

    On retry, re-uploads the DOCX (cheap relative to the convert step) so
    we don't depend on the prior upload's lifetime in OneDrive.
    """
    _load_env()
    folder_id = _ensure_folder()

    # Backoffs after attempt N (index N-1). Microsoft typically recovers
    # within 2-3 minutes for transient media-transform 5xx; if it hasn't
    # by then, the Word-for-the-Web fallback is the right escalation.
    backoffs = [20.0, 45.0, 90.0]
    last_exc: Exception | None = None
    item_id: str | None = None

    for attempt in range(1, max_retries + 1):
        try:
            sys.stderr.write(
                f"[graph] attempt {attempt}/{max_retries}: uploading {docx_path.name}\n"
            )
            size = docx_path.stat().st_size
            if size < LARGE_UPLOAD_THRESHOLD:
                item_id = _upload_small(docx_path, folder_id)
            else:
                item_id = _upload_large(docx_path, folder_id)
            # Post-upload settle delay — see POST_UPLOAD_SETTLE_SEC.
            sys.stderr.write(
                f"[graph] uploaded; settling for {POST_UPLOAD_SETTLE_SEC}s "
                f"before convert\n"
            )
            time.sleep(POST_UPLOAD_SETTLE_SEC)
            try:
                sys.stderr.write(f"[graph] converting → {pdf_path.name}\n")
                _convert_to_pdf(item_id, pdf_path)
            finally:
                _delete_item(item_id)
                item_id = None
            break
        except Exception as e:
            last_exc = e
            sc = ""
            if isinstance(e, requests.HTTPError) and e.response is not None:
                sc = f" (HTTP {e.response.status_code})"
            sys.stderr.write(f"[graph] failed{sc}: {type(e).__name__}: {str(e)[:200]}\n")
            # Always best-effort cleanup of an orphaned upload.
            if item_id:
                try: _delete_item(item_id)
                except Exception: pass
                item_id = None
            if not _is_retryable_http(e):
                sys.stderr.write("[graph] error is not retryable — giving up\n")
                raise
            if attempt < max_retries:
                wait = backoffs[min(attempt - 1, len(backoffs) - 1)]
                sys.stderr.write(f"[graph] sleeping {wait:.0f}s before retry\n")
                time.sleep(wait)
    else:
        raise last_exc or RuntimeError("Graph conversion failed after retries")

    if do_validate:
        problems = validate_pdf(pdf_path)
        if problems:
            for p in problems:
                sys.stderr.write(f"[graph] VALIDATION: {p}\n")
            raise SystemExit(2)
    sys.stderr.write(f"[graph] OK: {pdf_path}\n")


# ── CLI ───────────────────────────────────────────────────────────────

def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.strip().splitlines()[0])
    g1 = ap.add_argument_group("single conversion")
    g1.add_argument("--input", "-i", help="Path to .docx")
    g1.add_argument("--output", "-o", help="Path to write .pdf")
    g2 = ap.add_argument_group("batch conversion")
    g2.add_argument("--batch", help="Directory of .docx files to convert")
    g2.add_argument("--output-dir", help="Directory to write .pdf files (batch mode)")
    ap.add_argument("--no-validate", action="store_true",
                    help="Skip producer + run-on validation")
    args = ap.parse_args(argv)

    do_validate = not args.no_validate

    if args.batch:
        if not args.output_dir:
            ap.error("--batch requires --output-dir")
        in_dir = Path(args.batch)
        out_dir = Path(args.output_dir)
        out_dir.mkdir(parents=True, exist_ok=True)
        failures = []
        all_docx = sorted(in_dir.glob("*.docx"))
        # Skip files whose PDF already exists in the output dir (idempotent
        # resumption — if you re-run after a partial batch, completed files
        # are not re-converted).
        pending = [d for d in all_docx if not (out_dir / (d.stem + ".pdf")).exists()]
        if len(pending) < len(all_docx):
            sys.stderr.write(
                f"[graph] resume: {len(all_docx) - len(pending)} already done, "
                f"{len(pending)} remaining\n"
            )
        for i, docx in enumerate(pending, 1):
            sys.stderr.write(f"\n[graph] === {i}/{len(pending)} : {docx.name} ===\n")
            pdf = out_dir / (docx.stem + ".pdf")
            try:
                convert_one(docx, pdf, do_validate=do_validate)
                # Cool-down between files — avoids tripping Microsoft's
                # rate limits when running 30+ conversions back-to-back.
                if i < len(pending):
                    time.sleep(10)
            except SystemExit:
                failures.append((docx.name, "validation failed"))
            except Exception as e:
                failures.append((docx.name, repr(e)))
        if failures:
            sys.stderr.write(f"[graph] {len(failures)} failures:\n")
            for name, reason in failures:
                sys.stderr.write(f"  {name}: {reason}\n")
            return 1
        return 0

    if not args.input or not args.output:
        ap.error("--input and --output required (or use --batch + --output-dir)")
    convert_one(Path(args.input), Path(args.output), do_validate=do_validate)
    return 0


if __name__ == "__main__":
    sys.exit(main())

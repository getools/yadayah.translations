<?php
/**
 * Admin API for Books (Series + Volumes).
 * GET                — list all series with their volumes
 * GET ?scrolls=1     — list scrolls for dropdown
 * PUT ?key=N         — update a volume
 * PUT ?series_key=N  — update a series
 * POST               — create a new volume
 * POST ?action=add_series — create a new series
 * DELETE ?key=N      — delete a volume
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];
$key = (int)($_GET['key'] ?? 0);

if ($method === 'GET' && isset($_GET['scrolls'])) {
    $stmt = $db->query("SELECT cite_book_key, cite_book_hebrew FROM yy_cite_book ORDER BY cite_book_sort");
    jsonResponse(['scrolls' => $stmt->fetchAll()]);
}

if ($method === 'GET') {
    $series = $db->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM yy_volume v WHERE v.series_key = s.series_key) AS volume_count
        FROM yy_series s
        ORDER BY s.series_sort, s.series_key
    ")->fetchAll();

    // paragraph_count_live / translation_count_live are now denormalized
    // columns on yy_volume, kept in sync by statement-level triggers on
    // yy_paragraph and yy_translation. Was: two correlated COUNT(*) subqueries
    // per row over ~100K paragraph rows + ~14K translation rows.
    $volumes = $db->query("
        SELECT v.*, s.series_label,
               v.volume_paragraph_count_live AS paragraph_count_live,
               v.volume_translation_count_live AS translation_count_live
        FROM yy_volume v
        JOIN yy_series s ON s.series_key = v.series_key
        ORDER BY s.series_sort, v.volume_sort, v.volume_key
    ")->fetchAll();

    // Attach filesystem mtime for each artifact so the UI can show "last
    // generated X ago" and detect when a derived file (PDF, FLIP) is older
    // than its source (DOCX, or upstream PDF) — which means it needs a fresh
    // regeneration even if the row says "success".
    $publicRoot = dirname(__DIR__);
    foreach ($volumes as &$v) {
        $docxAbs = !empty($v['volume_docx'])  ? $publicRoot . '/u/books-word/' . $v['volume_docx']  : null;
        $pdfAbs  = !empty($v['volume_pdf'])   ? $publicRoot . '/pdf/'          . $v['volume_pdf']   : null;
        // Flipbook lives at /<volume_code>/ (top-level, post-migration).
        // Detect by index.php (current shell, post-2026-05-15 migration)
        // OR index.html (pre-migration shell) — whichever exists is the
        // live entry point. The migration moved every book to a 6-line
        // index.php pointer that requires _shared/flipbook-frame.php, but
        // a future book built fresh might still emit index.html. Either
        // proves the bundle is in place.
        $flipDir   = !empty($v['volume_code']) ? $publicRoot . '/' . $v['volume_code'] : null;
        $flipIdx   = $flipDir ? $flipDir . '/index.php'  : null;
        $flipIdxAlt = $flipDir ? $flipDir . '/index.html' : null;
        $v['volume_docx_mtime'] = ($docxAbs && is_file($docxAbs)) ? date('c', filemtime($docxAbs)) : null;
        $v['volume_pdf_mtime']  = ($pdfAbs  && is_file($pdfAbs))  ? date('c', filemtime($pdfAbs))  : null;
        // Freshness comes from pages/, not the index file. index.php is a
        // 4-line shim that can be rewritten without re-rendering a single
        // page (a template change, a bundleVersion backfill), which would
        // make a genuinely stale flipbook look freshly built and stop the
        // regen sweep from ever picking it up. pages/ is only touched by an
        // actual migrate_flipbook.sh render, so its mtime is the build time.
        // The index file still decides *presence* — pages/ without a wrapper
        // is not a servable flipbook.
        $flipPages = $flipDir ? $flipDir . '/pages' : null;
        if ($flipIdx && is_file($flipIdx)) {
            $flipPresent = $flipIdx;
        } elseif ($flipIdxAlt && is_file($flipIdxAlt)) {
            $flipPresent = $flipIdxAlt;
        } else {
            $flipPresent = null;
        }
        if ($flipPresent) {
            $v['volume_flip_mtime'] = ($flipPages && is_dir($flipPages))
                ? date('c', filemtime($flipPages))
                : date('c', filemtime($flipPresent));
        } else {
            $v['volume_flip_mtime'] = null;
        }
    }
    unset($v);

    jsonResponse(['series' => $series, 'volumes' => $volumes]);
}

if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload_docx') {
    if (!$key) errorResponse('Volume key required');
    $file = $_FILES['docx'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) errorResponse('No file uploaded');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'docx') errorResponse('Only .docx files are accepted');

    // Look up the volume. volume_code is the canonical root that drives
    // every per-book filename: docx, PDF, eventually any other artifact. If
    // it's already set we honor it (renaming a book is intentional, not auto).
    $volStmt = $db->prepare("
        SELECT v.volume_key, v.volume_label, v.volume_pdf, v.volume_code, s.series_key,
               COALESCE(s.series_number, 0) AS series_number, COALESCE(v.volume_number, 0) AS volume_number
        FROM yy_volume v JOIN yy_series s ON s.series_key = v.series_key
        WHERE v.volume_key = ?
    ");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol) errorResponse('Volume not found', 404);

    // book_code is THE single canonical root. Derive once, normalize, keep.
    $base = '';
    if (!empty($vol['volume_code'])) {
        $base = $vol['volume_code'];
    } elseif (!empty($vol['volume_pdf'])) {
        $base = pathinfo($vol['volume_pdf'], PATHINFO_FILENAME);
    }
    if (!$base) {
        $sanitize = function($s) {
            $s = preg_replace('/[^A-Za-z0-9 _-]/', '', $s ?? '');
            return trim(preg_replace('/\s+/', '-', $s), '-');
        };
        $base = sprintf('YY-s%02dv%02d-%s', (int)$vol['series_number'], (int)$vol['volume_number'], $sanitize($vol['volume_label'] ?: ''));
    }
    // Force canonical form on the book_code, then derive filenames from it.
    // Doing this here means a stale volume_pdf or volume_docx with %20 / spaces
    // can never propagate through this code path.
    $base = str_replace(['%20', ' '], '-', $base);
    $base = preg_replace('/-+/', '-', $base);
    $base = trim($base, '-');
    $bookCode = $base;
    $docxName = $bookCode . '.docx';
    $pdfName  = $bookCode . '.pdf';

    // Storage paths (works in both Docker container and local dev)
    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $docxDir = $publicRoot . '/u/books-word';
    if (!is_dir($docxDir) && !@mkdir($docxDir, 0775, true)) errorResponse('Cannot create u/books-word directory');
    $docxPath = $docxDir . '/' . $docxName;
    if (!move_uploaded_file($file['tmp_name'], $docxPath)) errorResponse('Failed to save .docx file');

    // Persist source filename + flag BOTH pipelines as queued: the docx→pdf→
    // flipbook pipeline AND the paragraph/translation extraction pipeline.
    // The latter rebuilds yy_paragraph and yy_translation rows from the new
    // docx so the in-app reader, search, and translation links pick up
    // changes without requiring a manual local script run.
    $db->prepare("
        UPDATE yy_volume
           SET volume_code = ?,
               volume_docx = ?,
               volume_pdf  = COALESCE(volume_pdf, ?),
               volume_pipeline_status = 'queued',
               volume_pipeline_message = 'Awaiting host worker (libreoffice + flipbook build)',
               volume_pipeline_retry_count = 0,
               volume_parse_status = 'queued',
               volume_parse_message = 'Awaiting host worker (paragraph + translation extraction)',
               volume_revision_dtime = NOW()
         WHERE volume_key = ?
    ")->execute([$bookCode, $docxName, $pdfName, $key]);

    // Drop a job file the host-side worker watches.
    // The worker runs LibreOffice (DOCX→PDF), then rebuilds the
    // self-hosted flipbook under /<volume_code>/ via rebuild_prototype.sh.
    //
    // Path discipline: $publicRoot is /var/www/html inside the container,
    // which is bind-mounted to /opt/yada-www/public on the host. Anything we
    // write under $publicRoot/jobs lands in /opt/yada-www/public/jobs which
    // the host-side worker can read. (The previous $publicRoot . '/../jobs'
    // resolved to /var/www/jobs in-container — NOT bind-mounted, so the
    // worker never saw any of the queued jobs.)
    $jobsDir = $publicRoot . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    $jobPayload = [
        'volume_key'  => (int)$key,
        'docx_name'   => $docxName,
        'pdf_name'    => $pdfName,
        'flip_code'   => null,  // Worker fills this in after upload
        'queued_at'   => date('c'),
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));

    jsonResponse([
        'saved'       => true,
        'docx'        => $docxName,
        'pdf'         => $pdfName,
        'job_queued'  => basename($jobFile),
        'message'     => 'Uploaded. Conversion + flipbook build will run on the host (typically 1–3 minutes).',
    ]);
}

// Manual retry of a failed pipeline. Resets retry_count, flips status back
// to 'queued', and drops a fresh job file. Used by the Books admin UI when
// auto-retry exhausts its 3-attempt cap or when status is 'error' otherwise.
if ($method === 'POST' && (($_GET['action'] ?? '') === 'retry_pipeline'
                            || (json_decode(file_get_contents('php://input'), true)['action'] ?? '') === 'retry_pipeline')) {
    if (!$key) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $key = (int)($body['volume_key'] ?? 0);
    }
    if (!$key) errorResponse('Volume key required');

    $volStmt = $db->prepare("SELECT volume_key, volume_docx, volume_pdf, volume_code FROM yy_volume WHERE volume_key = ?");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol)               errorResponse('Volume not found', 404);
    if (!$vol['volume_docx'] && !$vol['volume_code']) errorResponse('Volume has no docx — upload one before retrying');

    // Refuse to queue a retry if the docx file isn't actually on disk.
    // The pipeline can never recover from a missing source — only an admin
    // re-upload can. Set the volume status so the books admin UI surfaces
    // it clearly, then bail.
    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $docxAbs    = $publicRoot . '/u/books-word/' . ($vol['volume_docx'] ?: ($vol['volume_code'] . '.docx'));
    if (!is_file($docxAbs)) {
        $db->prepare("
            UPDATE yy_volume
               SET volume_pipeline_status = 'waiting-docx',
                   volume_pipeline_message = 'DOCX file missing on disk — re-upload before retrying',
                   volume_revision_dtime = NOW()
             WHERE volume_key = ?
        ")->execute([$key]);
        errorResponse('DOCX file missing on disk: ' . basename($docxAbs) . ' — upload it before retrying', 400);
    }

    $db->prepare("
        UPDATE yy_volume
           SET volume_pipeline_status = 'queued',
               volume_pipeline_message = 'Manually requeued by admin',
               volume_pipeline_retry_count = 0,
               volume_revision_dtime = NOW()
         WHERE volume_key = ?
    ")->execute([$key]);

    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $jobsDir    = $publicRoot . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    // Derive everything from book_code (single source of truth). If it isn't
    // populated yet, recover from volume_docx/volume_pdf by stripping the ext.
    $canon = function($s) {
        $s = str_replace(['%20', ' '], '-', (string)$s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    };
    $bookCode = $vol['volume_code']
        ?: pathinfo($vol['volume_docx'] ?: $vol['volume_pdf'] ?: '', PATHINFO_FILENAME);
    $bookCode = $canon($bookCode);
    $docxName = $bookCode . '.docx';
    $pdfName  = $bookCode . '.pdf';
    if ($bookCode !== $vol['volume_code']
            || $docxName !== $vol['volume_docx']
            || $pdfName  !== $vol['volume_pdf']) {
        $db->prepare("UPDATE yy_volume SET volume_code = ?, volume_docx = ?, volume_pdf = ? WHERE volume_key = ?")
           ->execute([$bookCode, $docxName, $pdfName, $key]);
    }
    $jobPayload = [
        'volume_key'   => (int)$key,
        'book_code'    => $bookCode,
        'docx_name'    => $docxName,
        'pdf_name'     => $pdfName,
        'flip_code'    => null,
        'queued_at'    => date('c'),
        'manual_retry' => true,
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_retry_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));
    jsonResponse(['queued' => true, 'job' => basename($jobFile), 'message' => 'Pipeline requeued — runs on next host worker tick (≤1 min).']);
}

// Force a fresh PDF render. Same as retry_pipeline but DELETES the existing
// PDF on disk first — book-pipeline-worker.sh's "PDF already on disk" skip
// uses file-existence + mtime, so without this delete a regenerate request
// would no-op into the existing stale PDF.
if ($method === 'POST' && (($_GET['action'] ?? '') === 'regenerate_pdf'
                            || (json_decode(file_get_contents('php://input'), true)['action'] ?? '') === 'regenerate_pdf')) {
    if (!$key) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $key  = (int)($body['volume_key'] ?? 0);
    }
    if (!$key) errorResponse('Volume key required');

    $volStmt = $db->prepare("SELECT volume_key, volume_docx, volume_pdf, volume_code FROM yy_volume WHERE volume_key = ?");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol) errorResponse('Volume not found', 404);
    if (!$vol['volume_docx']) errorResponse('Volume has no docx — upload one before regenerating', 400);

    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $docxAbs    = $publicRoot . '/u/books-word/' . $vol['volume_docx'];
    if (!is_file($docxAbs)) {
        $db->prepare("UPDATE yy_volume SET volume_pipeline_status='waiting-docx', volume_pipeline_message='DOCX file missing — re-upload before regenerating' WHERE volume_key=?")
           ->execute([$key]);
        errorResponse('DOCX file missing on disk: ' . basename($docxAbs) . ' — upload before regenerating', 400);
    }

    // Delete the existing PDF (if any). Worker sees missing PDF and will
    // run LibreOffice / ONLYOFFICE bridge fresh.
    $deletedPdf = null;
    if (!empty($vol['volume_pdf'])) {
        $pdfAbs = $publicRoot . '/pdf/' . $vol['volume_pdf'];
        if (is_file($pdfAbs)) {
            if (@unlink($pdfAbs)) $deletedPdf = $vol['volume_pdf'];
        }
    }

    $db->prepare("
        UPDATE yy_volume
           SET volume_pipeline_status      = 'queued',
               volume_pipeline_message     = 'Manual PDF regeneration — existing PDF deleted',
               volume_pipeline_retry_count = 0,
               volume_revision_dtime       = NOW()
         WHERE volume_key = ?
    ")->execute([$key]);

    $jobsDir = $publicRoot . '/jobs/book-pipeline';
    if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);
    $canon = function($s) {
        $s = str_replace(['%20', ' '], '-', (string)$s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    };
    $bookCode = $canon($vol['volume_code'] ?: pathinfo($vol['volume_docx'], PATHINFO_FILENAME));
    $jobPayload = [
        'volume_key'      => (int)$key,
        'book_code'       => $bookCode,
        'docx_name'       => $bookCode . '.docx',
        'pdf_name'        => $bookCode . '.pdf',
        'flip_code'       => null,
        'queued_at'       => date('c'),
        'force_regenerate'=> true,
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_regen_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));
    jsonResponse([
        'queued'      => true,
        'job'         => basename($jobFile),
        'deleted_pdf' => $deletedPdf,
        'message'     => 'PDF regeneration queued — runs on next host worker tick (≤10 min).',
    ]);
}

// Force a flipbook rebuild via /opt/yada-www/migrate_flipbook.sh on the host.
// Drops a small JSON job file under /jobs/flipbook/; a host cron picks it up
// and runs the rebuild, then deletes the job file. Same job-file pattern as
// the PDF regen above — keeps PHP from needing host shell access.
if ($method === 'POST' && (($_GET['action'] ?? '') === 'regenerate_flipbook'
                            || (json_decode(file_get_contents('php://input'), true)['action'] ?? '') === 'regenerate_flipbook')) {
    if (!$key) {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $key  = (int)($body['volume_key'] ?? 0);
    }
    if (!$key) errorResponse('Volume key required');

    $volStmt = $db->prepare("SELECT volume_key, volume_code, volume_pdf FROM yy_volume WHERE volume_key = ?");
    $volStmt->execute([$key]);
    $vol = $volStmt->fetch();
    if (!$vol) errorResponse('Volume not found', 404);
    if (empty($vol['volume_code'])) errorResponse('Volume has no Book Code — set one before regenerating', 400);
    if (empty($vol['volume_pdf']))  errorResponse('Volume has no PDF — generate the PDF first', 400);

    $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
    $pdfAbs = $publicRoot . '/pdf/' . $vol['volume_pdf'];
    if (!is_file($pdfAbs)) errorResponse('PDF file missing on disk: ' . basename($pdfAbs), 400);

    $jobsDir = $publicRoot . '/jobs/flipbook';
    if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0775, true)) errorResponse('Cannot create jobs/flipbook dir');
    $jobPayload = [
        'volume_key' => (int)$key,
        'book_code'  => $vol['volume_code'],
        'queued_at'  => date('c'),
    ];
    $jobFile = $jobsDir . '/' . sprintf('%010d', $key) . '_flipbook_' . time() . '.json';
    @file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT));
    jsonResponse([
        'queued'  => true,
        'job'     => basename($jobFile),
        'message' => 'Flipbook rebuild queued — runs on next host worker tick.',
    ]);
}

// Register 301 redirects in yy_redirect. Triggered by the Books admin form
// after a rename so old DOCX / PDF URLs forward to the new canonical ones.
// Body: {"action": "add_redirects", "pairs": [{"from": "/x", "to": "/y"}, ...]}
if ($method === 'POST' && (($_GET['action'] ?? '') === 'add_redirects'
                            || (json_decode(file_get_contents('php://input'), true)['action'] ?? '') === 'add_redirects')) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $pairs = $body['pairs'] ?? [];
    if (!is_array($pairs) || !$pairs) errorResponse('pairs array required');
    $stmt = $db->prepare("
        INSERT INTO yy_redirect (redirect_request, redirect_target, redirect_active_flag)
        VALUES (?, ?, true)
        ON CONFLICT (redirect_request) DO UPDATE SET
            redirect_target      = EXCLUDED.redirect_target,
            redirect_active_flag = true
    ");
    $added = 0;
    foreach ($pairs as $p) {
        $from = trim((string)($p['from'] ?? ''));
        $to   = trim((string)($p['to']   ?? ''));
        if (!$from || !$to || $from === $to) continue;
        // Strip query strings — yy_redirect matches on path only.
        $from = parse_url($from, PHP_URL_PATH) ?: $from;
        $to   = parse_url($to,   PHP_URL_PATH) ?: $to;
        $stmt->execute([$from, $to]);
        $added++;
    }
    jsonResponse(['added' => $added]);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    if ($action === 'add_series') {
        $label = trim($data['series_label'] ?? '');
        if (!$label) errorResponse('Series label is required');
        $stmt = $db->prepare("
            INSERT INTO yy_series (series_label, series_name, series_number, series_sort, series_summary, series_image)
            VALUES (?, ?, ?, ?, ?, ?) RETURNING series_key
        ");
        $stmt->execute([
            $label,
            trim($data['series_name'] ?? '') ?: $label,
            (int)($data['series_number'] ?? 0),
            (int)($data['series_sort'] ?? 0),
            trim($data['series_summary'] ?? '') ?: null,
            trim($data['series_image'] ?? '') ?: null,
        ]);
        jsonResponse(['saved' => true, 'series_key' => $stmt->fetchColumn()]);
    }

    // Create volume
    $label = trim($data['volume_label'] ?? '');
    $seriesKey = (int)($data['series_key'] ?? 0);
    if (!$label) errorResponse('Volume label is required');
    if (!$seriesKey) errorResponse('Series is required');

    // Canonicalize volume_code on the way in: replace %20 / spaces with -,
    // collapse doubled hyphens, strip leading/trailing hyphens. Mirrors the
    // upload_docx and retry_pipeline normalization so the column never holds
    // a non-canonical value regardless of how a row was created.
    $canonCode = function($s) {
        $s = str_replace(['%20', ' '], '-', (string)$s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    };
    $rawCode = trim($data['volume_code'] ?? '');
    $code = $rawCode !== '' ? $canonCode($rawCode) : null;

    // Clamp ask_rating to the 0-100 range the DB CHECK enforces. Default 50
    // matches the column default — neutral weight, all books equal.
    $askRating = isset($data['volume_ask_rating'])
        ? max(0, min(100, (int)$data['volume_ask_rating']))
        : 50;

    $stmt = $db->prepare("
        INSERT INTO yy_volume (series_key, volume_label, volume_number, volume_sort,
                               volume_code, volume_pdf, volume_page_count, volume_active_flag, volume_ask_rating)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING volume_key
    ");
    $stmt->execute([
        $seriesKey,
        $label,
        (int)($data['volume_number'] ?? 0),
        (int)($data['volume_sort'] ?? 0),
        $code,
        trim($data['volume_pdf'] ?? '') ?: null,
        (int)($data['volume_page_count'] ?? 0) ?: null,
        (bool)($data['volume_active_flag'] ?? true) ? 'true' : 'false',
        $askRating,
    ]);
    jsonResponse(['saved' => true, 'volume_key' => $stmt->fetchColumn()]);
}

if ($method === 'PUT') {
    $seriesKey = (int)($_GET['series_key'] ?? 0);

    if ($seriesKey) {
        // Update series
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $fields = []; $params = [];
        foreach (['series_label', 'series_name', 'series_summary', 'series_image'] as $col) {
            if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = trim($data[$col] ?? '') ?: null; }
        }
        foreach (['series_number', 'series_sort'] as $col) {
            if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = (int)$data[$col]; }
        }
        if (empty($fields)) errorResponse('Nothing to update');
        $params[] = $seriesKey;
        $db->prepare("UPDATE yy_series SET " . implode(', ', $fields) . " WHERE series_key = ?")->execute($params);
        jsonResponse(['saved' => true]);
    }

    if (!$key) errorResponse('Volume key required');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    // ── If volume_code is changing, also rename DOCX + PDF on disk ──
    // Read the old row first so we can compute the rename source paths and
    // bail if the destination already exists. The rename happens BEFORE the
    // UPDATE so a filesystem failure doesn't leave the DB pointing at a
    // file that doesn't exist.
    $newCode = null;
    if (array_key_exists('volume_code', $data)) {
        $raw = trim((string)($data['volume_code'] ?? ''));
        if ($raw !== '') {
            $raw = str_replace(['%20', ' '], '-', $raw);
            $raw = preg_replace('/-+/', '-', $raw);
            $raw = trim($raw, '-');
        }
        $newCode = $raw !== '' ? $raw : null;
    }
    $renamedDocx = null; $renamedPdf = null;
    $oldDocxName = null; $oldPdfName = null;   // Surfaced to the client so it
                                                // can prompt + register 301s.
    if ($newCode !== null) {
        $cur = $db->prepare("SELECT volume_code, volume_docx, volume_pdf FROM yy_volume WHERE volume_key = ?");
        $cur->execute([$key]);
        $oldRow = $cur->fetch();
        if ($oldRow && $newCode !== $oldRow['volume_code']) {
            $publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__) . '/public');
            $renames = [
                ['dir' => $publicRoot . '/u/books-word', 'old' => $oldRow['volume_docx'], 'new' => $newCode . '.docx', 'col' => 'volume_docx'],
                ['dir' => $publicRoot . '/pdf',          'old' => $oldRow['volume_pdf'],  'new' => $newCode . '.pdf',  'col' => 'volume_pdf'],
            ];
            foreach ($renames as $r) {
                if (!$r['old']) continue;                          // nothing to rename
                if ($r['old'] === $r['new']) continue;             // already canonical
                $oldAbs = $r['dir'] . '/' . $r['old'];
                $newAbs = $r['dir'] . '/' . $r['new'];
                if (!file_exists($oldAbs)) continue;               // source missing; just update DB
                if (file_exists($newAbs)) {
                    errorResponse('Cannot rename ' . $r['old'] . ' → ' . $r['new'] . ': destination already exists', 500);
                }
                if (!@rename($oldAbs, $newAbs)) {
                    // 500 so errorResponse logs the failure to yy_monitor_event;
                    // include the writable-by-PHP probe so the cause is in the
                    // admin event detail (typical case: dir not writable to www-data).
                    $writable = is_writable($r['dir']) ? 'yes' : 'no';
                    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($oldAbs))['name'] ?? '?') : (string)fileowner($oldAbs);
                    errorResponse(
                        'Filesystem rename failed: ' . $r['old'] . ' → ' . $r['new']
                        . ' (dir writable: ' . $writable . ', file owner: ' . $owner . ')',
                        500
                    );
                }
                if ($r['col'] === 'volume_docx') { $renamedDocx = $r['new']; $oldDocxName = $r['old']; }
                if ($r['col'] === 'volume_pdf')  { $renamedPdf  = $r['new']; $oldPdfName  = $r['old']; }
            }
        }
    }

    $fields = []; $params = [];
    if (array_key_exists('volume_code', $data)) {
        $fields[] = "volume_code = ?";
        $params[] = $newCode;
    }
    // If we successfully renamed files above, force the DB columns to match.
    // The client's payload values for these columns are ignored when a
    // rename fired, otherwise honored.
    if ($renamedDocx !== null) { $fields[] = "volume_docx = ?"; $params[] = $renamedDocx; }
    if ($renamedPdf  !== null) { $fields[] = "volume_pdf  = ?"; $params[] = $renamedPdf;  }
    foreach (['volume_label', 'volume_pdf', 'volume_docx'] as $col) {
        if ($col === 'volume_pdf'  && $renamedPdf  !== null) continue;
        if ($col === 'volume_docx' && $renamedDocx !== null) continue;
        if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = trim($data[$col] ?? '') ?: null; }
    }
    foreach (['series_key', 'volume_number', 'volume_sort', 'volume_page_count'] as $col) {
        if (array_key_exists($col, $data)) { $fields[] = "$col = ?"; $params[] = (int)$data[$col]; }
    }
    // ask_rating is bounded 0-100 by a DB CHECK; clamp here too so a malformed
    // payload returns a friendlier 400 path rather than a constraint violation.
    if (array_key_exists('volume_ask_rating', $data)) {
        $fields[] = "volume_ask_rating = ?";
        $params[] = max(0, min(100, (int)$data['volume_ask_rating']));
    }
    // PDO_PGSQL coerces PHP bool false to '' which Postgres rejects as a
    // boolean. Explicit 'true'/'false' strings match what admin-basics /
    // admin-vlog / admin-test do.
    foreach (['volume_active_flag', 'volume_search_flag', 'volume_parse_flag', 'volume_ask_yada_flag'] as $col) {
        if (array_key_exists($col, $data)) {
            $fields[] = "$col = ?";
            $params[] = (bool)$data[$col] ? 'true' : 'false';
        }
    }

    if (empty($fields)) errorResponse('Nothing to update');
    $params[] = $key;
    $db->prepare("UPDATE yy_volume SET " . implode(', ', $fields) . " WHERE volume_key = ?")->execute($params);

    // Tell the client which paths changed (with both forms) so it can offer
    // to register 301 redirects from the old URLs to the new ones.
    $renames = [];
    if ($renamedDocx) $renames[] = ['from' => '/u/books-word/' . $oldDocxName, 'to' => '/u/books-word/' . $renamedDocx];
    if ($renamedPdf)  $renames[] = ['from' => '/pdf/'         . $oldPdfName,   'to' => '/pdf/'         . $renamedPdf];
    jsonResponse([
        'saved'        => true,
        'renamed_docx' => $renamedDocx,
        'renamed_pdf'  => $renamedPdf,
        'renames'      => $renames,
    ]);
}

if ($method === 'DELETE') {
    if (!$key) errorResponse('Volume key required');
    $paras = $db->prepare("SELECT COUNT(*) FROM yy_paragraph WHERE volume_key = ?");
    $paras->execute([$key]);
    if ((int)$paras->fetchColumn() > 0) errorResponse('Cannot delete: volume has paragraphs');

    $db->prepare("DELETE FROM yy_volume WHERE volume_key = ?")->execute([$key]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);

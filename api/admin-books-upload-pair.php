<?php
/**
 * Receives a (.docx, .pdf) pair from the desktop uploader, stores them in
 * their canonical locations, updates yy_volume metadata, and drops a job
 * in the book pipeline queue.
 *
 * Auth: bearer token in `X-Author-Token` header (same token used by
 * admin-books-status.php).
 *
 * Request: multipart/form-data
 *   docx — the source .docx (Word format)
 *   pdf  — the Word-exported .pdf (page-perfect against the .docx)
 *
 * The volume is identified by the docx filename's stem; we look it up
 * in yy_volume by volume_docx OR by re-deriving the canonical book
 * code (strip .docx extension, normalize hyphens).
 *
 * After saving, we set the pdf's mtime equal to docx's mtime + 1 second
 * so the pipeline worker's `[ ! "$docx_path" -nt "$PDF_DIR/$pdf_name" ]`
 * check passes and skips the docx→pdf conversion (which would re-render
 * with a different engine and undo the whole point of this endpoint).
 *
 * Response: { ok, queued, volume_key, job, docx_size, pdf_size }
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

$db = getDb();

// ── Auth (same token as the status endpoint) ──────────────────────
$presented = $_SERVER['HTTP_X_AUTHOR_TOKEN'] ?? '';
$row = $db->query("
    SELECT setting_value FROM yy_setting
    WHERE setting_scope_code = 'config'
      AND setting_code       = 'author-upload-token'
    LIMIT 1
")->fetch();
$expected = $row && !empty($row['setting_value']) ? $row['setting_value'] : '';
if ($expected === '' || $presented === '' || !hash_equals($expected, $presented)) {
    errorResponse('Unauthorized', 401);
}

// ── Validate uploads ──────────────────────────────────────────────
if (empty($_FILES['docx']) || empty($_FILES['pdf'])) {
    errorResponse('Both `docx` and `pdf` file fields are required', 400);
}
foreach (['docx', 'pdf'] as $f) {
    if ($_FILES[$f]['error'] !== UPLOAD_ERR_OK) {
        errorResponse("Upload error on `$f` field: " . $_FILES[$f]['error'], 400);
    }
}

$docxName = basename($_FILES['docx']['name']);
$pdfName  = basename($_FILES['pdf']['name']);

// Filenames must be plausibly safe — letters, digits, dots, dashes,
// apostrophes (preserved from the docx canonical path), spaces (rare).
$safeRe = '/^[A-Za-z0-9 _\'\-\.]+$/';
if (!preg_match($safeRe, $docxName) || !preg_match($safeRe, $pdfName)) {
    errorResponse('Filename contains unsafe characters', 400);
}
if (substr(strtolower($docxName), -5) !== '.docx' || substr(strtolower($pdfName), -4) !== '.pdf') {
    errorResponse('docx must end in .docx and pdf in .pdf', 400);
}

// Stems should match (modulo extension) so we know they're a pair.
$docxStem = substr($docxName, 0, -5);
$pdfStem  = substr($pdfName,  0, -4);
if ($docxStem !== $pdfStem) {
    errorResponse("docx ($docxStem) and pdf ($pdfStem) stems do not match", 400);
}

// ── Resolve volume ────────────────────────────────────────────────
$lookup = $db->prepare("
    SELECT volume_key, volume_code, volume_locked_flag, volume_locked_by_name
    FROM yy_volume
    WHERE volume_active_flag = TRUE
      AND (volume_docx = ? OR volume_code = ?)
    LIMIT 1
");
$lookup->execute([$docxName, $docxStem]);
$vol = $lookup->fetch();
if (!$vol) {
    errorResponse("No active yy_volume found for $docxStem", 404);
}

// Source-control lockout. This endpoint authenticates by shared token, not a
// user session, so we can't tell whether the uploader is the person holding
// the checkout — any active lock blocks the pair upload. Release the checkout
// on the Admin → Books page first. 423 Locked.
if ($vol['volume_locked_flag']) {
    $holder = $vol['volume_locked_by_name'] ?: 'an admin';
    errorResponse("$docxStem is checked out by $holder — release the lock in Admin → Books before uploading.", 423);
}
$volKey  = (int)$vol['volume_key'];
$volCode = $vol['volume_code'] ?: $docxStem;

// ── Store files ───────────────────────────────────────────────────
$publicRoot = is_dir('/var/www/html') ? '/var/www/html' : (dirname(__DIR__));
$docxDest = $publicRoot . '/u/books-word/' . $docxName;
$pdfDest  = $publicRoot . '/pdf/' . $pdfName;

if (!@is_dir(dirname($docxDest))) @mkdir(dirname($docxDest), 0775, true);
if (!@is_dir(dirname($pdfDest)))  @mkdir(dirname($pdfDest),  0775, true);

if (!move_uploaded_file($_FILES['docx']['tmp_name'], $docxDest)) {
    errorResponse('Failed to write docx', 500);
}
if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfDest)) {
    @unlink($docxDest);
    errorResponse('Failed to write pdf', 500);
}
@chmod($docxDest, 0664);
@chmod($pdfDest,  0664);

// Pipeline worker check is `[ ! $docx -nt $pdf ]` — i.e. "skip
// conversion if pdf is at least as new as docx." Stamp pdf to docx
// mtime + 1 so it's strictly newer and the check unambiguously
// passes regardless of upload-order timing.
$docxMt = @filemtime($docxDest) ?: time();
@touch($pdfDest, $docxMt + 1);

// ── Update yy_volume metadata ─────────────────────────────────────
$db->prepare("
    UPDATE yy_volume
       SET volume_docx = ?, volume_pdf = ?,
           volume_revision_dtime = NOW()
     WHERE volume_key = ?
")->execute([$docxName, $pdfName, $volKey]);

// ── Drop pipeline job ─────────────────────────────────────────────
$jobsDir = $publicRoot . '/jobs/book-pipeline';
if (!@is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);

$payload = [
    'volume_key'      => $volKey,
    'book_code'       => $volCode,
    'docx_name'       => $docxName,
    'pdf_name'        => $pdfName,
    'flip_code'       => null,
    'queued_at'       => date('c'),
    'manual_retry'    => true,
    'reason'          => 'Author-supplied PDF (Word export); skip docx->pdf conversion',
    'authored_pdf'    => true,
];
$jobName = sprintf('%010d_authoredpdf_%d.json', $volKey, time());
$jobFile = $jobsDir . '/' . $jobName;
@file_put_contents($jobFile, json_encode($payload, JSON_PRETTY_PRINT));

// Mark queued in DB so the books admin UI shows it pending.
$db->prepare("
    UPDATE yy_volume
       SET volume_pipeline_status  = 'queued',
           volume_pipeline_message = 'Author-supplied PDF — pipeline will skip conversion and run FlipHTML5',
           volume_pipeline_retry_count = 0,
           volume_revision_dtime   = NOW()
     WHERE volume_key = ?
")->execute([$volKey]);

jsonResponse([
    'ok'         => true,
    'queued'     => true,
    'volume_key' => $volKey,
    'job'        => $jobName,
    'docx_size'  => filesize($docxDest),
    'pdf_size'   => filesize($pdfDest),
]);

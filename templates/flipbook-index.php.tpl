<?php
// Per-book flipbook wrapper. All HTML, scripts, and cache-bust versions
// live in /opt/yada-www/public/_shared/flipbook-frame.php. Each book is
// just the four fields below; bug fixes happen once in the shared shell
// and every flipbook picks them up on the next request.
// bundleVersion is the build stamp. Page/thumb/text/toc/search URLs are
// identical across rebuilds, so without it a browser holding the previous
// build's images re-serves them and the new book never appears.
$FB = [
    'total'         => {{TOTAL}},
    'title'         => '{{TITLE_PHP}}',
    'bookCode'      => '{{BOOK_CODE}}',
    'bundleVersion' => '{{BUNDLE_VERSION}}',
];
require __DIR__ . '/../_shared/flipbook-frame.php';

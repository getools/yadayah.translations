<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/image-helpers.php';

$db = getDb();
$UPLOAD_DIR = __DIR__ . '/../u/community/posts';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// GET: paginated feed
if ($method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $session = getCommunitySession();
    $accountKey = $session ? $session['account_key'] : 0;

    $countStmt = $db->query("SELECT COUNT(*) FROM yy_community_post WHERE post_active_flag = TRUE");
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT p.*, a.account_name, a.account_avatar,
            EXISTS(SELECT 1 FROM yy_community_post_like pl WHERE pl.post_key = p.post_key AND pl.account_key = :ak) as user_liked
            FROM yy_community_post p
            JOIN yy_account a ON a.account_key = p.account_key
            WHERE p.post_active_flag = TRUE
            ORDER BY p.post_pinned_flag DESC, p.post_dtime DESC
            LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':ak', $accountKey, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();

    // Convert bool strings
    foreach ($posts as &$p) {
        $p['user_liked'] = (bool)$p['user_liked'];
        $p['post_pinned_flag'] = ($p['post_pinned_flag'] === true || $p['post_pinned_flag'] === 't');
    }

    jsonResponse([
        'posts' => $posts,
        'page' => $page,
        'total_pages' => max(1, ceil($total / $perPage)),
        'total' => $total,
    ]);
}

// POST: create new post
if ($method === 'POST') {
    $account = requireCommunityAuth();

    $text = trim($_POST['post_text'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $imagePath = null;

    // Handle image upload
    if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            errorResponse('Only jpg, png, gif, webp images are allowed');
        }
        $filename = time() . '_' . $account['account_key'] . '.' . $ext;
        $dest = "$UPLOAD_DIR/$filename";
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
            errorResponse('Failed to save image');
        }
        // The stored file IS the raw upload here (no separate originals/ copy),
        // so derive icon/sm/md/lg from it. Feeds should request '-sm'.
        makeImageSizes($dest, $UPLOAD_DIR, $filename);
        $imagePath = "u/community/posts/$filename";
    }

    if (!$text && !$imagePath && !$videoUrl) {
        errorResponse('Post must have text, image, or video');
    }

    $stmt = $db->prepare("INSERT INTO yy_community_post (account_key, post_text, post_image, post_video_url) VALUES (?, ?, ?, ?) RETURNING post_key");
    $stmt->execute([$account['account_key'], $text ?: null, $imagePath, $videoUrl ?: null]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonResponse(['saved' => true, 'post_key' => $row['post_key']]);
}

// PUT: edit own post
if ($method === 'PUT') {
    $account = requireCommunityAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $postKey = (int)($input['post_key'] ?? 0);
    $text = trim($input['post_text'] ?? '');

    if (!$postKey) errorResponse('post_key required');

    // Verify ownership
    $check = $db->prepare("SELECT account_key FROM yy_community_post WHERE post_key = ?");
    $check->execute([$postKey]);
    $post = $check->fetch();
    if (!$post || (int)$post['account_key'] !== $account['account_key']) {
        errorResponse('You can only edit your own posts');
    }

    $stmt = $db->prepare("UPDATE yy_community_post SET post_text = ?, post_revision_dtime = NOW() WHERE post_key = ?");
    $stmt->execute([$text, $postKey]);
    jsonResponse(['saved' => true]);
}

// DELETE: soft-delete own post
if ($method === 'DELETE') {
    $account = requireCommunityAuth();
    $input = json_decode(file_get_contents('php://input'), true);
    $postKey = (int)($input['post_key'] ?? 0);
    if (!$postKey) errorResponse('post_key required');

    $check = $db->prepare("SELECT account_key FROM yy_community_post WHERE post_key = ?");
    $check->execute([$postKey]);
    $post = $check->fetch();
    if (!$post || (int)$post['account_key'] !== $account['account_key']) {
        errorResponse('You can only delete your own posts');
    }

    $db->prepare("UPDATE yy_community_post SET post_active_flag = FALSE WHERE post_key = ?")->execute([$postKey]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);

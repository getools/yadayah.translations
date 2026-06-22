<?php
require_once __DIR__ . '/config.php';
$user = requireAuth();
$db = getDb();
setCurrentUser($db, $user['user_key']);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && !empty($_POST['_method'])) $method = strtoupper($_POST['_method']);
$input = null;
if (in_array($method, ['POST','PUT'])) {
    $input = json_decode(file_get_contents('php://input'), true);
}

switch ($method) {
    case 'GET':
        // List users with their page settings
        $users = $db->query("
            SELECT user_key, user_code, user_name_display, user_handle, user_email, user_avatar, user_bio,
                   user_oauth_provider, user_dtime,
                   user_banned_flag, user_banned_until, user_ban_reason, user_muted_flag
            FROM yy_user ORDER BY user_key
        ")->fetchAll();

        // Auth methods per user
        $authMethods = $db->query("SELECT user_key, auth_provider, auth_email FROM yy_user_auth WHERE auth_active_flag = TRUE ORDER BY auth_linked_dtime")->fetchAll();
        $authMap = [];
        foreach ($authMethods as $am) {
            $authMap[$am['user_key']][] = ['provider' => $am['auth_provider'], 'email' => $am['auth_email']];
        }

        $settings = $db->query("
            SELECT setting_key, setting_code, setting_label FROM yy_setting
            WHERE setting_scope_code = 'admin' AND setting_group_code = 'pages'
            ORDER BY setting_sort
        ")->fetchAll();

        $userSettings = $db->query("
            SELECT us.user_key, us.setting_key, us.user_setting_value
            FROM yy_user_setting us
            JOIN yy_setting s ON s.setting_key = us.setting_key
            WHERE s.setting_scope_code = 'admin' AND s.setting_group_code = 'pages'
        ")->fetchAll();

        // All roles
        $roles = $db->query("SELECT role_key, role_code, role_label FROM yy_role WHERE role_active_flag = TRUE ORDER BY role_sort")->fetchAll();

        // User roles
        $userRoles = $db->query("SELECT user_key, role_key FROM yy_user_role")->fetchAll();
        $userRolesMap = [];
        foreach ($userRoles as $ur) {
            $userRolesMap[$ur['user_key']][] = (int)$ur['role_key'];
        }

        // Index user settings by user_key => setting_key => value
        $settingsMap = [];
        foreach ($userSettings as $us) {
            $settingsMap[$us['user_key']][$us['setting_key']] = $us['user_setting_value'];
        }

        // Attach settings and roles to each user
        foreach ($users as &$u) {
            $u['page_settings'] = [];
            foreach ($settings as $s) {
                $val = $settingsMap[$u['user_key']][$s['setting_key']] ?? '1';
                $u['page_settings'][] = [
                    'setting_key' => (int)$s['setting_key'],
                    'setting_code' => $s['setting_code'],
                    'setting_label' => $s['setting_label'],
                    'enabled' => $val === '1',
                ];
            }
            $u['role_keys'] = $userRolesMap[$u['user_key']] ?? [];
            $u['auth_methods'] = $authMap[$u['user_key']] ?? [];
        }
        unset($u);

        jsonResponse(['users' => $users, 'settings' => $settings, 'roles' => $roles]);
        break;

    case 'PUT':
        if (!$input || empty($input['user_key'])) errorResponse('user_key required');
        $key = (int)$input['user_key'];

        // Update only the profile fields that were actually supplied.
        // (Ban/mute toggles send a partial PUT with no profile fields — never
        //  overwrite name/code/email/handle/bio with blanks in that case.)
        $fieldMap = [
            'user_code'         => 'user_code',
            'user_name_display' => 'user_name_display',
            'user_handle'       => 'user_handle',
            'user_email'        => 'user_email',
            'user_bio'          => 'user_bio',
        ];
        $sets = [];
        $params = ['key' => $key];
        foreach ($fieldMap as $inKey => $col) {
            if (array_key_exists($inKey, $input)) {
                $sets[] = "$col = :$inKey";
                $params[$inKey] = $input[$inKey];
            }
        }
        if ($sets) {
            $stmt = $db->prepare("UPDATE yy_user SET " . implode(', ', $sets) . " WHERE user_key = :key");
            $stmt->execute($params);
        }

        // Update ban status
        if (isset($input['banned'])) {
            if ($input['banned']) {
                $banUntil = !empty($input['ban_duration']) ? date('Y-m-d H:i:s', time() + (int)$input['ban_duration'] * 3600) : null;
                $db->prepare("UPDATE yy_user SET user_banned_flag = TRUE, user_banned_until = ?, user_ban_reason = ? WHERE user_key = ?")
                    ->execute([$banUntil, $input['ban_reason'] ?? null, $key]);
            } else {
                $db->prepare("UPDATE yy_user SET user_banned_flag = FALSE, user_banned_until = NULL, user_ban_reason = NULL WHERE user_key = ?")
                    ->execute([$key]);
            }
        }

        // Update mute status
        if (isset($input['muted'])) {
            $muted = $input['muted'] ? 't' : 'f';
            $db->prepare("UPDATE yy_user SET user_muted_flag = ? WHERE user_key = ?")
                ->execute([$muted, $key]);
        }

        // Update password if provided
        if (!empty($input['new_password'])) {
            $hash = password_hash($input['new_password'], PASSWORD_DEFAULT);
            $db->prepare("UPDATE yy_user SET user_pass = ? WHERE user_key = ?")->execute([$hash, $key]);
            // Update yy_user_auth too
            $authExists = $db->prepare("SELECT 1 FROM yy_user_auth WHERE user_key = ? AND auth_provider = 'email' AND auth_active_flag = TRUE");
            $authExists->execute([$key]);
            if ($authExists->fetchColumn()) {
                $db->prepare("UPDATE yy_user_auth SET auth_pass = ? WHERE user_key = ? AND auth_provider = 'email' AND auth_active_flag = TRUE")
                    ->execute([$hash, $key]);
            } else {
                // Create email auth method if one doesn't exist
                $emailStmt = $db->prepare("SELECT user_email FROM yy_user WHERE user_key = ?");
                $emailStmt->execute([$key]);
                $userEmail = $emailStmt->fetchColumn();
                $db->prepare("INSERT INTO yy_user_auth (user_key, auth_provider, auth_email, auth_pass) VALUES (?, 'email', ?, ?)")
                    ->execute([$key, $userEmail, $hash]);
            }
        }

        // Update roles
        if (isset($input['role_keys']) && is_array($input['role_keys'])) {
            $db->prepare("DELETE FROM yy_user_role WHERE user_key = ?")->execute([$key]);
            $insRole = $db->prepare("INSERT INTO yy_user_role (user_key, role_key) VALUES (?, ?) ON CONFLICT DO NOTHING");
            foreach ($input['role_keys'] as $rk) {
                $insRole->execute([$key, (int)$rk]);
            }
        }

        // Update page settings
        if (isset($input['page_settings']) && is_array($input['page_settings'])) {
            $upsert = $db->prepare("
                INSERT INTO yy_user_setting (user_key, setting_key, user_setting_value)
                VALUES (:uk, :sk, :val)
                ON CONFLICT (user_key, setting_key)
                DO UPDATE SET user_setting_value = EXCLUDED.user_setting_value
            ");
            foreach ($input['page_settings'] as $ps) {
                $upsert->execute([
                    'uk' => $key,
                    'sk' => (int)$ps['setting_key'],
                    'val' => $ps['enabled'] ? '1' : '0',
                ]);
            }
        }

        jsonResponse(['saved' => true]);
        break;

    case 'POST':
        if (empty($input['user_code'])) errorResponse('user_code required');

        $displayName = $input['user_name_full'] ?? $input['user_name_display'] ?? '';
        if (!$displayName && !empty($input['user_email'])) $displayName = explode('@', $input['user_email'])[0];
        $stmt = $db->prepare("
            INSERT INTO yy_user (user_code, user_name_full, user_display_name, user_email, user_pass)
            VALUES (:code, :name_full, :display_name, :email, :pass)
            RETURNING user_key
        ");
        $hash = !empty($input['new_password']) ? password_hash($input['new_password'], PASSWORD_DEFAULT) : null;
        $stmt->execute([
            'code' => $input['user_code'],
            'name_full' => $input['user_name_full'] ?? $displayName,
            'display_name' => $displayName,
            'email' => $input['user_email'] ?? '',
            'pass' => $hash,
        ]);
        $newKey = $stmt->fetchColumn();

        // Create default page settings for new user
        $settings = $db->query("SELECT setting_key FROM yy_setting WHERE setting_scope_code = 'admin' AND setting_group_code = 'pages'")->fetchAll();
        $ins = $db->prepare("INSERT INTO yy_user_setting (user_key, setting_key, user_setting_value) VALUES (:uk, :sk, '1') ON CONFLICT DO NOTHING");
        foreach ($settings as $s) {
            $ins->execute(['uk' => $newKey, 'sk' => $s['setting_key']]);
        }

        jsonResponse(['saved' => true, 'user_key' => (int)$newKey]);
        break;

    case 'DELETE':
        $key = (int)($_GET['key'] ?? 0);
        if (!$key) errorResponse('user_key required');
        // Prevent deleting yourself
        if ($key === (int)$user['user_key']) errorResponse('Cannot delete your own account');
        // Delete related records first
        $db->prepare("DELETE FROM yy_user_role WHERE user_key = ?")->execute([$key]);
        $db->prepare("DELETE FROM yy_user_setting WHERE user_key = ?")->execute([$key]);
        $db->prepare("DELETE FROM yy_user WHERE user_key = ?")->execute([$key]);
        jsonResponse(['deleted' => true]);
        break;

    default:
        errorResponse('Method not allowed', 405);
}

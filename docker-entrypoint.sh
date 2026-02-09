#!/bin/bash
set -e

# Copy TinyMCE into the mounted public volume if not already present
if [ ! -f /var/www/html/js/tinymce/tinymce.min.js ]; then
    mkdir -p /var/www/html/js/tinymce
    cp -r /opt/tinymce/* /var/www/html/js/tinymce/
    echo "TinyMCE copied to public/js/tinymce/"
fi

# Initialize user passwords (hash any NULL passwords with bcrypt)
# Retry up to 10 times in case MySQL is still starting
for i in $(seq 1 10); do
    php -r '
$host = getenv("DB_HOST") ?: "db";
$name = getenv("DB_NAME") ?: "yada_translations";
$user = getenv("DB_USER") ?: "yada";
$pass = getenv("DB_PASS") ?: "yada_pass";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT yy_user_key FROM yy_user WHERE yy_user_pass IS NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) > 0) {
        $up = $pdo->prepare("UPDATE yy_user SET yy_user_pass = ? WHERE yy_user_key = ?");
        foreach ($users as $u) {
            $hash = password_hash("7#Yada.Yah#7", PASSWORD_DEFAULT);
            $up->execute([$hash, $u["yy_user_key"]]);
        }
        echo "User passwords initialized for " . count($users) . " account(s).\n";
    } else {
        echo "All user passwords already set.\n";
    }
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Password init attempt: " . $e->getMessage() . "\n");
    exit(1);
}
' 2>&1 && break
    echo "Retrying password init ($i/10)..."
    sleep 2
done

exec "$@"

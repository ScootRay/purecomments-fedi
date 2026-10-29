<?php
declare(strict_types=1);

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = $config['db_path'];
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_slug TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            name TEXT NOT NULL,
            email TEXT,
            website TEXT,
            content_md TEXT NOT NULL,
            content_html TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('pending','published'))
        );"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL
        );"
    );
    ensure_schema($pdo);

    return $pdo;
}

function validate_post_slug(string $slug): bool
{
    return preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
}

function sanitize_post_path(string $path, string $expectedSlug): ?string
{
    $parsed = parse_url($path, PHP_URL_PATH);
    if (!is_string($parsed) || $parsed === '') {
        return null;
    }

    if (strlen($parsed) > 300 || str_contains($parsed, '..') || str_contains($parsed, '\\')) {
        return null;
    }

    if (preg_match('#^/[A-Za-z0-9\-_.~%/]*$#', $parsed) !== 1) {
        return null;
    }

    $trimmed = trim($parsed, '/');
    if ($trimmed === '') {
        return null;
    }

    $parts = explode('/', $trimmed);
    $last = rawurldecode((string)end($parts));
    $lastWithoutExt = preg_replace('/\.[a-z0-9]{1,10}$/i', '', $last);
    $norm = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9\-]+/', '-', strtolower($lastWithoutExt))), '-');

    if ($norm !== $expectedSlug) {
        return null;
    }

    $cleanPath = '/' . $trimmed;
    if (str_ends_with($parsed, '/') || !preg_match('/\.[a-z0-9]{1,10}$/i', $last)) {
        $cleanPath .= '/';
    }

    return $cleanPath;
}

function encrypt_email(?string $email, array $config): ?string
{
    if ($email === null || $email === '') {
        return null;
    }
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('libsodium extension is required for email encryption.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($email, $nonce, $config['sodium_key']);
    return base64_encode($nonce . $cipher);
}

function decrypt_email(?string $value, array $config): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('libsodium extension is required for email encryption.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $config['sodium_key']);
    return $plain === false ? null : $plain;
}

function ensure_schema(PDO $pdo): void
{
    $columns = $pdo->query("PRAGMA table_info(comments)")->fetchAll();
    $names = array_column($columns, 'name');
    if (!in_array('website', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN website TEXT');
    }
    if (!in_array('type', $names, true)) {
        $pdo->exec("ALTER TABLE comments ADD COLUMN type TEXT NOT NULL DEFAULT 'comment'");
    }
    if (!in_array('source_url', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN source_url TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_url', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN avatar_url TEXT DEFAULT NULL');
    }
    // Backport from 1.8.5 (additive only): webmention title. No backfill here;
    // existing rows simply keep title = NULL until they are re-sent.
    if (!in_array('title', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN title TEXT DEFAULT NULL');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_slug_status_type ON comments(post_slug, status, type);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_source_url ON comments(source_url);');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_paths (
            post_slug TEXT PRIMARY KEY,
            post_path TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        );"
    );
    // Backport from 1.8.5 (additive only): blocked webmention sources.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blocked_webmentions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_url TEXT NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        );"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocked_webmentions_url ON blocked_webmentions(source_url);');
}

function insert_comment(array $config, array $data): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT INTO comments (post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url, title)
         VALUES (:post_slug, :parent_id, :name, :email, :website, :content_md, :content_html, :created_at, :status, :type, :source_url, :avatar_url, :title)'
    );
    $stmt->execute([
        ':post_slug' => $data['post_slug'],
        ':parent_id' => $data['parent_id'],
        ':name' => $data['name'],
        ':email' => $data['email_encrypted'] ?? null,
        ':website' => $data['website'] ?? null,
        ':content_md' => $data['content_md'],
        ':content_html' => $data['content_html'],
        ':created_at' => $data['created_at'],
        ':status' => $data['status'],
        ':type' => $data['type'] ?? 'comment',
        ':source_url' => $data['source_url'] ?? null,
        ':avatar_url' => $data['avatar_url'] ?? null,
        ':title' => $data['title'] ?? null,
    ]);

    return (int)$pdo->lastInsertId();
}

function fetch_published_comments(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, post_slug, parent_id, name, website, content_html, created_at, email, type, source_url, avatar_url, title
         FROM comments
         WHERE post_slug = :slug AND status = :status AND type NOT IN ('like', 'repost')
         ORDER BY created_at ASC"
    );
    $stmt->execute([
        ':slug' => $slug,
        ':status' => 'published',
    ]);
    return $stmt->fetchAll() ?: [];
}

function fetch_post_reactions(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, name, website, avatar_url, source_url, created_at, type
         FROM comments
         WHERE post_slug = :slug AND status = 'published' AND type IN ('like', 'repost')
         ORDER BY created_at ASC"
    );
    $stmt->execute([':slug' => $slug]);
    $rows = $stmt->fetchAll() ?: [];

    $likes = [];
    $reposts = [];
    foreach ($rows as $row) {
        $reaction = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'website' => !empty($row['website']) ? $row['website'] : ($row['source_url'] ?? null),
            'avatar_url' => $row['avatar_url'] ?? null,
            'source_url' => $row['source_url'] ?? null,
            'created_at' => $row['created_at'],
        ];
        if ($row['type'] === 'like') {
            $likes[] = $reaction;
        } elseif ($row['type'] === 'repost') {
            $reposts[] = $reaction;
        }
    }

    return [
        'likes' => $likes,
        'reposts' => $reposts,
    ];
}

function fetch_comment_by_source(array $config, string $sourceUrl, string $slug): ?array
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT * FROM comments WHERE source_url = :source_url AND post_slug = :slug LIMIT 1');
    $stmt->execute([
        ':source_url' => $sourceUrl,
        ':slug' => $slug,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Backport from 1.8.5: insert or refresh a webmention.
 * - Keeps parent_id threading for Fediverse replies (fork behaviour).
 * - A re-sent webmention never demotes an already published row.
 * Returns ['id' => int, 'is_new' => bool, 'status' => string].
 */
function insert_or_update_webmention(array $config, array $data): array
{
    $pdo = db($config);
    $existing = fetch_comment_by_source($config, $data['source_url'], $data['post_slug']);
    if ($existing) {
        $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null
            ? $data['parent_id']
            : $existing['parent_id'];
        // Never let a re-sent webmention become its own parent (it would drop out of the thread).
        if ($parentId !== null && (int)$parentId === (int)$existing['id']) {
            $parentId = $existing['parent_id'];
        }
        $finalStatus = ($existing['status'] === 'published') ? 'published' : ($data['status'] ?? 'published');
        $title = $data['title'] ?? null;
        if (($title === null || $title === '') && !empty($existing['title'])) {
            $title = $existing['title'];
        }
        $stmt = $pdo->prepare(
            'UPDATE comments SET
                parent_id = :parent_id,
                name = :name,
                website = :website,
                avatar_url = :avatar_url,
                title = :title,
                type = :type,
                content_md = :content_md,
                content_html = :content_html,
                status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $existing['id'],
            ':parent_id' => $parentId,
            ':name' => $data['name'],
            ':website' => $data['website'] ?? null,
            ':avatar_url' => $data['avatar_url'] ?? null,
            ':title' => $title,
            ':type' => $data['type'] ?? 'comment',
            ':content_md' => $data['content_md'] ?? '',
            ':content_html' => $data['content_html'] ?? '',
            ':status' => $finalStatus,
        ]);
        return [
            'id' => (int)$existing['id'],
            'is_new' => false,
            'status' => $finalStatus,
        ];
    }

    $id = insert_comment($config, $data);
    return [
        'id' => $id,
        'is_new' => true,
        'status' => (string)($data['status'] ?? 'published'),
    ];
}

function is_source_url_matched(string $sourceUrl, string $pattern): bool
{
    $pattern = trim($pattern);
    if ($pattern === '') {
        return false;
    }

    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return false;
    }

    if (strcasecmp(rtrim($sourceUrl, '/'), rtrim($pattern, '/')) === 0) {
        return true;
    }

    $sourceParsed = parse_url($sourceUrl);
    $sourceHost = strtolower($sourceParsed['host'] ?? '');
    if ($sourceHost === '') {
        return false;
    }

    if (preg_match('#^https?://#i', $pattern)) {
        $patternParsed = parse_url($pattern);
        $patternHost = strtolower($patternParsed['host'] ?? '');
        $patternPath = rtrim($patternParsed['path'] ?? '', '/');

        if ($patternPath === '' || $patternPath === '/') {
            if ($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost)) {
                return true;
            }
        } else {
            $sourcePath = rtrim($sourceParsed['path'] ?? '', '/');
            if (($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost))
                && (str_starts_with($sourcePath, $patternPath) || strcasecmp($sourcePath, $patternPath) === 0)) {
                return true;
            }
        }
    } else {
        $patternClean = ltrim(strtolower($pattern), '*.');
        if (str_contains($patternClean, '/')) {
            $parts = explode('/', $patternClean, 2);
            $patternHost = $parts[0];
            $patternPath = '/' . rtrim($parts[1], '/');
            $sourcePath = rtrim($sourceParsed['path'] ?? '', '/');
            if (($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost))
                && (str_starts_with($sourcePath, $patternPath) || strcasecmp($sourcePath, $patternPath) === 0)) {
                return true;
            }
        } else {
            if ($sourceHost === $patternClean || str_ends_with($sourceHost, '.' . $patternClean)) {
                return true;
            }
        }
    }

    return false;
}

function get_blocked_webmentions(array $config): array
{
    $pdo = db($config);
    $stmt = $pdo->query('SELECT source_url FROM blocked_webmentions ORDER BY id DESC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return is_array($rows) ? $rows : [];
}

function is_webmention_source_blocked(array $config, string $sourceUrl): bool
{
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return false;
    }

    $configBlocked = $config['webmentions']['blocked_sources'] ?? [];
    if (is_string($configBlocked)) {
        $configBlocked = preg_split('/[\r\n]+/', $configBlocked);
    }
    if (is_array($configBlocked)) {
        foreach ($configBlocked as $rule) {
            if (is_source_url_matched($sourceUrl, (string)$rule)) {
                return true;
            }
        }
    }

    foreach (get_blocked_webmentions($config) as $rule) {
        if (is_source_url_matched($sourceUrl, (string)$rule)) {
            return true;
        }
    }

    return false;
}

/**
 * Auto-block helper used on delete. Only exact item URLs (with a real path)
 * are recorded, so deleting one Bridgy like/reply never blocks all of brid.gy
 * or a whole Fediverse instance.
 */
function auto_block_deleted_source(array $config, ?string $sourceUrl): void
{
    $sourceUrl = trim((string)$sourceUrl);
    if ($sourceUrl === '' || !preg_match('#^https?://#i', $sourceUrl)) {
        return;
    }
    $path = (string)(parse_url($sourceUrl, PHP_URL_PATH) ?? '');
    if (trim($path, '/') === '') {
        return;
    }
    block_webmention_source($config, $sourceUrl);
}

function block_webmention_source(array $config, string $sourcePattern): bool
{
    $sourcePattern = trim($sourcePattern);
    if ($sourcePattern === '') {
        return false;
    }
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO blocked_webmentions (source_url, created_at) VALUES (:source_url, :created_at)'
    );
    return $stmt->execute([
        ':source_url' => $sourcePattern,
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function unblock_webmention_source(array $config, string $sourcePattern): bool
{
    $sourcePattern = trim($sourcePattern);
    if ($sourcePattern === '') {
        return false;
    }
    $pdo = db($config);
    $stmt = $pdo->prepare('DELETE FROM blocked_webmentions WHERE source_url = :source_url');
    $stmt->execute([':source_url' => $sourcePattern]);
    return $stmt->rowCount() > 0;
}

function save_blocked_webmentions(array $config, array $patterns): void
{
    $pdo = db($config);
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM blocked_webmentions');
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO blocked_webmentions (source_url, created_at) VALUES (:source_url, :created_at)');
        $now = gmdate('Y-m-d H:i:s');
        $seen = [];
        foreach ($patterns as $pattern) {
            $p = trim((string)$pattern);
            if ($p !== '' && !isset($seen[$p])) {
                $seen[$p] = true;
                $stmt->execute([
                    ':source_url' => $p,
                    ':created_at' => $now,
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fetch_comments_by_status(
    array $config,
    string $status,
    string $direction = 'ASC',
    ?int $limit = null,
    int $offset = 0,
    ?string $slug = null,
    ?string $search = null,
    bool $includeReactions = false
): array
{
    $pdo = db($config);
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $offset = max(0, $offset);

    $where = 'WHERE status = :status';
    if (!$includeReactions) {
        $where .= " AND (type IS NULL OR type NOT IN ('like', 'repost'))";
    }
    if ($slug !== null && $slug !== '') {
        $where .= ' AND post_slug = :slug';
    }
    if ($search !== null && $search !== '') {
        $where .= ' AND (name LIKE :search OR content_md LIKE :search OR title LIKE :search)';
    }

    $sql =
        "SELECT id, post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url, title
         FROM comments
         {$where}
         ORDER BY created_at {$direction}";

    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    if ($slug !== null && $slug !== '') {
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
    }
    if ($search !== null && $search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }
    if ($limit !== null) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['email_plain'] = decrypt_email($row['email'], $config);
    }

    return $rows;
}

function fetch_pending_comments(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null, bool $includeReactions = false): array
{
    return fetch_comments_by_status($config, 'pending', 'ASC', $limit, $offset, $slug, $search, $includeReactions);
}

function fetch_published_comments_admin(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null, bool $includeReactions = false): array
{
    return fetch_comments_by_status($config, 'published', 'DESC', $limit, $offset, $slug, $search, $includeReactions);
}

function count_comments_by_status(array $config, string $status): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE status = :status');
    $stmt->execute([':status' => $status]);
    return (int)$stmt->fetchColumn();
}

function is_author_comment(array $config, ?string $encryptedEmail, string $name): bool
{
    $author = $config['author'] ?? [];
    $authorEmail = $author['email'] ?? '';
    $authorName = $author['name'] ?? '';

    if ($authorEmail !== '') {
        $plain = decrypt_email($encryptedEmail, $config);
        return $plain !== null && strcasecmp($plain, $authorEmail) === 0;
    }

    if ($authorName !== '') {
        return hash_equals($authorName, $name);
    }

    return false;
}

function fetch_comment_by_id(array $config, int $id): ?array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'SELECT * FROM comments WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['email_plain'] = decrypt_email($row['email'], $config);
    return $row;
}

function publish_comment(array $config, int $id): bool
{
    $pdo = db($config);
    $stmt = $pdo->prepare('UPDATE comments SET status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => 'published',
        ':id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

function delete_comment(array $config, int $id): bool
{
    $pdo = db($config);
    $srcStmt = $pdo->prepare('SELECT source_url FROM comments WHERE id = :id LIMIT 1');
    $srcStmt->execute([':id' => $id]);
    auto_block_deleted_source($config, ($v = $srcStmt->fetchColumn()) === false ? null : (string)$v);

    $stmt = $pdo->prepare('DELETE FROM comments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

function delete_comment_thread(array $config, int $rootId): bool
{
    $pdo = db($config);
    $exists = $pdo->prepare('SELECT 1 FROM comments WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $rootId]);
    if (!$exists->fetchColumn()) {
        return false;
    }

    $srcStmt = $pdo->prepare(
        'WITH RECURSIVE thread(id) AS (
            SELECT id FROM comments WHERE id = :root_id
            UNION ALL
            SELECT c.id
            FROM comments c
            INNER JOIN thread t ON c.parent_id = t.id
        )
        SELECT source_url FROM comments WHERE id IN (SELECT id FROM thread) AND source_url IS NOT NULL'
    );
    $srcStmt->execute([':root_id' => $rootId]);
    foreach ($srcStmt->fetchAll(PDO::FETCH_COLUMN) as $src) {
        auto_block_deleted_source($config, (string)$src);
    }

    $stmt = $pdo->prepare(
        'WITH RECURSIVE thread(id) AS (
            SELECT id FROM comments WHERE id = :root_id
            UNION ALL
            SELECT c.id
            FROM comments c
            INNER JOIN thread t ON c.parent_id = t.id
        )
        DELETE FROM comments
        WHERE id IN (SELECT id FROM thread)'
    );
    $stmt->execute([':root_id' => $rootId]);
    return true;
}

function insert_remember_token(array $config, string $tokenHash, int $expiresAt): void
{
    $pdo = db($config);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO remember_tokens (token_hash, expires_at) VALUES (:token_hash, :expires_at)');
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);
}

function verify_remember_token(array $config, string $tokenHash): bool
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT 1 FROM remember_tokens WHERE token_hash = :token_hash AND expires_at > :now LIMIT 1');
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':now'        => time(),
    ]);
    return (bool)$stmt->fetchColumn();
}

function delete_remember_token(array $config, string $tokenHash): void
{
    $pdo = db($config);
    $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = :token_hash');
    $stmt->execute([':token_hash' => $tokenHash]);
}

function &post_paths_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }
    return $cache;
}

function fetch_post_paths(array $config): array
{
    $cache = &post_paths_cache();
    static $loaded = false;
    if (!$loaded) {
        $pdo = db($config);
        $rows = $pdo->query('SELECT post_slug, post_path FROM post_paths')->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['post_slug']] = (string)$row['post_path'];
        }
        $loaded = true;
    }
    return $cache;
}

function fetch_post_path(array $config, string $slug): ?string
{
    $paths = fetch_post_paths($config);
    return $paths[$slug] ?? null;
}

function record_post_path(array $config, string $slug, string $path): void
{
    $pdo = db($config);
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO post_paths (post_slug, post_path, updated_at) VALUES (:slug, :path, :updated_at)');
    $stmt->execute([
        ':slug'       => $slug,
        ':path'       => $path,
        ':updated_at' => $now,
    ]);
    $cache = &post_paths_cache();
    $cache[$slug] = $path;
}

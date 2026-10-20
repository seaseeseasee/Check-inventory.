<?php
/**
 * Docker HEALTHCHECK for the app container (CLI only — lives outside public/,
 * so it is never reachable over HTTP).
 *
 * Not using /api/health because that route sits behind AuthMiddleware and
 * returns 401 without a session; making it public would also expose the DB
 * name/version. This checks the same two things directly:
 *   1. Apache + PHP answer on port 80
 *   2. The app's DB credentials (from the container env) can connect
 * Exit 0 = healthy, 1 = unhealthy.
 */

$ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$body = @file_get_contents('http://127.0.0.1/', false, $ctx);
$status = isset($http_response_header[0]) ? (int)(explode(' ', $http_response_header[0])[1] ?? 0) : 0;
if ($body === false || $status !== 200) {
    fwrite(STDERR, "web: unexpected status {$status}\n");
    exit(1);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        getenv('DB_HOST') ?: 'db',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_DATABASE') ?: '',
        getenv('DB_CHARSET') ?: 'utf8mb4'
    );
    $pdo = new PDO($dsn, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3,
    ]);
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "db: connection failed\n");
    exit(1);
}

exit(0);

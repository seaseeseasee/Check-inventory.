<?php
declare(strict_types=1);

/**
 * โหลดสภาพแวดล้อมเดียวกับ public/index.php สำหรับสคริปต์ CLI ใน bin/
 * Docker: docker compose exec backend php bin/<script>.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

function cli_out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function cli_err(string $msg): void
{
    fwrite(STDERR, $msg . PHP_EOL);
}

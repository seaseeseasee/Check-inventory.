<?php
declare(strict_types=1);

namespace App\Helpers;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function success(array $data = [], int $status = 200): void
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        $response = ['error' => $message];
        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }
        self::json($response, $status);
    }

    public static function validationError(array $errors, int $status = 422): void
    {
        // Surface the actual field messages in "error" so clients that only
        // display error.message still show a clear reason, not a generic text.
        $details = [];
        foreach ($errors as $messages) {
            foreach ((array)$messages as $message) {
                if (is_string($message) && $message !== '') {
                    $details[] = $message;
                }
            }
        }
        $summary = $details
            ? 'ข้อมูลไม่ผ่านการตรวจสอบ: ' . implode(' • ', array_unique($details))
            : 'ข้อมูลไม่ผ่านการตรวจสอบ';

        self::json(['error' => $summary, 'errors' => $errors], $status);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 404);
    }

    public static function conflict(string $message = 'Conflict', array $extra = []): void
    {
        self::error($message, 409, $extra);
    }

    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, 500);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): void
    {
        self::error($message, 429);
    }
}
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;

abstract class Controller
{
    protected function json(array $data, int $status = 200): void
    {
        Response::json($data, $status);
    }

    protected function success(array $data = [], int $status = 200): void
    {
        Response::success($data, $status);
    }

    protected function error(string $message, int $status = 400, array $extra = []): void
    {
        Response::error($message, $status, $extra);
    }

    protected function validationError(array $errors, int $status = 422): void
    {
        Response::validationError($errors, $status);
    }

    protected function notFound(string $message = 'Not found'): void
    {
        Response::notFound($message);
    }

    protected function getInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Malformed body must answer JSON 400, never a raw fatal page
            $this->error('Invalid JSON request body', 400);
        }
        return is_array($data) ? $data : [];
    }

    protected function getQuery(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    protected function getBody(string $key, mixed $default = null): mixed
    {
        $input = $this->getInput();
        return $input[$key] ?? $default;
    }
}
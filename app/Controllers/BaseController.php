<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;

abstract class BaseController
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

    protected function notFound(string $message = 'Not found'): void
    {
        Response::notFound($message);
    }

    protected function conflict(string $message = 'Conflict', array $extra = []): void
    {
        Response::conflict($message, $extra);
    }

    protected function validationError(array $errors): void
    {
        Response::validationError($errors);
    }

    protected function getRequestData(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    protected function validateRequired(array $data, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $missing[] = $field;
            }
        }
        return $missing;
    }
}
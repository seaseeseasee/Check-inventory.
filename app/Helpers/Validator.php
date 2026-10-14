<?php
declare(strict_types=1);

namespace App\Helpers;

class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $messages = [];

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    public function validate(): bool
    {
        foreach ($this->rules as $field => $rules) {
            $present = array_key_exists($field, $this->data);
            $value = $this->data[$field] ?? null;
            $ruleList = explode('|', $rules);
            
            foreach ($ruleList as $rule) {
                $method = 'validate' . ucfirst($rule);
                $baseRule = $rule;
                $params = [];
                if (preg_match('/^(\w+)\((.*)\)$/', $rule, $matches)) {
                    $method = 'validate' . ucfirst($matches[1]);
                    $baseRule = $matches[1];
                    $params = array_map('trim', explode(',', $matches[2]));
                } elseif (preg_match('/^(\w+):(.*)$/', $rule, $matches)) {
                    // Colon syntax (max:100, in:เช้า,บ่าย,ดึก) passes the raw value
                    // as a single param — validateIn() splits the list itself.
                    $method = 'validate' . ucfirst($matches[1]);
                    $baseRule = $matches[1];
                    $params = [trim($matches[2])];
                }
                // Absent key (partial update): only "required" can fail on it;
                // other rules are skipped so omitted optional fields don't fail
                // as null (e.g. PUT /users/{id} with only {"active":false}).
                if (!$present && $baseRule !== 'required') {
                    continue;
                }
                if (method_exists($this, $method)) {
                    if (!call_user_func_array([$this, $method], array_merge([$field, $value], $params))) {
                        $this->addError($field, $baseRule, $value, $params[0] ?? null);
                        break; // Stop on first failed rule for this field
                    }
                }
            }
        }
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    private function addError(string $field, string $rule, mixed $value, ?string $param = null): void
    {
        $key = $field . '.' . $rule;
        $message = $this->messages[$key] 
            ?? $this->messages[$rule] 
            ?? $this->defaultMessage($field, $rule, $param);
        $this->errors[$field][] = $message;
    }

    private function defaultMessage(string $field, string $rule, ?string $param = null): string
    {
        $messages = [
            'required' => 'กรุณากรอก :field',
            'string' => ':field ต้องเป็นข้อความ',
            'numeric' => ':field ต้องเป็นตัวเลข',
            'integer' => ':field ต้องเป็นจำนวนเต็ม',
            'boolean' => ':field ต้องเป็น true หรือ false',
            'email' => ':field รูปแบบอีเมลไม่ถูกต้อง',
            'min' => ':field ต้องมีอย่างน้อย :param ตัวอักษร',
            'max' => ':field ต้องไม่เกิน :param ตัวอักษร',
            'in' => ':field ค่าที่เลือกไม่ถูกต้อง',
            'date' => ':field รูปแบบวันที่ไม่ถูกต้อง',
            'unique' => ':field นี้มีอยู่ในระบบแล้ว',
        ];
        $msg = $messages[$rule] ?? ':field ไม่ผ่านการตรวจสอบ';
        return str_replace([':field', ':param'], [$field, (string)$param], $msg);
    }

    // Validation rules
    protected function validateRequired(string $field, mixed $value): bool
    {
        if ($value === null || $value === '') return false;
        if (is_array($value)) return count($value) > 0;
        return true;
    }

    protected function validateString(string $field, mixed $value): bool
    {
        return is_string($value);
    }

    protected function validateNumeric(string $field, mixed $value): bool
    {
        return is_numeric($value);
    }

    protected function validateInteger(string $field, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    protected function validateBoolean(string $field, mixed $value): bool
    {
        return is_bool($value) || in_array($value, [true, false, 'true', 'false', 1, 0, '1', '0'], true);
    }

    protected function validateEmail(string $field, mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateMin(string $field, mixed $value, string $min): bool
    {
        if (is_string($value)) return strlen($value) >= (int)$min;
        if (is_array($value)) return count($value) >= (int)$min;
        if (is_numeric($value)) return $value >= (int)$min;
        return false;
    }

    protected function validateMax(string $field, mixed $value, string $max): bool
    {
        if (is_string($value)) return strlen($value) <= (int)$max;
        if (is_array($value)) return count($value) <= (int)$max;
        if (is_numeric($value)) return $value <= (int)$max;
        return false;
    }

    protected function validateIn(string $field, mixed $value, string $values): bool
    {
        $allowed = array_map('trim', explode(',', $values));
        return in_array($value, $allowed, true);
    }

    protected function validateDate(string $field, mixed $value): bool
    {
        if (!is_string($value)) return false;
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }

    protected function validateUnique(string $field, mixed $value, string $table, string $column = null): bool
    {
        $db = \App\Config\Database::getConnection();
        $column = $column ?? $field;
        $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$value]);
        return !$stmt->fetch();
    }
}
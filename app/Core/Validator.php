<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === [])) {
            $this->fail($field, Lang::get('validation.required', ['field' => $label]));
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, Lang::get('validation.email', ['field' => $label]));
        }

        return $this;
    }

    public function url(string $field, string $label): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value === '') {
            return $this;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($value, PHP_URL_HOST) === null) {
            $this->fail($field, Lang::get('validation.url', ['field' => $label]));
        }

        return $this;
    }

    public function between(string $field, string $label, int $min, int $max): self
    {
        $value = $this->data[$field] ?? null;
        if ($value === null || $value === '') {
            return $this;
        }
        $number = (int) $value;
        if ($number < $min || $number > $max) {
            $this->fail($field, Lang::get('validation.between', ['field' => $label, 'min' => $min, 'max' => $max]));
        }

        return $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) > $max) {
            $this->fail($field, Lang::get('validation.max', ['field' => $label, 'max' => $max]));
        }

        return $this;
    }

    /** @param array<int,string> $allowed */
    public function in(string $field, string $label, array $allowed): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->fail($field, Lang::get('validation.invalid', ['field' => $label]));
        }

        return $this;
    }

    public function password(string $field): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if ($value !== '' && mb_strlen($value) < 10) {
            $this->fail($field, Lang::get('validation.password'));
        }

        return $this;
    }

    public function matches(string $field, string $other): self
    {
        if ((string) ($this->data[$field] ?? '') !== (string) ($this->data[$other] ?? '')) {
            $this->fail($field, Lang::get('validation.confirm'));
        }

        return $this;
    }

    public function custom(string $field, bool $passes, string $message): self
    {
        if (!$passes) {
            $this->fail($field, $message);
        }

        return $this;
    }

    public function fail(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;

        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }
}

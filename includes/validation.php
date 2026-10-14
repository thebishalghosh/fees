<?php
declare(strict_types=1);

function get_post_value(string $key, string $default = ''): string
{
    if (!isset($_POST[$key])) {
        return $default;
    }

    if (!is_string($_POST[$key]) && !is_numeric($_POST[$key])) {
        throw new InvalidArgumentException('The submitted value must be a scalar string.');
    }

    return trim((string) $_POST[$key]);
}

function validate_min_length(string $value, int $minLength): bool
{
    return $minLength >= 0 && mb_strlen(trim($value), 'UTF-8') >= $minLength;
}

function validate_max_length(string $value, int $maxLength): bool
{
    return $maxLength >= 0 && mb_strlen($value, 'UTF-8') <= $maxLength;
}

function validate_alpha_numeric_space(string $value): bool
{
    return preg_match('/^[A-Za-z0-9 _.-]+$/u', $value) === 1;
}

function validate_integer_range(string $value, int $minimum, int $maximum): bool
{
    if ($minimum > $maximum) {
        return false;
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT);
    return $integer !== false && $integer >= $minimum && $integer <= $maximum;
}

function validate_one_of(string $value, array $allowedValues): bool
{
    return in_array($value, $allowedValues, true);
}

<?php

namespace App\Services\Tools;

/**
 * Reads credentials from the plain-text environment file that the social
 * tools fall back to when a token is not passed as an argument.
 *
 * The path is configurable via AGENT_CREDENTIALS_FILE (config agent.credentials_file)
 * and defaults to /tmp/laraclaw/.env.
 */
class CredentialsFile
{
    /**
     * The configured credentials file path.
     */
    public static function path(): string
    {
        return (string) config('agent.credentials_file', '/tmp/laraclaw/.env');
    }

    /**
     * A one-line reminder of where credentials live, for tool descriptions.
     */
    public static function hint(): string
    {
        return 'Credentials live in the environment file at '.self::path();
    }

    /**
     * Read a single credential, returning the fallback when absent or empty.
     */
    public static function get(string $key, ?string $fallback = null): ?string
    {
        $value = self::all()[$key] ?? null;

        if ($value === null || $value === '') {
            return $fallback;
        }

        return $value;
    }

    /**
     * Parse the credentials file into a key => value map.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $path = self::path();

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $values = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}

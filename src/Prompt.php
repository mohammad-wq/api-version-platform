<?php

declare(strict_types=1);

namespace Mohammad\ApiVersionPlatform;

/** Terminal questions for the CLI. Prompts go to STDERR so command output on STDOUT stays clean. */
final class Prompt
{
    /** True when a person can type an answer (STDIN is a terminal). */
    public static function interactive(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    public static function ask(string $label, ?string $default = null): string
    {
        fwrite(STDERR, $label . ($default !== null && $default !== '' ? " [{$default}]" : '') . ': ');
        $line = fgets(STDIN);
        if ($line === false) throw self::noInput($label);
        $answer = trim($line);
        return $answer === '' ? (string) $default : $answer;
    }

    /** Raised when a question cannot be answered because there is no keyboard (a Composer script, CI, closed stdin). */
    private static function noInput(string $label): \RuntimeException
    {
        return new \RuntimeException("Cannot ask for \"{$label}\": there is no terminal to type in (Composer scripts such as `composer avp` cannot prompt). "
            . 'Run `vendor/bin/avp login` directly in a terminal, or set AVP_CLOUD_EMAIL and AVP_CLOUD_PASSWORD.');
    }

    /** Reads a secret without echoing it. Falls back to a visible line only when no terminal control is available. */
    public static function secret(string $label): string
    {
        fwrite(STDERR, $label . ': ');
        if (!self::interactive()) {
            // Piped input (scripts, tests): there is nothing to hide.
            $line = fgets(STDIN);
            if ($line === false) throw self::noInput($label);
            return rtrim($line, "\r\n");
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if (function_exists('shell_exec')) {
                // No -NonInteractive: that switch makes Read-Host fail at once. The marker means only a real answer
                // counts; an error, or no PowerShell, falls through to the visible prompt below.
                $marker = 'AVPSECRET:';
                $secret = @shell_exec('powershell -NoProfile -Command "$p = Read-Host -AsSecureString; \'' . $marker . '\' + [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"');
                if (is_string($secret) && str_starts_with($secret, $marker)) {
                    fwrite(STDERR, "\n");
                    return rtrim(substr($secret, strlen($marker)), "\r\n");
                }
            }
            fwrite(STDERR, "(input will be visible) ");
        } elseif (function_exists('shell_exec') && trim((string) @shell_exec('command -v stty')) !== '') {
            @shell_exec('stty -echo');
            try {
                $line = fgets(STDIN);
            } finally {
                @shell_exec('stty echo');
                fwrite(STDERR, "\n");
            }
            return $line === false ? '' : rtrim($line, "\r\n");
        } else {
            fwrite(STDERR, "(input will be visible) ");
        }
        $line = fgets(STDIN);
        return $line === false ? '' : rtrim($line, "\r\n");
    }
}

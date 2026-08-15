<?php

declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('icon')) {
    /**
     * Render an accessibility-friendly button icon (emoji) by name.
     */
    function icon(string $name, ?string $label = null): string
    {
        $icons = [
            'add' => '➕',
            'delete' => '➖',
            'edit' => '✏️',
            'copy' => '📋',
            'archive' => '📁',
            'unarchive' => '📂',
            'history' => '🕘',
            'activate' => '▶️',
            'deactivate' => '⏸️',
            'search' => '🔍',
            'download' => '⬇️',
            'upload' => '⬆️',
            'inspect' => '👁️',
            'select' => '✔️',
            'inactive' => '🚫',
            'back' => '←',
            'next' => '→',
            'save' => '💾',
        ];

        $output = '<span class="btn-icon" aria-hidden="true">' . ($icons[$name] ?? '▪') . '</span>';
        if ($label !== null && $label !== '') {
            $output .= '<span class="sr-only">' . e($label) . '</span>';
        }

        return $output;
    }
}

if (!function_exists('status_key')) {
    function status_key(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'status.active',
            'archived' => 'status.archived',
            'draft' => 'status.draft',
            default => 'status.' . strtolower($status),
        };
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(mixed $token): bool
    {
        $expected = $_SESSION['_csrf_token'] ?? null;

        if (!is_string($expected) || !is_string($token)) {
            return false;
        }

        return hash_equals($expected, $token);
    }
}

if (!function_exists('flash')) {
    /**
     * Set or consume a one-shot flash message stored in the session.
     */
    function flash(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;

            return null;
        }

        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return $message;
    }
}


if (!function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        static $nonce = null;
        return $nonce ??= base64_encode(random_bytes(24));
    }
}


if (!function_exists('security_message')) {
    function security_message(string $key): string
    {
        $locale = $_SESSION['php_compte_locale'] ?? 'en';
        if (!is_string($locale) || !in_array($locale, ['en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl'], true)) {
            $locale = 'en';
        }
        $messages = require dirname(__DIR__, 2) . '/lang/' . $locale . '.php';
        return is_string($messages[$key] ?? null) ? $messages[$key] : 'Service unavailable.';
    }
}

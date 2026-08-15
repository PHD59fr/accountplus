<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class View
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $templatePath,
        private array $shared = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function share(array $data): void
    {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    public function translate(string $key, array $replace = []): string
    {
        $translator = $this->shared['translator'] ?? null;

        if (!$translator instanceof Translator) {
            return $key;
        }

        return $translator->get($key, $replace);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templatePath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }

        $payload = array_merge($this->shared, $data);
        extract($payload, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    public function renderError(int $status): string
    {
        $template = match ($status) {
            403 => 'error/403',
            404 => 'error/404',
            500 => 'error/500',
            default => 'error/500',
        };

        return $this->render($template, ['status' => $status]);
    }
}

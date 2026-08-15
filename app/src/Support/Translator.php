<?php

declare(strict_types=1);

namespace App\Support;

final class Translator
{
    /**
     * @param array<string, mixed> $messages
     * @param array<string, string> $supportedLocales
     */
    public function __construct(
        private string $locale,
        private array $messages,
        private array $supportedLocales
    ) {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @return array<string, string>
     */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $value = $this->messages[$key] ?? $key;
        if (!is_string($value)) {
            return $key;
        }

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }

        return $value;
    }
}

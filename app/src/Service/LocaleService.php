<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;
use App\Support\Config;
use App\Support\Translator;
use RuntimeException;

final class LocaleService
{
    private const SESSION_KEY = 'php_compte_locale';

    /**
     * @var array<string, string>
     */
    private array $supportedLocales = [
        'en' => '🇬🇧 English',
        'fr' => '🇫🇷 Français',
        'de' => '🇩🇪 Deutsch',
        'es' => '🇪🇸 Español',
        'it' => '🇮🇹 Italiano',
        'pt' => '🇵🇹 Português',
        'nl' => '🇳🇱 Nederlands',
        'pl' => '🇵🇱 Polski',
    ];

    public function __construct(
        private string $basePath,
        private Config $config,
        private ?UserRepository $userRepository = null,
        private ?AuthService $authService = null
    ) {
    }

    public function resolve(): Translator
    {
        $locale = $this->config->get('locale', 'en');

        if ($this->authService !== null && $this->authService->isAuthenticated()) {
            $locale = $this->authService->locale() ?? $locale;

            if ($locale === $this->config->get('locale', 'en') && $this->userRepository !== null) {
                $userId = $this->authService->userId();
                if ($userId !== null) {
                    $user = $this->userRepository->findById($userId);
                    if ($user !== null) {
                        $locale = $user->locale();
                        $this->authService->setLocale($locale);
                    }
                }
            }
        } elseif (isset($_SESSION[self::SESSION_KEY])) {
            $locale = (string) $_SESSION[self::SESSION_KEY];
        }

        $locale = $this->normalize($locale);
        $_SESSION[self::SESSION_KEY] = $locale;

        $messages = $this->loadMessages($locale);

        return new Translator($locale, $messages, $this->supportedLocales);
    }

    public function setLocale(string $locale): void
    {
        $locale = $this->normalize($locale);
        $_SESSION[self::SESSION_KEY] = $locale;

        if ($this->authService !== null && $this->authService->isAuthenticated()) {
            $this->authService->setLocale($locale);
            if ($this->userRepository !== null) {
                $userId = $this->authService->userId();
                if ($userId !== null) {
                    $this->userRepository->updateLocale($userId, $locale);
                }
            }
        }
    }

    private function normalize(string $locale): string
    {
        return array_key_exists($locale, $this->supportedLocales) ? $locale : 'en';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMessages(string $locale): array
    {
        $file = $this->basePath . '/app/lang/' . $locale . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Translation file not found for locale: ' . $locale);
        }

        $messages = require $file;
        $fallback = $locale === 'en' ? [] : require $this->basePath . '/app/lang/en.php';
        return array_replace(is_array($fallback) ? $fallback : [], is_array($messages) ? $messages : []);
    }
}

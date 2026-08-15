<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\LocaleService;

final class LocaleController
{
    public function __construct(
        private LocaleService $localeService
    ) {
    }

    public function switch(Request $request): Response
    {
        $this->localeService->setLocale((string) $request->input('locale', 'en'));

        $parts = parse_url((string) $request->server('HTTP_REFERER', ''));
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return Response::redirect($path);
    }
}

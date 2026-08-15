<?php

declare(strict_types=1);

use App\Service\DashboardSettings;
use App\Support\View;

return [
    'enabled label chart stays visible without eligible transactions' => static function (): void {
        $view = new View(dirname(__DIR__, 2) . '/app/templates');
        $widgets = array_fill_keys(array_keys(DashboardSettings::WIDGETS), false);
        $widgets['label_breakdown'] = true;
        $company = new App\Domain\Company(1, 'Empty company', 'TEST', 'Active', 0, 0);
        $html = $view->render('dashboard/index', [
            'appName' => 'Account+', 'currentCompany' => $company, 'widgets' => $widgets,
            'breakdown' => [], 'labelBreakdown' => ['currency' => 'EUR', 'labels' => []],
            't' => static fn (string $key): string => $key,
        ]);
        if (!str_contains($html, 'data-dashboard-widget="label_breakdown"')
            || !str_contains($html, 'dashboard.no_label_data')
            || str_contains($html, 'id="dashboard-label"')) {
            throw new RuntimeException('The empty label chart should show a message, without an empty canvas.');
        }
        $widgets['label_breakdown'] = false;
        $hidden = $view->render('dashboard/index', [
            'appName' => 'Account+', 'currentCompany' => $company, 'widgets' => $widgets,
            'breakdown' => [], 'labelBreakdown' => ['currency' => 'EUR', 'labels' => []],
            't' => static fn (string $key): string => $key,
        ]);
        if (str_contains($hidden, 'data-dashboard-widget="label_breakdown"')) {
            throw new RuntimeException('The disabled label chart should stay hidden.');
        }
    },
];

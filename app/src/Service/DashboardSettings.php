<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SettingsRepository;
use InvalidArgumentException;

final class DashboardSettings
{
    public const WIDGETS = [
        'balance' => 'dashboard.global_balance',
        'income' => 'dashboard.income',
        'expenses' => 'dashboard.expenses',
        'recurring' => 'dashboard.recurring',
        'accounts' => 'dashboard.accounts',
        'recent_transactions' => 'dashboard.recent_transactions',
        'category_breakdown' => 'reports.by_category',
        'type_breakdown' => 'reports.by_type',
        'label_breakdown' => 'dashboard.by_label',
    ];

    public function __construct(private ?SettingsRepository $settings = null, private ?AuditLogService $audit = null)
    {
    }

    /** @return array<string, bool> */
    public function read(): array
    {
        $widgets = array_fill_keys(array_keys(self::WIDGETS), true);
        $saved = json_decode($this->settings?->get('dashboard_widgets', '') ?? '', true);
        if (is_array($saved)) {
            foreach ($widgets as $key => $default) {
                if (isset($saved[$key]) && is_bool($saved[$key])) {
                    $widgets[$key] = $saved[$key];
                }
            }
        }
        return $widgets;
    }

    /** @param array<mixed> $selected */
    public function update(array $selected): void
    {
        if (!array_is_list($selected) || count($selected) > count(self::WIDGETS)) {
            throw new InvalidArgumentException('settings.dashboard_invalid');
        }
        $widgets = array_fill_keys(array_keys(self::WIDGETS), false);
        foreach ($selected as $key) {
            if (!is_string($key) || !array_key_exists($key, $widgets)) {
                throw new InvalidArgumentException('settings.dashboard_invalid');
            }
            $widgets[$key] = true;
        }
        if ($this->settings === null) {
            throw new InvalidArgumentException('settings.dashboard_unavailable');
        }
        $old = $this->read();
        $this->settings->set('dashboard_widgets', json_encode($widgets, JSON_THROW_ON_ERROR));
        $this->audit?->log('settings.dashboard.update', 'settings', 'dashboard_widgets', $old, $widgets);
    }
}

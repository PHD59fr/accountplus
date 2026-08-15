<?php
/**
 * Reusable category/type breakdown charts (Chart.js).
 *
 * Expected variables:
 *  - array  $breakdown   result of AppDataService::categoryBreakdown()
 *  - string $chartPrefix unique id prefix when several charts share a page
 *  - bool   $showCategoryChart, $showTypeChart (optional, both default to true)
 */
$chartPrefix = (string) ($chartPrefix ?? 'bd');
$t = $t ?? static fn ($key) => $key;
$showCategoryChart = $showCategoryChart ?? true;
$showTypeChart = $showTypeChart ?? true;

if ((!$showCategoryChart && !$showTypeChart) || !isset($breakdown) || !is_array($breakdown) || ((array) ($breakdown['categories'] ?? [])) === []) {
    return;
}

$chartData = [
    'currency' => (string) ($breakdown['currency'] ?? 'EUR'),
    'categories' => array_map(static function (array $c): array {
        return [
            'label' => (!empty($c['emoji']) ? $c['emoji'] . ' ' : '') . $c['name'],
            'total' => (float) ($c['total'] ?? 0),
            'percent' => round((float) ($c['percent'] ?? 0), 1),
            'type' => (string) ($c['type'] ?? ''),
        ];
    }, (array) ($breakdown['categories'] ?? [])),
    'by_type' => (array) ($breakdown['by_type'] ?? ['income' => 0.0, 'expense' => 0.0, 'transfer' => 0.0]),
];
?>

<div class="breakdown-charts<?= $showCategoryChart && $showTypeChart ? '' : ' breakdown-charts-single' ?>">
    <?php if ($showCategoryChart): ?>
    <div class="chart-box" data-dashboard-widget="category_breakdown">
        <h4><?= e($t('reports.by_category')) ?></h4>
        <div class="chart-canvas"><canvas id="<?= e($chartPrefix) ?>-cat"></canvas></div>
    </div>
    <?php endif; ?>
    <?php if ($showTypeChart): ?>
    <div class="chart-box" data-dashboard-widget="type_breakdown">
        <h4><?= e($t('reports.by_type')) ?></h4>
        <div class="chart-canvas"><canvas id="<?= e($chartPrefix) ?>-type"></canvas></div>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="<?= e($chartPrefix) ?>-data"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('<?= e($chartPrefix) ?>-data');
    if (!el || typeof Chart === 'undefined') {
        return;
    }

    var data = JSON.parse(el.textContent);
    var palette = ['#4f7c4f', '#3b6ea5', '#c9872f', '#9b5ba6', '#4a9aa3', '#c2554d', '#7a8a3a', '#5a6b8c', '#b06f9e', '#3f8f6b'];

    var catLabels = data.categories.map(function (c) { return c.label; });
    var catValues = data.categories.map(function (c) { return Math.abs(c.total); });
    var catPercents = data.categories.map(function (c) { return c.percent; });
    var percentFormat = new Intl.NumberFormat(document.documentElement.lang || 'fr', { maximumFractionDigits: 1 });

    function percentageLabels(percents) {
        return function (chart) {
            return Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart).map(function (item) {
                item.text += ' (' + percentFormat.format(percents[item.index]) + ' %)';
                return item;
            });
        };
    }

    <?php if ($showCategoryChart): ?>
    new Chart(document.getElementById('<?= e($chartPrefix) ?>-cat'), {
        type: 'doughnut',
        data: {
            labels: catLabels,
            datasets: [{
                data: catValues,
                backgroundColor: catLabels.map(function (_, i) { return palette[i % palette.length]; }),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        font: { size: 11 },
                        generateLabels: percentageLabels(catPercents)
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.label + ' : ' + ctx.parsed.toFixed(2) + ' ' + data.currency
                                + ' (' + percentFormat.format(catPercents[ctx.dataIndex]) + ' %)';
                        }
                    }
                }
            }
        }
    });

    <?php endif; ?>
    <?php if ($showTypeChart): ?>
    var types = data.by_type;
    var typeValues = [Math.max(0, types.income || 0), Math.max(0, types.expense || 0), Math.max(0, types.transfer || 0)];
    var typeTotal = typeValues.reduce(function (total, value) { return total + value; }, 0);
    var typePercents = typeValues.map(function (value) { return typeTotal > 0 ? value / typeTotal * 100 : 0; });
    new Chart(document.getElementById('<?= e($chartPrefix) ?>-type'), {
        type: 'doughnut',
        data: {
            labels: ['<?= e($t('categories.type_income')) ?>', '<?= e($t('categories.type_expense')) ?>', '<?= e($t('categories.type_transfer')) ?>'],
            datasets: [{
                data: typeValues,
                backgroundColor: ['#3b6ea5', '#c2554d', '#7a8a3a'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { boxWidth: 12, font: { size: 11 }, generateLabels: percentageLabels(typePercents) }
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.label + ' : ' + ctx.parsed.toFixed(2) + ' ' + data.currency
                                + ' (' + percentFormat.format(typePercents[ctx.dataIndex]) + ' %)';
                        }
                    }
                }
            }
        }
    });
    <?php endif; ?>
});
</script>

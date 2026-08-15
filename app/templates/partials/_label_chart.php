<?php
/** @var array $labelBreakdown */
$labelData = [
    'currency' => $labelBreakdown['currency'] ?? 'EUR',
    'labels' => array_map(static function (array $row) use ($t): array {
        return [
            'label' => $row['other'] ? $t('dashboard.other_labels') : ($row['label'] ?? $t('dashboard.unlabelled')),
            'total' => $row['total'],
            'percent' => round($row['percent'], 1),
            'other' => $row['other'],
        ];
    }, $labelBreakdown['labels'] ?? []),
];
?>
<div class="breakdown-charts breakdown-charts-single">
    <div class="chart-box" data-dashboard-widget="label_breakdown">
        <h4><?= e($t('dashboard.by_label')) ?></h4>
        <p class="section-text"><?= e($t('dashboard.by_label_hint')) ?></p>
        <?php if ($labelData['labels'] === []): ?>
            <p class="section-text"><?= e($t('dashboard.no_label_data')) ?></p>
        <?php else: ?>
        <div class="chart-canvas"><canvas id="dashboard-label"></canvas></div>
        <?php endif; ?>
    </div>
</div>
<?php if ($labelData['labels'] === []) { return; } ?>
<script nonce="<?= e(csp_nonce()) ?>" type="application/json" id="dashboard-label-data"><?= json_encode($labelData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('dashboard-label-data');
    if (!el || typeof Chart === 'undefined') {
        return;
    }
    var data = JSON.parse(el.textContent);
    var palette = ['#4f7c4f', '#3b6ea5', '#c9872f', '#9b5ba6', '#4a9aa3', '#c2554d', '#7a8a3a', '#5a6b8c', '#b06f9e', '#3f8f6b'];
    var percent = new Intl.NumberFormat(document.documentElement.lang || 'fr', { maximumFractionDigits: 1 });
    var amount = new Intl.NumberFormat(document.documentElement.lang || 'fr', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    new Chart(document.getElementById('dashboard-label'), {
        type: 'doughnut',
        data: {
            labels: data.labels.map(function (row) { return row.label; }),
            datasets: [{
                data: data.labels.map(function (row) { return row.total; }),
                backgroundColor: data.labels.map(function (row, index) { return row.other ? '#8b9290' : palette[index % palette.length]; }),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12,
                        font: { size: 11 },
                        generateLabels: function (chart) {
                            return Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart).map(function (item) {
                                var characters = Array.from(item.text);
                                var label = characters.length > 32 ? characters.slice(0, 32).join('') + '…' : item.text;
                                item.text = label + ' (' + percent.format(data.labels[item.index].percent) + ' %)';
                                return item;
                            });
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ctx.label + ' : ' + amount.format(ctx.parsed) + ' ' + data.currency
                                + ' (' + percent.format(data.labels[ctx.dataIndex].percent) + ' %)';
                        }
                    }
                }
            }
        }
    });
});
</script>

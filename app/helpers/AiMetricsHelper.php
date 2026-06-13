<?php

require_once __DIR__ . '/DiagnosisHelper.php';

class AiMetricsHelper
{
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $path = BASE_PATH . '/config/ai_models.php';
            self::$config = is_file($path) ? require $path : [
                'benchmark_accuracy' => ['cnn' => 51.53, 'vgg16' => 91.01, 'resnet50' => 95.54],
                'ensemble_weights' => ['cnn' => 0.30, 'vgg16' => 0.35, 'resnet50' => 0.35],
            ];
        }
        return self::$config;
    }

    public static function benchmarkAccuracy(string $model): float
    {
        $key = strtolower($model);
        return (float)(self::config()['benchmark_accuracy'][$key] ?? 0);
    }

    /**
     * Agreement derived from each model's benchmark accuracy and case confidence.
     */
    public static function computeAgreementMetrics(
        string $cnnLabel,
        string $vggLabel,
        string $resnetLabel,
        float $cnnConf,
        float $vggConf,
        float $resnetConf,
        string $finalLabel
    ): array {
        $final = DiagnosisHelper::normalize($finalLabel);
        $models = [
            [
                'key' => 'cnn',
                'label' => DiagnosisHelper::normalize($cnnLabel),
                'confidence' => self::normalizePercent($cnnConf),
                'accuracy' => self::benchmarkAccuracy('cnn'),
            ],
            [
                'key' => 'vgg16',
                'label' => DiagnosisHelper::normalize($vggLabel),
                'confidence' => self::normalizePercent($vggConf),
                'accuracy' => self::benchmarkAccuracy('vgg16'),
            ],
            [
                'key' => 'resnet50',
                'label' => DiagnosisHelper::normalize($resnetLabel),
                'confidence' => self::normalizePercent($resnetConf),
                'accuracy' => self::benchmarkAccuracy('resnet50'),
            ],
        ];

        $labels = array_column($models, 'label');
        $majority = self::majorityLabel($labels);
        $labelConcordance = (count(array_filter($labels, fn($l) => $l === $majority)) / 3) * 100;

        $weights = self::config()['ensemble_weights'];
        $weightedConfidence = 0.0;
        $weightSum = 0.0;
        foreach ($models as $model) {
            $w = (float)($weights[$model['key']] ?? 0);
            $weightedConfidence += $w * ($model['accuracy'] / 100) * ($model['confidence'] / 100);
            $weightSum += $w;
        }
        $weightedConfidence = $weightSum > 0 ? ($weightedConfidence / $weightSum) * 100 : 0.0;

        $aligned = array_values(array_filter($models, fn($m) => $m['label'] === $final));
        if ($aligned !== []) {
            $accSum = array_sum(array_column($aligned, 'accuracy'));
            $agreementAccuracy = ($accSum / count($aligned));
            $agreementConfidence = $accSum > 0
                ? (array_sum(array_map(fn($m) => $m['accuracy'] * $m['confidence'], $aligned)) / $accSum)
                : 0.0;
        } else {
            $agreementAccuracy = 0.0;
            $agreementConfidence = $weightedConfidence * 0.5;
        }

        $composite = (0.35 * $labelConcordance)
            + (0.35 * $agreementAccuracy)
            + (0.30 * $agreementConfidence);

        return [
            'cnn_accuracy' => round(self::benchmarkAccuracy('cnn'), 2),
            'vgg_accuracy' => round(self::benchmarkAccuracy('vgg16'), 2),
            'resnet_accuracy' => round(self::benchmarkAccuracy('resnet50'), 2),
            'agreement_label_pct' => round($labelConcordance, 2),
            'agreement_accuracy_pct' => round($agreementAccuracy, 2),
            'agreement_confidence_pct' => round($agreementConfidence, 2),
            'model_agreement_score' => round($composite, 2),
        ];
    }

    private static function normalizePercent(float $value): float
    {
        if ($value > 0 && $value <= 1) {
            $value *= 100;
        }
        return max(0.0, min(100.0, $value));
    }

    private static function majorityLabel(array $labels): string
    {
        $counts = array_count_values($labels);
        arsort($counts);
        return (string)array_key_first($counts);
    }
}

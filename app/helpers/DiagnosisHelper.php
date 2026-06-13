<?php

/**
 * Canonical ophthalmology diagnosis labels (4-class system).
 */
class DiagnosisHelper
{
    public const NORMAL = 'Normal';
    public const CATARACT = 'Cataract';
    public const GLAUCOMA = 'Glaucoma';
    public const DIABETIC_RETINOPATHY = 'Diabetic Retinopathy';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::NORMAL,
            self::CATARACT,
            self::GLAUCOMA,
            self::DIABETIC_RETINOPATHY,
        ];
    }

    /**
     * Map any stored or AI label to one of the four canonical names.
     */
    public static function normalize(?string $label): string
    {
        if ($label === null || trim($label) === '') {
            return self::NORMAL;
        }

        $key = strtolower(trim(preg_replace('/\s+/', ' ', $label)));
        $key = str_replace(['_', '-'], ' ', $key);

        $exact = [
            'normal' => self::NORMAL,
            'cataract' => self::CATARACT,
            'glaucoma' => self::GLAUCOMA,
            'diabetic retinopathy' => self::DIABETIC_RETINOPATHY,
            'diabeticretinopathy' => self::DIABETIC_RETINOPATHY,
            'retinopathy' => self::DIABETIC_RETINOPATHY,
            'dr' => self::DIABETIC_RETINOPATHY,
        ];

        if (isset($exact[$key])) {
            return $exact[$key];
        }

        if (str_contains($key, 'diabet') || str_contains($key, 'retinopath')) {
            return self::DIABETIC_RETINOPATHY;
        }
        if (str_contains($key, 'glauc')) {
            return self::GLAUCOMA;
        }
        if (str_contains($key, 'catar')) {
            return self::CATARACT;
        }
        if (str_contains($key, 'normal') || str_contains($key, 'healthy')) {
            return self::NORMAL;
        }

        return self::NORMAL;
    }

    public static function isValid(string $label): bool
    {
        return in_array($label, self::all(), true);
    }

    public static function cssClass(string $label): string
    {
        switch (self::normalize($label)) {
            case self::NORMAL:
                return 'dx-normal';
            case self::CATARACT:
                return 'dx-cataract';
            case self::GLAUCOMA:
                return 'dx-glaucoma';
            case self::DIABETIC_RETINOPATHY:
                return 'dx-diabetic';
            default:
                return 'dx-default';
        }
    }

    public static function defaultRisk(string $label): string
    {
        switch (self::normalize($label)) {
            case self::CATARACT:
                return 'Medium';
            case self::GLAUCOMA:
            case self::DIABETIC_RETINOPATHY:
                return 'High';
            default:
                return 'Low';
        }
    }

    /**
     * Clinical priority from diagnosis + AI certainty and model agreement.
     *
     * Low    = Normal with confident agreement, or very uncertain non-urgent reads
     * Medium = Cataract, or any equivocal result needing clinical review
     * High   = Diabetic retinopathy / glaucoma when AI is sufficiently confident
     */
    public static function computeRiskLevel(string $diagnosis, float $certaintyPct, float $agreementPct): string
    {
        $dx = self::normalize($diagnosis);
        $certainty = max(0.0, min(100.0, $certaintyPct));
        $agreement = max(0.0, min(100.0, $agreementPct));

        if ($dx === self::NORMAL) {
            if ($certainty >= 75.0 && $agreement >= 75.0) {
                return 'Low';
            }
            return 'Medium';
        }

        if ($dx === self::CATARACT) {
            if ($certainty < 55.0 || $agreement < 55.0) {
                return 'Low';
            }
            return 'Medium';
        }

        if ($dx === self::DIABETIC_RETINOPATHY || $dx === self::GLAUCOMA) {
            if ($certainty >= 70.0 && $agreement >= 70.0) {
                return 'High';
            }
            return 'Medium';
        }

        return self::defaultRisk($dx);
    }

    /**
     * Plain-language clinical guidance for reports (ophthalmologist-facing).
     */
    public static function clinicalRecommendation(string $label): string
    {
        return self::unanimousClinicalNote(self::normalize($label));
    }

    /**
     * Clinical note based on CNN, VGG16 and ResNet outputs (ordered pattern-aware).
     */
    public static function clinicalNoteFromModels(
        string $cnn,
        string $vgg,
        string $resnet,
        ?string $finalResult = null
    ): string {
        $cnn = self::normalize($cnn);
        $vgg = self::normalize($vgg);
        $resnet = self::normalize($resnet);
        $final = self::normalize($finalResult ?? '');

        $labels = [$cnn, $vgg, $resnet];
        $unique = array_values(array_unique($labels));
        $key = self::patternKey(...$labels);
        $action = self::finalAction($final);

        if (count($unique) === 1) {
            return self::unanimousClinicalNote($unique[0]);
        }

        if (count($unique) === 2) {
            $counts = array_count_values($labels);
            $majority = null;
            $minority = null;
            foreach ($counts as $dx => $count) {
                if ($count === 2) {
                    $majority = $dx;
                }
                if ($count === 1) {
                    $minority = $dx;
                }
            }

            $outlier = self::findOutlierModel($cnn, $vgg, $resnet, (string)$minority);
            $twoOneNotes = self::twoOneNotes();
            $plain = self::plainDxNames();
            $body = $twoOneNotes[$key] ?? sprintf(
                'Two readings suggest %s. %s indicates %s.',
                $plain[$majority] ?? strtolower((string)$majority),
                $outlier,
                $plain[$minority] ?? strtolower((string)$minority)
            );

            $outlierHint = str_contains($body, $outlier) ? '' : ' ' . $outlier . ' recorded the alternate finding.';
            return $body . $outlierHint . ' Overall diagnosis: ' . $final . '. ' . $action;
        }

        $threeWayNotes = self::threeWayNotes();
        $body = $threeWayNotes[$key] ?? sprintf(
            'Readings differ (%s, %s, %s). Clinical correlation is essential.',
            $cnn,
            $vgg,
            $resnet
        );

        return $body . ' Overall diagnosis: ' . $final . '. ' . $action;
    }

    /** @param string ...$labels */
    private static function patternKey(string ...$labels): string
    {
        $order = [
            self::CATARACT => 0,
            self::DIABETIC_RETINOPATHY => 1,
            self::GLAUCOMA => 2,
            self::NORMAL => 3,
        ];
        usort($labels, static function (string $a, string $b) use ($order): int {
            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });

        return implode('|', $labels);
    }

    private static function findOutlierModel(string $cnn, string $vgg, string $resnet, string $minority): string
    {
        if ($cnn === $minority && $vgg !== $minority && $resnet !== $minority) {
            return 'CNN';
        }
        if ($vgg === $minority && $cnn !== $minority && $resnet !== $minority) {
            return 'VGG16';
        }
        if ($resnet === $minority && $cnn !== $minority && $vgg !== $minority) {
            return 'ResNet';
        }

        return 'One model';
    }

    private static function unanimousClinicalNote(string $dx): string
    {
        $notes = [
            self::NORMAL => 'Fundus screening shows no significant abnormality. No pattern of cataract, glaucoma or diabetic retinopathy was identified. Continue routine eye checks. Review sooner if the patient reports vision changes or new symptoms.',
            self::CATARACT => 'All analyses are consistent with cataract. Lens opacity may be present and can affect vision. Arrange visual acuity testing and slit-lamp examination. Discuss treatment if daily activities are affected.',
            self::GLAUCOMA => 'All analyses suggest glaucoma-related findings. Optic nerve changes may be present. Arrange IOP measurement, optic disc review and visual field testing promptly.',
            self::DIABETIC_RETINOPATHY => 'All analyses suggest diabetic retinopathy. Diabetes-related retinal changes may be present. Arrange DR grading, HbA1c review and blood pressure assessment.',
        ];

        return $notes[$dx] ?? $notes[self::NORMAL];
    }

    /** @return array<string, string> */
    private static function plainDxNames(): array
    {
        return [
            self::NORMAL => 'normal',
            self::CATARACT => 'cataract',
            self::GLAUCOMA => 'glaucoma',
            self::DIABETIC_RETINOPATHY => 'diabetic retinopathy',
        ];
    }

    private static function finalAction(string $final): string
    {
        $actions = [
            self::NORMAL => 'No urgent action is needed if the patient is well. Continue routine follow-up.',
            self::CATARACT => 'Book ophthalmology review for slit-lamp examination and visual acuity.',
            self::GLAUCOMA => 'Arrange urgent IOP check and optic nerve assessment.',
            self::DIABETIC_RETINOPATHY => 'Arrange urgent DR grading and diabetes management review.',
        ];

        return $actions[$final] ?? 'Clinical review is recommended before any treatment decision.';
    }

    /** @return array<string, string> */
    private static function twoOneNotes(): array
    {
        return [
            'Normal|Normal|Cataract' => 'Most readings appear normal. One analysis suggests possible cataract. Early lens change can be difficult to see on a photograph alone. Slit-lamp review is advised.',
            'Normal|Normal|Glaucoma' => 'Most readings appear normal. One analysis raises glaucoma concern. Optic nerve disease is not always visible on a single fundus image. IOP and disc review are advised.',
            'Normal|Normal|Diabetic Retinopathy' => 'Most readings appear normal. One analysis suggests diabetic retinopathy. Mild retinal change can be overlooked on screening images. DR grading is advised if clinically indicated.',
            'Cataract|Cataract|Normal' => 'Most analyses suggest cataract. One reading appears normal. Lens opacity may be mild, or image quality may have influenced one result. Clinical correlation is recommended.',
            'Cataract|Cataract|Glaucoma' => 'Most analyses suggest cataract. One raises glaucoma concern. Both conditions can occur together. Assess lens clarity, IOP and the optic disc.',
            'Cataract|Cataract|Diabetic Retinopathy' => 'Most analyses suggest cataract. One suggests diabetic retinopathy. Dense lens change can obscure retinal detail. Consider dilated fundus examination.',
            'Glaucoma|Glaucoma|Normal' => 'Most analyses suggest glaucoma. One reading appears normal. Early optic nerve change may be subtle. Confirm with IOP measurement and disc assessment.',
            'Cataract|Glaucoma|Glaucoma' => 'Most analyses suggest glaucoma. One suggests cataract. Evaluate IOP, the optic disc and lens status together.',
            'Diabetic Retinopathy|Glaucoma|Glaucoma' => 'Most analyses suggest glaucoma. One suggests diabetic retinopathy. Both findings are sight-threatening. Prioritise full ophthalmology review.',
            'Diabetic Retinopathy|Diabetic Retinopathy|Normal' => 'Most analyses suggest diabetic retinopathy. One reading appears normal. Mild disease may still warrant grading and diabetes control review.',
            'Cataract|Diabetic Retinopathy|Diabetic Retinopathy' => 'Most analyses suggest diabetic retinopathy. One suggests cataract. Cataract can limit retinal views. Consider imaging after dilation if the retina cannot be assessed.',
            'Diabetic Retinopathy|Diabetic Retinopathy|Glaucoma' => 'Most analyses suggest diabetic retinopathy. One raises glaucoma concern. Screen for both retinal and optic nerve disease.',
        ];
    }

    /** @return array<string, string> */
    private static function threeWayNotes(): array
    {
        return [
            'Cataract|Diabetic Retinopathy|Glaucoma' => 'Analyses indicate three different conditions (cataract, diabetic retinopathy and glaucoma). This image is inconclusive. Full clinical examination is required before any treatment decision.',
            'Cataract|Glaucoma|Normal' => 'Readings are split between normal appearance, cataract and glaucoma. Bedside examination is needed to determine which finding applies.',
            'Cataract|Diabetic Retinopathy|Normal' => 'Readings are split between normal appearance, cataract and diabetic retinopathy. Correlate with symptoms, diabetes history and visual acuity.',
            'Diabetic Retinopathy|Glaucoma|Normal' => 'Readings are split between normal appearance, glaucoma and diabetic retinopathy. Check IOP, the optic disc, macula and diabetes control.',
        ];
    }

    /**
     * Suggested follow-up interval for report footer.
     */
    public static function followUpInterval(string $label, string $risk): string
    {
        $dx = self::normalize($label);
        $risk = ucfirst(strtolower(trim($risk)));

        if ($dx === self::NORMAL) {
            return '12 months (routine), or earlier if symptomatic';
        }
        if ($dx === self::CATARACT) {
            return $risk === 'High' ? '4 to 8 weeks' : '3 to 6 months';
        }
        if ($dx === self::DIABETIC_RETINOPATHY) {
            return $risk === 'High' ? '1 to 4 weeks' : '1 to 3 months';
        }
        if ($dx === self::GLAUCOMA) {
            return '1 to 4 weeks (urgent ophthalmology assessment)';
        }

        return 'As clinically indicated';
    }

    /**
     * Merge diagnosis breakdown rows to exactly four buckets.
     *
     * @param array<int, array{final_result: string, total: int|string}> $rows
     * @return array<string, int>
     */
    public static function mergeBreakdown(array $rows): array
    {
        $out = array_fill_keys(self::all(), 0);

        foreach ($rows as $row) {
            $name = self::normalize($row['final_result'] ?? '');
            $out[$name] = ($out[$name] ?? 0) + (int)($row['total'] ?? 0);
        }

        return $out;
    }
}

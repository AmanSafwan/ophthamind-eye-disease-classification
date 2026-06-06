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
     * Plain-language clinical guidance for reports (ophthalmologist-facing).
     */
    public static function clinicalRecommendation(string $label): string
    {
        switch (self::normalize($label)) {
            case self::NORMAL:
                return 'No significant retinal abnormality detected on this AI screening. '
                    . 'Recommend routine ophthalmic review per local guidelines (typically annually for adults at risk, '
                    . 'or sooner if symptomatic). Document visual acuity and optic disc assessment at next visit.';
            case self::CATARACT:
                return 'Lens opacity pattern consistent with cataract on fundus imaging. '
                    . 'Recommend comprehensive ophthalmology assessment including visual acuity, slit-lamp examination, '
                    . 'and discussion of surgical timing if vision-limiting. Monitor for progression and glare symptoms.';
            case self::DIABETIC_RETINOPATHY:
                return 'Features suggestive of diabetic retinopathy. '
                    . 'Recommend urgent diabetic retinopathy grading by an ophthalmologist, HbA1c review, blood pressure control, '
                    . 'and coordinated care with primary care / endocrinology. Consider wide-field imaging and macular OCT if available.';
            case self::GLAUCOMA:
                return 'Findings suggestive of glaucoma-related optic nerve risk. '
                    . 'Recommend formal IOP measurement, gonioscopy, optic nerve head assessment, visual field testing, '
                    . 'and OCT RNFL where available. Prioritise ophthalmology referral according to local glaucoma pathway.';
            default:
                return 'AI screening completed. Clinical correlation and specialist review recommended before treatment decisions.';
        }
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
            return $risk === 'High' ? '4–8 weeks' : '3–6 months';
        }
        if ($dx === self::DIABETIC_RETINOPATHY) {
            return $risk === 'High' ? '1–4 weeks' : '1–3 months';
        }
        if ($dx === self::GLAUCOMA) {
            return '1–4 weeks (urgent ophthalmology assessment)';
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

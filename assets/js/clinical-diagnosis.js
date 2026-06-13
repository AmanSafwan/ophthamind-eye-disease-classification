/**
 * Four-class diagnosis system (must match DiagnosisHelper.php & ai_api/app.py).
 */
(function () {
    const CLINICAL_DIAGNOSES = [
        'Normal',
        'Cataract',
        'Glaucoma',
        'Diabetic Retinopathy',
    ];

    function normalizeDiagnosis(label) {
        if (!label || !String(label).trim()) {
            return 'Normal';
        }

        let key = String(label).toLowerCase().trim().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');

        const exact = {
            normal: 'Normal',
            cataract: 'Cataract',
            glaucoma: 'Glaucoma',
            'diabetic retinopathy': 'Diabetic Retinopathy',
            diabeticretinopathy: 'Diabetic Retinopathy',
            retinopathy: 'Diabetic Retinopathy',
            dr: 'Diabetic Retinopathy',
        };

        if (exact[key]) {
            return exact[key];
        }

        if (key.includes('diabet') || key.includes('retinopath')) {
            return 'Diabetic Retinopathy';
        }
        if (key.includes('glauc')) {
            return 'Glaucoma';
        }
        if (key.includes('catar')) {
            return 'Cataract';
        }
        if (key.includes('normal') || key.includes('healthy')) {
            return 'Normal';
        }

        return 'Normal';
    }

    function getClinicalDxClass(result) {
        const n = normalizeDiagnosis(result);
        if (n === 'Normal') return 'dx-normal';
        if (n === 'Cataract') return 'dx-cataract';
        if (n === 'Glaucoma') return 'dx-glaucoma';
        if (n === 'Diabetic Retinopathy') return 'dx-diabetic';
        return 'dx-default';
    }

    function getDiagnosisTheme(label) {
        const n = normalizeDiagnosis(label);
        if (n === 'Normal') return { class: 'dx-normal', icon: 'fa-check-circle' };
        if (n === 'Cataract') return { class: 'dx-cataract', icon: 'fa-eye' };
        if (n === 'Diabetic Retinopathy') return { class: 'dx-diabetic', icon: 'fa-exclamation-triangle' };
        if (n === 'Glaucoma') return { class: 'dx-glaucoma', icon: 'fa-eye-dropper' };
        return { class: 'dx-default', icon: 'fa-question-circle' };
    }

    const MODEL_BENCHMARK_ACCURACY = window.MODEL_BENCHMARK_ACCURACY || {
        cnn: 51.53,
        vgg16: 91.01,
        resnet50: 95.54,
    };

    const ENSEMBLE_WEIGHTS = window.ENSEMBLE_WEIGHTS || { cnn: 0.30, vgg16: 0.35, resnet50: 0.35 };

    function normalizePercent(value) {
        const n = parseFloat(value);
        if (Number.isNaN(n)) return 0;
        return n > 0 && n <= 1 ? n * 100 : Math.min(100, Math.max(0, n));
    }

    function clampPercent(value) {
        return normalizePercent(value);
    }

    /** Shared bar colours: list, modal, and report use the same scale. */
    function getMetricBarColor(pct) {
        const v = clampPercent(pct);
        if (v >= 85) return '#2e7d32';
        if (v >= 75) return '#f9a825';
        if (v >= 55) return '#ef6c00';
        return '#c62828';
    }

    function metricBarStyle(pct) {
        const v = clampPercent(pct);
        return `width:${v}%;background:${getMetricBarColor(v)}`;
    }

    function resolveScreeningMetrics(row) {
        const certainty = clampPercent(row?.final_confidence ?? row?.confidence ?? 0);
        const agreement = computeAgreementMetrics(row || {});
        return {
            certainty,
            agreement: clampPercent(agreement.composite),
            aligned: agreement.models
                ? agreement.models.filter((m) => m.label === normalizeDiagnosis(row?.final_result)).length
                : 0,
        };
    }

    function computeAgreementMetrics(result) {
        const final = normalizeDiagnosis(result.final_result || 'Normal');
        const models = [
            {
                key: 'cnn',
                label: normalizeDiagnosis(result.cnn_result),
                confidence: normalizePercent(result.cnn_confidence),
                accuracy: result.cnn_accuracy != null
                    ? normalizePercent(result.cnn_accuracy)
                    : MODEL_BENCHMARK_ACCURACY.cnn,
            },
            {
                key: 'vgg16',
                label: normalizeDiagnosis(result.vgg_result),
                confidence: normalizePercent(result.vgg_confidence),
                accuracy: result.vgg_accuracy != null
                    ? normalizePercent(result.vgg_accuracy)
                    : MODEL_BENCHMARK_ACCURACY.vgg16,
            },
            {
                key: 'resnet50',
                label: normalizeDiagnosis(result.resnet_result),
                confidence: normalizePercent(result.resnet_confidence),
                accuracy: result.resnet_accuracy != null
                    ? normalizePercent(result.resnet_accuracy)
                    : MODEL_BENCHMARK_ACCURACY.resnet50,
            },
        ];

        const labels = models.map((m) => m.label);
        const counts = {};
        labels.forEach((l) => { counts[l] = (counts[l] || 0) + 1; });
        const majority = Object.keys(counts).reduce((best, key) =>
            (counts[key] > (counts[best] || 0) ? key : best), labels[0]);
        const labelConcordance = (labels.filter((l) => l === majority).length / 3) * 100;

        let weightedConfidence = 0;
        let weightSum = 0;
        models.forEach((m) => {
            const w = ENSEMBLE_WEIGHTS[m.key] || 0;
            weightedConfidence += w * (m.accuracy / 100) * (m.confidence / 100);
            weightSum += w;
        });
        weightedConfidence = weightSum > 0 ? (weightedConfidence / weightSum) * 100 : 0;

        const aligned = models.filter((m) => m.label === final);
        let agreementAccuracy = 0;
        let agreementConfidence = weightedConfidence * 0.5;
        if (aligned.length) {
            agreementAccuracy = aligned.reduce((s, m) => s + m.accuracy, 0) / aligned.length;
            const accSum = aligned.reduce((s, m) => s + m.accuracy, 0);
            agreementConfidence = accSum > 0
                ? aligned.reduce((s, m) => s + m.accuracy * m.confidence, 0) / accSum
                : 0;
        }

        const compositeFromParts = (0.35 * labelConcordance)
            + (0.35 * agreementAccuracy)
            + (0.30 * agreementConfidence);
        const hasCaseConfidence = models.every((m) => m.confidence > 0);
        const composite = hasCaseConfidence
            ? compositeFromParts
            : (result.model_agreement_score != null
                ? normalizePercent(result.model_agreement_score)
                : compositeFromParts);

        return {
            label: labelConcordance,
            accuracy: agreementAccuracy,
            confidence: agreementConfidence,
            composite,
            models,
        };
    }

    const DX_PLAIN = {
        Normal: 'normal',
        Cataract: 'cataract',
        Glaucoma: 'glaucoma',
        'Diabetic Retinopathy': 'diabetic retinopathy',
    };

    const SORT_ORDER = ['Cataract', 'Diabetic Retinopathy', 'Glaucoma', 'Normal'];

    function sortDxLabels(labels) {
        return labels.slice().sort((a, b) => SORT_ORDER.indexOf(a) - SORT_ORDER.indexOf(b));
    }

    function patternKey(cnn, vgg, resnet) {
        return sortDxLabels([cnn, vgg, resnet]).join('|');
    }

    function countLabels(labels) {
        const counts = {};
        labels.forEach((l) => { counts[l] = (counts[l] || 0) + 1; });
        return counts;
    }

    function findOutlierModel(cnn, vgg, resnet, minority) {
        if (cnn === minority && vgg !== minority && resnet !== minority) return 'CNN';
        if (vgg === minority && cnn !== minority && resnet !== minority) return 'VGG16';
        if (resnet === minority && cnn !== minority && vgg !== minority) return 'ResNet';
        return 'One model';
    }

    const UNANIMOUS_NOTES = {
        Normal: 'Fundus screening shows no significant abnormality. No pattern of cataract, glaucoma or diabetic retinopathy was identified. Continue routine eye checks. Review sooner if the patient reports vision changes or new symptoms.',
        Cataract: 'All analyses are consistent with cataract. Lens opacity may be present and can affect vision. Arrange visual acuity testing and slit-lamp examination. Discuss treatment if daily activities are affected.',
        Glaucoma: 'All analyses suggest glaucoma-related findings. Optic nerve changes may be present. Arrange IOP measurement, optic disc review and visual field testing promptly.',
        'Diabetic Retinopathy': 'All analyses suggest diabetic retinopathy. Diabetes-related retinal changes may be present. Arrange DR grading, HbA1c review and blood pressure assessment.',
    };

    const TWO_ONE_NOTES = {
        'Normal|Normal|Cataract': 'Most readings appear normal. One analysis suggests possible cataract. Early lens change can be difficult to see on a photograph alone. Slit-lamp review is advised.',
        'Normal|Normal|Glaucoma': 'Most readings appear normal. One analysis raises glaucoma concern. Optic nerve disease is not always visible on a single fundus image. IOP and disc review are advised.',
        'Normal|Normal|Diabetic Retinopathy': 'Most readings appear normal. One analysis suggests diabetic retinopathy. Mild retinal change can be overlooked on screening images. DR grading is advised if clinically indicated.',
        'Cataract|Cataract|Normal': 'Most analyses suggest cataract. One reading appears normal. Lens opacity may be mild, or image quality may have influenced one result. Clinical correlation is recommended.',
        'Cataract|Cataract|Glaucoma': 'Most analyses suggest cataract. One raises glaucoma concern. Both conditions can occur together. Assess lens clarity, IOP and the optic disc.',
        'Cataract|Cataract|Diabetic Retinopathy': 'Most analyses suggest cataract. One suggests diabetic retinopathy. Dense lens change can obscure retinal detail. Consider dilated fundus examination.',
        'Glaucoma|Glaucoma|Normal': 'Most analyses suggest glaucoma. One reading appears normal. Early optic nerve change may be subtle. Confirm with IOP measurement and disc assessment.',
        'Cataract|Glaucoma|Glaucoma': 'Most analyses suggest glaucoma. One suggests cataract. Evaluate IOP, the optic disc and lens status together.',
        'Diabetic Retinopathy|Glaucoma|Glaucoma': 'Most analyses suggest glaucoma. One suggests diabetic retinopathy. Both findings are sight-threatening. Prioritise full ophthalmology review.',
        'Diabetic Retinopathy|Diabetic Retinopathy|Normal': 'Most analyses suggest diabetic retinopathy. One reading appears normal. Mild disease may still warrant grading and diabetes control review.',
        'Cataract|Diabetic Retinopathy|Diabetic Retinopathy': 'Most analyses suggest diabetic retinopathy. One suggests cataract. Cataract can limit retinal views. Consider imaging after dilation if the retina cannot be assessed.',
        'Diabetic Retinopathy|Diabetic Retinopathy|Glaucoma': 'Most analyses suggest diabetic retinopathy. One raises glaucoma concern. Screen for both retinal and optic nerve disease.',
    };

    const THREE_WAY_NOTES = {
        'Cataract|Diabetic Retinopathy|Glaucoma': 'Analyses indicate three different conditions (cataract, diabetic retinopathy and glaucoma). This image is inconclusive. Full clinical examination is required before any treatment decision.',
        'Cataract|Glaucoma|Normal': 'Readings are split between normal appearance, cataract and glaucoma. Bedside examination is needed to determine which finding applies.',
        'Cataract|Diabetic Retinopathy|Normal': 'Readings are split between normal appearance, cataract and diabetic retinopathy. Correlate with symptoms, diabetes history and visual acuity.',
        'Diabetic Retinopathy|Glaucoma|Normal': 'Readings are split between normal appearance, glaucoma and diabetic retinopathy. Check IOP, the optic disc, macula and diabetes control.',
    };

    const FINAL_ACTIONS = {
        Normal: 'No urgent action is needed if the patient is well. Continue routine follow-up.',
        Cataract: 'Book ophthalmology review for slit-lamp examination and visual acuity.',
        Glaucoma: 'Arrange urgent IOP check and optic nerve assessment.',
        'Diabetic Retinopathy': 'Arrange urgent DR grading and diabetes management review.',
    };

    function buildClinicalNote(result) {
        const cnn = normalizeDiagnosis(result.cnn_result);
        const vgg = normalizeDiagnosis(result.vgg_result);
        const resnet = normalizeDiagnosis(result.resnet_result);
        const final = normalizeDiagnosis(result.final_result);
        const labels = [cnn, vgg, resnet];
        const unique = [...new Set(labels)];
        const key = patternKey(cnn, vgg, resnet);
        const action = FINAL_ACTIONS[final] || 'Clinical review is recommended before any treatment decision.';

        if (unique.length === 1) {
            return UNANIMOUS_NOTES[unique[0]] || `${UNANIMOUS_NOTES.Normal} ${action}`;
        }

        if (unique.length === 2) {
            const counts = countLabels(labels);
            const majority = Object.keys(counts).find((k) => counts[k] === 2);
            const minority = Object.keys(counts).find((k) => counts[k] === 1);
            const outlier = findOutlierModel(cnn, vgg, resnet, minority);
            const body = TWO_ONE_NOTES[key]
                || `Two readings suggest ${DX_PLAIN[majority]}. ${outlier} indicates ${DX_PLAIN[minority]}.`;
            const outlierHint = body.includes(outlier) ? '' : ` ${outlier} recorded the alternate finding.`;
            return `${body}${outlierHint} Overall diagnosis: ${final}. ${action}`;
        }

        const body = THREE_WAY_NOTES[key]
            || `Readings differ (${cnn}, ${vgg}, ${resnet}). Clinical correlation is essential.`;
        return `${body} Overall diagnosis: ${final}. ${action}`;
    }

    function getSuggestion(result, context) {
        const ctx = context || {};
        const payload = (typeof result === 'object' && result !== null && result.cnn_result != null)
            ? result
            : (ctx.result || {
                final_result: typeof result === 'string' ? result : result?.final_result,
                cnn_result: ctx.cnn_result,
                vgg_result: ctx.vgg_result,
                resnet_result: ctx.resnet_result,
            });

        if (payload.cnn_result != null && payload.vgg_result != null && payload.resnet_result != null) {
            return buildClinicalNote(payload);
        }

        const n = normalizeDiagnosis(payload.final_result || (typeof result === 'string' ? result : ''));
        return UNANIMOUS_NOTES[n] || UNANIMOUS_NOTES.Normal;
    }

    window.CLINICAL_DIAGNOSES = CLINICAL_DIAGNOSES;
    window.normalizeDiagnosis = normalizeDiagnosis;
    window.getClinicalDxClass = getClinicalDxClass;
    window.getDiagnosisTheme = getDiagnosisTheme;
    window.MODEL_BENCHMARK_ACCURACY = MODEL_BENCHMARK_ACCURACY;
    window.computeAgreementMetrics = computeAgreementMetrics;
    window.clampPercent = clampPercent;
    window.getMetricBarColor = getMetricBarColor;
    window.metricBarStyle = metricBarStyle;
    window.resolveScreeningMetrics = resolveScreeningMetrics;
    window.buildClinicalNote = buildClinicalNote;
    window.getDiagnosisSuggestion = getSuggestion;
})();

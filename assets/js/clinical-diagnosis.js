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

    function getSuggestion(result) {
        const n = normalizeDiagnosis(result);

        if (n === 'Normal') {
            return 'No abnormal findings detected. Maintain routine eye screening annually and healthy lifestyle practices.';
        }
        if (n === 'Cataract') {
            return 'Cataract detected. Lens opacity observed which may affect vision clarity. Recommend ophthalmology referral for further evaluation.';
        }
        if (n === 'Diabetic Retinopathy') {
            return 'Diabetic retinopathy detected. Retinal vascular changes observed. Recommend glycemic control and specialist referral.';
        }
        if (n === 'Glaucoma') {
            return 'Glaucoma detected. Risk of optic nerve damage identified. Urgent IOP assessment and ophthalmology referral required.';
        }

        return 'Clinical evaluation recommended.';
    }

    window.CLINICAL_DIAGNOSES = CLINICAL_DIAGNOSES;
    window.normalizeDiagnosis = normalizeDiagnosis;
    window.getClinicalDxClass = getClinicalDxClass;
    window.getDiagnosisTheme = getDiagnosisTheme;
    window.getDiagnosisSuggestion = getSuggestion;
})();

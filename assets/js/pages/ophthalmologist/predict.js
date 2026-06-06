// ========================================
// PREDICT PAGE - JAVASCRIPT
// ========================================

// ========================================
// STATE MANAGEMENT
// ========================================
let currentPatient = null;
let currentIC = null;
let extractedAge = null;
let extractedGender = null;
let selectedFile = null;

// ========================================
// DOM ELEMENTS - declared but assigned after DOM ready
// ========================================
let icInput = null;
let patientCard = null;
let predictionCard = null;
let uploadZone = null;
let imageInput = null;
let predictBtn = null;
let historyBody = null;
let historyActions = null;
let historySubtitle = null;
let historyRecordCount = null;
let predictSplit = null;
let pendingPredictView = null;
const HISTORY_COLSPAN = 6;
const CLINICAL_DOCTOR_ID = typeof window.CLINICAL_DOCTOR_ID === 'number'
    ? window.CLINICAL_DOCTOR_ID
    : parseInt(window.CLINICAL_DOCTOR_ID || '0', 10) || 0;

// ========================================
// INITIALIZATION
// ========================================
document.addEventListener('DOMContentLoaded', function () {
    initializeEventListeners();
    warmAiEngine();

    window.addEventListener('pageshow', function (e) {
        if (e.persisted && currentPatient?.id) {
            refreshPredictionHistory();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && currentPatient?.id) {
            refreshPredictionHistory();
        }
    });
});

function warmAiEngine() {
    clinicalFetch('ophthalmologist/aiStatus')
        .then(res => res.json())
        .then(data => updateAiStatusPill(data.online, data.message))
        .catch(() => updateAiStatusPill(false, 'AI unavailable'));
}

function updateAiStatusPill(online, message) {
    const pill = document.getElementById('predictAiStatus');
    if (!pill) return;
    pill.classList.toggle('online', !!online);
    pill.classList.toggle('offline', !online);
    const text = pill.querySelector('.status-text');
    if (text) text.textContent = message || (online ? 'AI Engine Online' : 'AI Engine Offline');
}

function initializeEventListeners() {

    // =========================
    // DOM ASSIGNMENT
    // =========================
    icInput        = document.getElementById('icInput');
    patientCard    = document.getElementById('patientCard');
    predictionCard = document.getElementById('predictionCard');
    uploadZone     = document.getElementById('uploadZone');
    imageInput     = document.getElementById('imageInput');
    predictBtn     = document.getElementById('predictBtn');
    historyBody    = document.getElementById('historyBody');
    historyActions = document.getElementById('historyActions');
    historySubtitle = document.getElementById('historySubtitle');
    historyRecordCount = document.getElementById('historyRecordCount');
    predictSplit = document.getElementById('predictSplit');

    // =========================
    // GUARD CHECK
    // =========================
    if (!icInput) {
        console.error("❌ icInput missing - DOM not ready or wrong ID");
        return;
    }

    // =========================
    // IC INPUT EVENTS
    // =========================
    icInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchPatient();
        }
    });

    icInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 12);
    });

    // =========================
    // UPLOAD ZONE
    // =========================
    if (uploadZone && imageInput) {

        uploadZone.addEventListener('click', function (e) {
            if (e.target.tagName === 'BUTTON' || e.target.closest('button')) return;
            imageInput.click();
        });

        uploadZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            this.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', function () {
            this.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', function (e) {
            e.preventDefault();
            this.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) handleFileSelect(files[0]);
        });

        imageInput.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                handleFileSelect(this.files[0]);
            }
        });
    }

    // =========================
    // REGISTER FORM
    // =========================
    const form = document.getElementById('registerForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            registerPatient();
        });
    }

    loadPatientFromUrl();
    updateWorkflowSteps('lookup');
}

/**
 * Auto-load patient from ?ic=...&view=history (linked from Patient registry).
 */
function loadPatientFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const ic = (params.get('ic') || '').replace(/\D/g, '').slice(0, 12);

    if (ic.length !== 12 || !icInput) {
        return;
    }

    const view = params.get('view') || (params.get('focus') === 'upload' ? 'upload' : 'history');
    pendingPredictView = view;

    icInput.value = ic;
    currentIC = ic;

    searchPatient();
}

function scrollToPredictView(view) {
    if (view === 'upload') {
        predictionCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    const historyEl = document.getElementById('predictHistorySection');
    if (historyEl) {
        historyEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function finishPendingPredictView() {
    if (!pendingPredictView) return;
    const view = pendingPredictView;
    pendingPredictView = null;
    setTimeout(() => scrollToPredictView(view), 350);
}

// ========================================
// PATIENT SEARCH
// ========================================
function clearPredictWorkspace() {
    currentPatient = null;
    currentIC = null;
    if (icInput) icInput.value = '';
    if (patientCard) patientCard.style.display = 'none';
    if (predictionCard) predictionCard.style.display = 'none';
    if (predictSplit) predictSplit.style.display = 'none';
    const btnRemove = document.getElementById('btnRemovePatient');
    if (btnRemove) btnRemove.style.display = 'none';
    const content = document.getElementById('patientContent');
    if (content) content.innerHTML = '';
    resetUploadZone();
    if (historyActions) historyActions.style.display = 'none';
    if (historySubtitle) {
        historySubtitle.textContent = 'Search a patient to load screening records from the database';
    }
    if (historyRecordCount) historyRecordCount.textContent = '0 records';
    showHistoryEmpty('no_patient');
    updateWorkflowSteps('lookup');
}

function updateWorkflowSteps(activeStep) {
    const steps = document.querySelectorAll('#predictWorkflow li');
    const order = ['lookup', 'profile', 'screen', 'history'];
    const activeIdx = order.indexOf(activeStep);

    steps.forEach((li) => {
        const step = li.getAttribute('data-step');
        const idx = order.indexOf(step);
        li.classList.remove('is-active', 'is-done');
        if (idx < activeIdx) li.classList.add('is-done');
        if (step === activeStep) li.classList.add('is-active');
    });
}

window.updateWorkflowSteps = updateWorkflowSteps;

function refreshPredictionHistory() {
    if (currentPatient?.id) {
        loadPredictions(currentPatient.id);
    } else if (currentIC && icInput?.value?.length === 12) {
        searchPatient();
    }
}

function deleteCurrentPatient() {
    if (!currentPatient?.id) {
        showToast('No patient loaded', 'error');
        return;
    }

    const name = currentPatient.name || 'this patient';
    if (!confirm(
        `Remove patient "${name}" from the registry?\n\n` +
        'All AI screening results and images for this patient will be deleted permanently.'
    )) {
        return;
    }

    showLoading('Removing patient…', 'Deleting screening records');

    clinicalFetch('ophthalmologist/patientDelete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(currentPatient.id)}`
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const n = data.predictions_removed ?? 0;
            showToast(
                n > 0
                    ? `Patient removed (${n} screening record${n === 1 ? '' : 's'} deleted)`
                    : 'Patient removed from registry',
                'success'
            );
            clearPredictWorkspace();
            if (icInput) icInput.value = '';
        } else {
            showToast(data.message || 'Could not remove patient', 'error');
        }
    })
    .catch(() => {
        hideLoading();
        showToast('Remove patient request failed', 'error');
    });
}

window.clearPredictWorkspace = clearPredictWorkspace;
window.refreshPredictionHistory = refreshPredictionHistory;
window.deleteCurrentPatient = deleteCurrentPatient;

function searchPatient() {
    const ic = icInput.value.trim();

    if (ic.length !== 12) {
        showToast('Please enter a valid 12-digit IC number', 'error');
        icInput.focus();
        return;
    }

    currentIC = ic;
    currentPatient = null;
    showHistoryLoading();
    showLoading('Searching patient...', 'Please wait');

    clinicalFetch('ophthalmologist/checkIC', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'ic=' + encodeURIComponent(ic)
    })
    .then(async (res) => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error("RAW RESPONSE:", text);
            throw new Error("Invalid JSON from server");
        }
    })
    .then(data => {
        hideLoading();

        if (data.status === 'exist' && data.patient) {
            currentPatient = data.patient;
            displayExistingPatient(data.patient);
            showPredictionCard();
            updateWorkflowSteps(pendingPredictView === 'history' ? 'history' : 'screen');
            loadPredictions(data.patient.id);
        } else if (data.status === 'new') {
            pendingPredictView = null;
            clearPredictWorkspace();
            currentIC = ic;
            if (icInput) icInput.value = ic;
            extractedAge    = data.age;
            extractedGender = data.gender;
            showRegisterModal(ic, data.age, data.gender);
        } else {
            clearPredictWorkspace();
            currentIC = ic;
            if (icInput) icInput.value = ic;
            showToast(data.message || 'Patient not found in database', 'info');
            showHistoryEmpty('not_found');
        }
    })
    .catch(err => {
        hideLoading();
        pendingPredictView = null;
        showToast('Error searching patient. Please try again.', 'error');
        console.error(err);
        showHistoryEmpty('error');
    });
}

// ========================================
// DISPLAY PATIENT
// ========================================
function displayExistingPatient(patient) {

    const content = document.getElementById('patientContent');

    if (!content) {
        console.error("❌ patientContent element missing in DOM");
        return;
    }

    content.innerHTML = `
        <div class="clinical-field-grid">
            <div class="clinical-field">
                <span class="clinical-label">Full name</span>
                <strong>${escapeHtml(patient.name)}</strong>
            </div>
            <div class="clinical-field">
                <span class="clinical-label">IC number</span>
                <code>${escapeHtml(patient.ic)}</code>
            </div>
            <div class="clinical-field">
                <span class="clinical-label">Age</span>
                <strong>${escapeHtml(String(patient.age))} years</strong>
            </div>
            <div class="clinical-field">
                <span class="clinical-label">Gender</span>
                <strong>${patient.gender === 'Male' ? 'Male' : 'Female'}</strong>
            </div>
            <div class="clinical-field clinical-field-grid--full">
                <span class="clinical-label">Registered</span>
                <strong>${formatDate(patient.created_at)}</strong>
            </div>
        </div>
    `;

    if (predictSplit) predictSplit.style.display = 'grid';
    patientCard.style.display = 'block';
    const btnRemove = document.getElementById('btnRemovePatient');
    if (btnRemove) btnRemove.style.display = 'inline-flex';
}

function showPredictionCard() {
    if (predictSplit) predictSplit.style.display = 'grid';
    predictionCard.style.display = 'block';
    resetUploadZone();
}

// ========================================
// PATIENT REGISTRATION
// ========================================
function showRegisterModal(ic, age, gender) {
    document.getElementById('regIC').value  = ic;
    document.getElementById('regAge').value = age + ' years old';

    // ==========================================================
    // BUG FIX: PHP extractFromIC() returns 'Male'/'Female' string
    // Old code checked (=== 'M') which never matched, so gender
    // always displayed wrong. Now we normalise properly.
    // ==========================================================
    const genderNorm = (gender || '').toLowerCase();
    document.getElementById('regGender').value =
        genderNorm === 'male' || genderNorm === 'm' ? 'Male' : 'Female';

    document.getElementById('regName').value = '';

    $('#registerModal').modal('show');
    setTimeout(() => document.getElementById('regName')?.focus(), 300);
}

function closeRegisterModal() {
    $('#registerModal').modal('hide');
}

function registerPatient() {
    const name = document.getElementById('regName').value.trim();

    if (!name) {
        showToast('Please enter patient name', 'error');
        return;
    }

    showLoading('Registering patient...', 'Please wait');

    const formData = new FormData();
    formData.append('ic',     currentIC);
    formData.append('name',   name);
    formData.append('age',    extractedAge);
    formData.append('gender', extractedGender);

    clinicalFetch('ophthalmologist/register', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();
        closeRegisterModal();

        if (data.success) {
            currentPatient = data.patient;
            displayExistingPatient(data.patient);
            showPredictionCard();
            showToast('Patient registered successfully!', 'success');
            loadPredictions(data.patient.id);
            updateWorkflowSteps('screen');
        } else {
            showToast(data.message || 'Registration failed', 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('Error registering patient', 'error');
        console.error(err);
    });
}

// ========================================
// IMAGE UPLOAD
// ========================================
function handleFileSelect(file) {
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png'];

    // ================= VALIDATION =================
    if (!file) {
        showToast('No file selected', 'error');
        return;
    }

    if (!validTypes.includes(file.type)) {
        showToast('Only JPG / PNG images allowed', 'error');
        return;
    }

    if (file.size > 10 * 1024 * 1024) {
        showToast('Max file size is 10MB', 'error');
        return;
    }

    // ================= STORE GLOBAL STATE =================
    selectedFile = file;

    const reader = new FileReader();

    reader.onload = function (e) {
        const imageData = e.target.result;

        // ================= UPLOAD PREVIEW =================
        const previewImg = document.getElementById('previewImage');
        const placeholder = document.getElementById('uploadPlaceholder');
        const previewBox = document.getElementById('uploadPreview');

        if (previewImg) previewImg.src = imageData;
        if (placeholder) placeholder.style.display = 'none';
        if (previewBox) previewBox.style.display = 'block';

        // ================= GLOBAL STORE (IMPORTANT) =================
        window.lastUploadedImage = imageData;

        // ================= ENABLE BUTTON =================
        if (predictBtn) predictBtn.disabled = false;
    };

    reader.readAsDataURL(file);
}

function removeImage(e) {
    if (e) e.stopPropagation();
    resetUploadZone();
}

function resetUploadZone() {
    selectedFile     = null;
    imageInput.value = '';

    const preview     = document.getElementById('uploadPreview');
    const placeholder = document.getElementById('uploadPlaceholder');
    const previewImg  = document.getElementById('previewImage');

    if (previewImg)   previewImg.src              = '';
    if (placeholder)  placeholder.style.display   = 'block';
    if (preview)      preview.style.display        = 'none';

    predictBtn.disabled = true;
}

// ========================================
// PREDICTION
// ========================================
function runPrediction() {

    if (predictBtn.disabled) return;

    if (!currentPatient?.id) {
        showToast('Search and load a patient first (step 1)', 'error');
        return;
    }

    if (!selectedFile) {
        showToast('Upload a fundus image first', 'error');
        return;
    }

    predictBtn.disabled = true;

    showLoading('Analyzing image...', 'Starting AI engine if needed — first run may take up to 30 seconds');

    const formData = new FormData();
    formData.append('patient_id', String(currentPatient.id));
    formData.append('image', selectedFile);

    clinicalFetch('ophthalmologist/predict', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        predictBtn.disabled = false;
        hideLoading();

        if (data.success) {
            if (data.patient_id) {
                currentPatient.id = data.patient_id;
            }
            showResultModal(data.result);
            resetUploadZone();
            loadPredictions(currentPatient.id);
            updateWorkflowSteps('history');
            scrollToPredictView('history');
            showToast('Screening saved — listed in prediction history below', 'success');
        } else {
            showToast(data.message || 'Prediction failed', 'error');
        }
    })
    .catch(err => {
        predictBtn.disabled = false;
        hideLoading();
        showToast('Error running prediction', 'error');
        console.error(err);
    });
}

// ========================================
// RESULT MODAL (single screen, no scroll)
// ========================================
function buildModelChip(cssClass, title, label, conf) {
    const c = Math.min(100, Math.max(0, parseFloat(conf) || 0));
    return `
        <div class="ai-model-chip ${cssClass}">
            <div class="model-chip-head">
                <span class="model-name">${title}</span>
                <span class="model-conf">${c.toFixed(1)}%</span>
            </div>
            <div class="model-dx" title="${escapeHtml(label)}">${escapeHtml(label || '-')}</div>
            <div class="model-bar" aria-hidden="true"><span style="width:${c}%"></span></div>
        </div>
    `;
}

function buildAgreementBox(agreement) {
    const pct = Math.min(100, Math.max(0, parseFloat(agreement) || 0));
    return `
        <div class="ai-agreement-box">
            <span class="ai-agreement-label">Agreement</span>
            <span class="ai-agreement-val">${pct.toFixed(1)}%</span>
            <span class="ai-agreement-sub">Ensemble consistency</span>
            <div class="ai-agreement-bar" aria-hidden="true"><span style="width:${pct}%"></span></div>
        </div>
    `;
}

function showResultModal(result) {
    const modalBody = document.getElementById('modalBody');
    if (!modalBody) return;

    if (!result) {
        showToast('No prediction data available', 'error');
        return;
    }

    const label = normalizeDiagnosis(result.final_result || 'Normal');
    const risk = (result.risk_level || 'Unknown').toString();
    const riskKey = risk.toLowerCase() === 'high' ? 'risk-high' : (risk.toLowerCase() === 'medium' ? 'risk-medium' : 'risk-low');
    const theme = getDiagnosisTheme(label);
    const confidence = parseFloat(result.final_confidence ?? result.confidence ?? 0);
    const agreement = parseFloat(result.model_agreement_score ?? 0);
    const imgSrc = window.lastUploadedImage || result.image_url || '';
    const doctorName = result.doctor_name ? escapeHtml(result.doctor_name) : '';
    const screenedAt = result.created_at ? escapeHtml(splitDateTime(result.created_at).date + ' ' + splitDateTime(result.created_at).time) : '';
    const clinicianMeta = doctorName
        ? `<span class="ai-clinician-badge"><i class="fas fa-user-md mr-1"></i>${doctorName}${result.is_mine ? ' · You' : ''}</span>`
        : '';

    modalBody.innerHTML = `
        <div class="ai-result-compact">
            <div class="ai-result-top">
                <div class="ai-result-thumb">
                    ${imgSrc ? `<img src="${imgSrc}" alt="Fundus scan">` : '<i class="fas fa-image fa-2x text-white-50"></i>'}
                </div>
                <div class="ai-result-hero ${theme.class}">
                    <h3><i class="fas ${theme.icon} mr-2"></i>${escapeHtml(label)}</h3>
                    <div class="ai-result-meta">
                        <span class="ai-confidence-badge">${confidence.toFixed(1)}% confidence</span>
                        <span class="ai-risk-badge ${riskKey}">${escapeHtml(risk)} risk</span>
                        ${clinicianMeta}
                        ${screenedAt ? `<span class="ai-screened-at-badge">${screenedAt}</span>` : ''}
                    </div>
                </div>
            </div>
            <div class="ai-ensemble-grid">
                ${buildModelChip('cnn', 'CNN', normalizeDiagnosis(result.cnn_result), result.cnn_confidence)}
                ${buildModelChip('vgg', 'VGG16', normalizeDiagnosis(result.vgg_result), result.vgg_confidence)}
                ${buildModelChip('resnet', 'ResNet', normalizeDiagnosis(result.resnet_result), result.resnet_confidence)}
                ${buildAgreementBox(agreement)}
            </div>
            <div class="ai-clinical-note">
                <span class="ai-clinical-note-label">Clinical note</span>
                <p class="ai-interpretation">${escapeHtml(getDiagnosisSuggestion(label))}</p>
            </div>
        </div>
    `;

    $('#resultModal').modal('show');
}

function closeModal() {
    $('#resultModal').modal('hide');
}

// ========================================
// PREDICTION HISTORY
// ========================================
function showHistoryLoading() {
    if (!historyBody) return;
    historyBody.innerHTML = `
        <tr>
            <td colspan="${HISTORY_COLSPAN}" class="clinical-loading-cell">
                <span class="spinner-border spinner-border-sm text-primary mr-2" role="status"></span>
                Loading records from database…
            </td>
        </tr>
    `;
}

function showHistoryEmpty(reason) {
    if (!historyBody) return;
    const messages = {
        no_patient: ['No patient selected', 'Complete step 1 to view AI screening history'],
        not_found: ['Patient not in registry', 'Register this IC or verify data in phpMyAdmin (port 3307)'],
        empty: ['No screenings yet', 'Upload a fundus image in step 3 and run AI diagnosis'],
        error: ['Could not load history', 'Check your connection and click Refresh'],
    };
    const icons = {
        no_patient: 'fa-inbox',
        not_found: 'fa-user-slash',
        empty: 'fa-microscope',
        error: 'fa-exclamation-circle',
    };
    const [title, sub] = messages[reason] || messages.no_patient;
    const icon = icons[reason] || 'fa-inbox';
    historyBody.innerHTML = `
        <tr>
            <td colspan="${HISTORY_COLSPAN}">
                <div class="clinical-empty-state">
                    <i class="fas ${icon}"></i>
                    <strong>${title}</strong>
                    <span>${sub}</span>
                </div>
            </td>
        </tr>
    `;
    if (reason === 'no_patient') {
        updateWorkflowSteps('lookup');
    }
}

function loadPredictions(patientId) {
    if (!patientId) return;

    showHistoryLoading();

    if (historyActions) historyActions.style.display = 'flex';

    if (historySubtitle && currentPatient) {
        historySubtitle.textContent =
            `All screenings for ${currentPatient.name} (IC ${currentPatient.ic}) — every clinician in the registry`;
    }

    clinicalFetch(`ophthalmologist/getPredictions&patient_id=${encodeURIComponent(patientId)}`)
        .then(async (res) => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error("Invalid JSON response:", text);
                throw new Error("Server did not return JSON");
            }
        })
        .then(data => {
            if (data && (data.status === 'unauthenticated' || data.status === 'unauthorized')) {
                showHistoryEmpty('error');
                showToast(data.message || 'Please log in again', 'error');
                return;
            }

            const items = Array.isArray(data)
                ? data
                : (Array.isArray(data?.items) ? data.items : []);

            if (data && data.success === false && items.length === 0) {
                showHistoryEmpty('error');
                showToast(data.message || 'Could not load history', 'error');
                return;
            }

            if (historyRecordCount) {
                historyRecordCount.textContent =
                    items.length + (items.length === 1 ? ' record' : ' records');
            }

            if (items.length === 0) {
                showHistoryEmpty('empty');
                updateWorkflowSteps('screen');
                finishPendingPredictView();
                return;
            }

            let html = '';

            items.forEach(row => {
                const dxLabel         = normalizeDiagnosis(row.final_result ?? '');
                const finalResult     = escapeHtml(dxLabel);
                const confidence      = parseFloat(row.confidence ?? 0);
                const finalConfidence = parseFloat(row.final_confidence ?? 0);
                const dxClass = getClinicalDxClass(dxLabel);
                const risk = (row.risk_level ?? 'unknown').toLowerCase();
                const riskClass = risk === 'low' ? 'risk-low' : (risk === 'medium' ? 'risk-medium' : (risk === 'high' ? 'risk-high' : ''));
                const dt = splitDateTime(row.created_at);
                const doctorName = escapeHtml(row.doctor_name || 'Unassigned clinician');
                const isMine = row.is_mine === true
                    || (CLINICAL_DOCTOR_ID > 0 && parseInt(row.doctor_id, 10) === CLINICAL_DOCTOR_ID);
                const clinicianCell = isMine
                    ? `<span class="history-clinician-name">${doctorName}</span><span class="history-clinician-you">You</span>`
                    : `<span class="history-clinician-name">${doctorName}</span>`;

                html += `
                    <tr>
                        <td>
                            <span class="history-date-main">${dt.date}</span>
                            <span class="history-date-sub">${dt.time}</span>
                        </td>
                        <td class="history-clinician-cell">${clinicianCell}</td>
                        <td>
                            <span class="clinical-badge-dx ${dxClass}">${finalResult}</span>
                            <span class="clinical-badge-risk ${riskClass}">${escapeHtml(row.risk_level ?? 'Unknown')} risk</span>
                        </td>
                        <td class="clinical-conf-wrap">
                            <div class="clinical-conf-bar"><span style="width:${Math.min(100, confidence)}%"></span></div>
                            <span class="clinical-conf-pct">${confidence.toFixed(1)}%</span>
                            <span class="clinical-conf-final">Ensemble ${finalConfidence.toFixed(1)}%</span>
                        </td>
                        <td>
                            <div class="predict-model-grid">
                                <span><strong>CNN</strong> ${escapeHtml(normalizeDiagnosis(row.cnn_result ?? ''))}</span>
                                <span><strong>VGG</strong> ${escapeHtml(normalizeDiagnosis(row.vgg_result ?? ''))}</span>
                                <span><strong>ResNet</strong> ${escapeHtml(normalizeDiagnosis(row.resnet_result ?? ''))}</span>
                            </div>
                        </td>
                        <td class="clinical-action-cell">
                            <div class="clinical-action-group">
                                <button type="button" class="btn btn-clinical-predict"
                                        onclick="viewPrediction(${row.id})" title="View">
                                    <i class="fas fa-eye"></i><span>View</span>
                                </button>
                                <button type="button" class="btn btn-clinical-edit"
                                        onclick="rerunPrediction(${row.id})" title="Re-run">
                                    <i class="fas fa-redo"></i><span>Re-run</span>
                                </button>
                                <a href="${apiUrl('ophthalmologist/exportPDF')}&id=${row.id}&_ts=${Date.now()}"
                                   class="btn btn-clinical-pdf" title="PDF" target="_blank" rel="noopener">
                                    <i class="fas fa-file-pdf"></i><span>PDF</span>
                                </a>
                                <button type="button" class="btn btn-clinical-delete"
                                        onclick="deletePrediction(${row.id})" title="Delete">
                                    <i class="fas fa-trash-alt"></i><span>Delete</span>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            historyBody.innerHTML = html;
            updateWorkflowSteps('history');
            finishPendingPredictView();
        })
        .catch(err => {
            console.error('Error loading predictions:', err);
            showHistoryEmpty('error');
            finishPendingPredictView();
        });
}

function splitDateTime(dateString) {
    if (!dateString) return { date: '-', time: '' };
    const d = new Date(dateString);
    if (Number.isNaN(d.getTime())) return { date: dateString, time: '' };
    return {
        date: d.toLocaleDateString('en-MY', { day: '2-digit', month: 'short', year: 'numeric' }),
        time: d.toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' }),
    };
}

function viewPrediction(id) {
    showLoading('Loading...', 'Fetching prediction details');

    clinicalFetch(`ophthalmologist/getPredictionDetail&id=${encodeURIComponent(id)}`)
    .then(res => res.json())
    .then(data => {
        hideLoading();
        if (!data || data.deleted) {
            showToast('This record no longer exists in the database. Refreshing list.', 'info');
            if (currentPatient?.id) loadPredictions(currentPatient.id);
            return;
        }
        if (data.image_url) {
            window.lastUploadedImage = data.image_url;
        }
        showResultModal(data);
    })
    .catch(err => {
        hideLoading();
        showToast('Error loading prediction details', 'error');
        console.error(err);
    });
}

function rerunPrediction(id) {
    if (!confirm('Are you sure you want to rerun this prediction?')) return;

    showLoading('Rerunning prediction...', 'Please wait');

    clinicalFetch('ophthalmologist/rerunPrediction', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    })
    .then(res => res.json())
    .then(data => {
        hideLoading();

        if (data.success) {
            showResultModal(data.result);
            loadPredictions(currentPatient.id);
        } else {
            showToast(data.message || 'Rerun failed', 'error');
        }
    })
    .catch(err => {
        hideLoading();
        showToast('Error rerunning prediction', 'error');
        console.error(err);
    });
}

function deletePrediction(id) {
    if (!confirm('Are you sure you want to delete this prediction? This action cannot be undone.')) return;

    clinicalFetch('ophthalmologist/deletePrediction', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Prediction deleted successfully', 'success');
            loadPredictions(currentPatient.id);
        } else {
            showToast(data.message || 'Delete failed', 'error');
        }
    })
    .catch(err => {
        showToast('Error deleting prediction', 'error');
        console.error(err);
    });
}

// ========================================
// UTILITY FUNCTIONS
// ========================================
function showLoading(title, subtitle) {
    const overlay = document.getElementById('loadingOverlay');
    const text    = document.getElementById('loadingText');
    const subtext = document.getElementById('loadingSubtext');

    if (text)    text.textContent    = title    || 'Processing...';
    if (subtext) subtext.textContent = subtitle || 'Please wait';

    if (overlay) overlay.classList.add('show');
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.remove('show');
}

function showToast(message, type = 'info') {
    const iconMap = {
        success: 'fa-check-circle',
        error:   'fa-exclamation-circle',
        info:    'fa-info-circle'
    };

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i class="fas ${iconMap[type] || iconMap.info}"></i>
        <span>${message}</span>
    `;

    if (!document.getElementById('toastStyles')) {
        const style = document.createElement('style');
        style.id = 'toastStyles';
        style.textContent = `
            .toast {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 14px 20px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                gap: 10px;
                font-weight: 500;
                z-index: 9999;
                animation: slideIn 0.3s ease, fadeOut 0.3s ease 2.7s forwards;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            }
            .toast-success { background: #d4edda; color: #155724; }
            .toast-error   { background: #f8d7da; color: #721c24; }
            .toast-info    { background: #d1ecf1; color: #0c5460; }
            @keyframes slideIn {
                from { transform: translateX(120%); opacity: 0; }
                to   { transform: translateX(0);    opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; }
                to   { opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }

    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-MY', {
        day:   'numeric',
        month: 'short',
        year:  'numeric'
    });
}

function formatDateTime(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-MY', {
        day:    'numeric',
        month:  'short',
        year:   'numeric',
        hour:   '2-digit',
        minute: '2-digit'
    });
}

function getConfidenceClass(confidence) {
    if (confidence >= 80) return 'success';
    if (confidence >= 50) return 'warning';
    return 'danger';
}

function buildModelBlock(title, label, confidence, color) {
    const conf = parseFloat(confidence ?? 0).toFixed(2);
    return `
        <div class="mb-3">
            <div class="d-flex justify-content-between mb-1">
                <small><i class="fas fa-microchip mr-1"></i> ${title}</small>
                <small><strong>${escapeHtml(label ?? '-')}</strong> (${conf}%)</small>
            </div>
            <div class="progress" style="height:8px;">
                <div class="progress-bar bg-${color}"
                     style="width:${conf}%">
                </div>
            </div>
        </div>
    `;
}

function exportPDF(id) {
    window.open(`${apiUrl('ophthalmologist/exportPDF')}&id=${encodeURIComponent(id)}`, '_blank');
}

// ========================================
// GLOBAL EXPORT (for onclick HTML attributes)
// ========================================
window.searchPatient      = searchPatient;
window.runPrediction      = runPrediction;
window.removeImage        = removeImage;
window.closeModal         = closeModal;
window.closeRegisterModal = closeRegisterModal;
window.viewPrediction     = viewPrediction;
window.rerunPrediction    = rerunPrediction;
window.deletePrediction   = deletePrediction;
window.registerPatient    = registerPatient;
window.exportPDF          = exportPDF;
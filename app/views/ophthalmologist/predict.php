<?php
$page_css = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/pages/ophthalmologist/predict.css">';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidebar.php';

$page_icon = 'fa-microscope';
$page_subtitle = 'Patient lookup · retinal upload · AI ensemble diagnosis';
$page_header_actions = '<span id="predictAiStatus" class="ai-status-pill offline"><span class="dot"></span><span class="status-text">Checking AI…</span></span>';
require BASE_PATH . '/includes/clinical_layout_start.php';
?>

<div class="clinical-workspace predict-workspace">

    <!-- 1. Lookup -->
    <section class="clinical-panel">
        <div class="clinical-panel__head">
            <div class="clinical-panel__head-main">
                <h2><i class="fas fa-id-card"></i> Patient lookup</h2>
                <p>Enter 12-digit Malaysian IC to load registry and screening records</p>
            </div>
        </div>
        <div class="clinical-panel__body">
            <div class="predict-lookup-grid">
                <div>
                    <label class="clinical-label" for="icInput">Identification number (IC)</label>
                    <div class="input-group clinical-input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                        </div>
                        <input type="text"
                               id="icInput"
                               class="form-control clinical-input"
                               placeholder="IC number..."
                               maxlength="12"
                               autocomplete="off"
                               inputmode="numeric">
                    </div>
                </div>
                <div class="clinical-btn-stack">
                    <button type="button" class="btn btn-clinical-outline" onclick="clearPredictWorkspace()">
                        <i class="fas fa-eraser mr-1"></i> Clear workspace
                    </button>
                    <button type="button" class="btn btn-clinical-primary" onclick="searchPatient()">
                        <i class="fas fa-search mr-1"></i> Search patient
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- 2 & 3. Profile + AI -->
    <div class="clinical-grid-2" id="predictSplit" style="display:none;">

        <section class="clinical-panel clinical-panel--success" id="patientCard" style="display:none;">
            <div class="clinical-panel__head">
                <div class="clinical-panel__head-main">
                    <h2><i class="fas fa-user-circle"></i> Patient profile</h2>
                    <p>Verified registry record</p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="clinical-panel__badge" id="patientBadge">Active</span>
                    <button type="button" class="btn btn-clinical-delete btn-sm" id="btnRemovePatient" style="display:none;"
                            onclick="deleteCurrentPatient()" title="Remove patient and all screening records">
                        <i class="fas fa-user-times mr-1"></i> Remove patient
                    </button>
                </div>
            </div>
            <div class="clinical-panel__body" id="patientContent"></div>
        </section>

        <section class="clinical-panel clinical-panel--info" id="predictionCard" style="display:none;">
            <div class="clinical-panel__head">
                <div class="clinical-panel__head-main">
                    <h2><i class="fas fa-brain"></i> AI retinal screening</h2>
                    <p>Ensemble CNN · VGG16 · ResNet50</p>
                </div>
            </div>
            <div class="clinical-dx-strip" aria-label="Supported diagnoses">
                <span class="clinical-badge-dx dx-normal">Normal</span>
                <span class="clinical-badge-dx dx-cataract">Cataract</span>
                <span class="clinical-badge-dx dx-glaucoma">Glaucoma</span>
                <span class="clinical-badge-dx dx-diabetic">Diabetic Retinopathy</span>
            </div>
            <div class="clinical-panel__body">
                <div id="uploadZone" class="predict-upload-zone">
                    <div id="uploadPlaceholder" class="upload-inner">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h3>Upload fundus image</h3>
                        <p>JPG or PNG · maximum 10 MB</p>
                        <button type="button" class="btn btn-clinical-outline btn-sm"
                                onclick="document.getElementById('imageInput').click(); event.stopPropagation();">
                            <i class="fas fa-folder-open mr-1"></i> Browse file
                        </button>
                    </div>
                    <div id="uploadPreview" class="predict-upload-preview upload-inner" style="display:none;">
                        <img id="previewImage" alt="Retinal fundus preview">
                        <button type="button" class="btn btn-clinical-delete btn-sm mt-3" onclick="removeImage(event)">
                            <i class="fas fa-trash-alt mr-1"></i> Remove image
                        </button>
                    </div>
                    <input type="file" id="imageInput" accept="image/jpeg,image/png,image/jpg" hidden>
                </div>
                <div class="predict-run-wrap">
                    <button type="button" id="predictBtn" class="btn btn-clinical-primary btn-lg" onclick="runPrediction()" disabled>
                        <i class="fas fa-microchip mr-2"></i> Run AI diagnosis
                    </button>
                </div>
            </div>
        </section>
    </div>

    <!-- 4. History -->
    <section class="clinical-panel" id="predictHistorySection">
        <div class="clinical-panel__head">
            <div class="clinical-panel__head-main">
                <h2><i class="fas fa-history"></i> Prediction history</h2>
                <p id="historySubtitle">Search a patient to load all AI screenings for that patient (every clinician)</p>
            </div>
            <div class="clinical-panel__head-actions" id="historyActions" style="display:none;">
                <span class="clinical-panel__meta" id="historyRecordCount">0 records</span>
                <button type="button" class="btn btn-clinical-outline btn-sm" onclick="refreshPredictionHistory()" title="Reload from database">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
            </div>
        </div>
        <div class="clinical-panel__body clinical-panel__body--flush">
            <div class="table-responsive">
                <table id="predictHistoryTable" class="table mb-0 clinical-data-table predict-history-table table-hover table-striped">
                    <thead>
                        <tr>
                            <th class="col-date">Screened</th>
                            <th class="col-clinician">Clinician</th>
                            <th class="col-dx">Diagnosis</th>
                            <th class="col-conf">Confidence</th>
                            <th class="col-models">Model outputs</th>
                            <th class="col-actions text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr>
                            <td colspan="6">
                                <div class="clinical-empty-state" id="historyEmptyDefault">
                                    <i class="fas fa-inbox"></i>
                                    <strong>No patient selected</strong>
                                    <span>Complete step 1 to view AI screening history for this patient</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</div>

<!-- Result modal -->
<div class="modal fade" id="resultModal" tabindex="-1" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-ai-result">
        <div class="modal-content clinical-modal border-0">
            <div class="modal-header clinical-modal-header py-2">
                <div>
                    <h5 class="modal-title mb-0 text-white font-weight-bold">
                        <i class="fas fa-microscope mr-2"></i>AI diagnostic report
                    </h5>
                    <small class="text-white-50">Four-class ensemble analysis</small>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body modal-ai-body p-0">
                <div id="modalBody"></div>
            </div>
            <div class="modal-footer py-2 bg-light border-0 justify-content-center">
                <button type="button" class="btn btn-clinical-primary px-4" data-dismiss="modal">
                    <i class="fas fa-check mr-1"></i> Close report
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Register modal -->
<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content clinical-modal">
            <div class="modal-header clinical-modal-header">
                <h5 class="modal-title mb-0 text-white"><i class="fas fa-user-plus mr-2"></i>Register patient</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <form id="registerForm">
                    <div class="form-group mb-3">
                        <label class="clinical-label">Full name</label>
                        <input type="text" id="regName" class="form-control clinical-input" required placeholder="As per IC">
                    </div>
                    <div class="form-group mb-3">
                        <label class="clinical-label">IC number</label>
                        <input type="text" id="regIC" class="form-control clinical-input" readonly>
                    </div>
                    <div class="row">
                        <div class="col-6 form-group mb-3">
                            <label class="clinical-label">Age</label>
                            <input type="text" id="regAge" class="form-control clinical-input" readonly>
                        </div>
                        <div class="col-6 form-group mb-3">
                            <label class="clinical-label">Gender</label>
                            <input type="text" id="regGender" class="form-control clinical-input" readonly>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-clinical-primary btn-block">
                        <i class="fas fa-user-check mr-1"></i> Confirm registration
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-card">
        <div class="loading-icon"><i class="fas fa-brain fa-2x text-primary"></i></div>
        <div class="spinner-border text-primary loading-spinner" role="status"></div>
        <h5 id="loadingText" class="loading-title">Processing</h5>
        <p id="loadingSubtext" class="loading-subtext">Please wait…</p>
        <div class="loading-dots"><span></span><span></span><span></span></div>
    </div>
</div>

<?php
$page_js = '<script>window.CLINICAL_DOCTOR_ID = ' . (int)($current_doctor_id ?? 0) . ';</script>'
    . '<script src="' . BASE_URL . '/assets/js/pages/ophthalmologist/predict.js"></script>';
require BASE_PATH . '/includes/clinical_layout_end.php';
require_once BASE_PATH . '/includes/footer.php';
?>

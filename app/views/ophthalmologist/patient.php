<?php
$page_css = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/pages/ophthalmologist/patient.css">'
    . '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/clinical-pagination.css">';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidebar.php';

$page_icon = 'fa-user-injured';
$stats = $registryStats ?? [];
$statTotal = (int)($stats['total'] ?? $totalPatients ?? 0);
$page_subtitle = 'Registry · ' . $statTotal . ' patients on file';
$page_header_actions = '<a href="' . BASE_URL . '/ophthalmologist/predict" class="btn btn-clinical-light btn-sm"><i class="fas fa-microscope mr-1"></i> New screening</a>';
require BASE_PATH . '/includes/clinical_layout_start.php';
?>

<div class="clinical-workspace patient-workspace">

    <div class="patient-kpi-row" aria-label="Patient registry summary">
        <div class="patient-kpi patient-kpi-primary">
            <div class="val"><?= (int)($stats['total'] ?? 0) ?></div>
            <div class="lbl">Total patients</div>
            <div class="hint">On registry</div>
        </div>
        <div class="patient-kpi patient-kpi-male">
            <div class="val"><?= (int)($stats['male'] ?? 0) ?></div>
            <div class="lbl">Male</div>
            <div class="hint">Male patients</div>
        </div>
        <div class="patient-kpi patient-kpi-female">
            <div class="val"><?= (int)($stats['female'] ?? 0) ?></div>
            <div class="lbl">Female</div>
            <div class="hint">Female patients</div>
        </div>
        <div class="patient-kpi patient-kpi-senior">
            <div class="val"><?= (int)($stats['seniors'] ?? 0) ?></div>
            <div class="lbl">Age 55+</div>
            <div class="hint">Senior cohort</div>
        </div>
        <div class="patient-kpi">
            <div class="val"><?= (int)($stats['middle_age'] ?? 0) ?></div>
            <div class="lbl">Age 40–54</div>
            <div class="hint">Middle age</div>
        </div>
        <div class="patient-kpi">
            <div class="val"><?= (int)($stats['young'] ?? 0) ?></div>
            <div class="lbl">Under 40</div>
            <div class="hint">Younger patients</div>
        </div>
        <div class="patient-kpi patient-kpi-screened">
            <div class="val"><?= (int)($stats['screened'] ?? 0) ?></div>
            <div class="lbl">Screened</div>
            <div class="hint">Has AI prediction</div>
        </div>
        <div class="patient-kpi">
            <div class="val"><?= (int)($stats['avg_age'] ?? 0) ?></div>
            <div class="lbl">Avg age</div>
            <div class="hint">Registry mean</div>
        </div>
        <div class="patient-kpi patient-kpi-new">
            <div class="val"><?= (int)($stats['new_30_days'] ?? 0) ?></div>
            <div class="lbl">New (30d)</div>
            <div class="hint">Recently registered</div>
        </div>
    </div>

    <p class="patient-kpi-note">
        <i class="fas fa-info-circle"></i>
        Summary reflects the full registry. Table filters below apply to the list only.
        <?= (int)($stats['not_screened'] ?? 0) ?> patient<?= ((int)($stats['not_screened'] ?? 0) === 1) ? '' : 's' ?> not yet screened.
    </p>

    <section class="clinical-panel">
        <div class="clinical-panel__head">
            <div class="clinical-panel__head-main">
                <h2><i class="fas fa-users"></i> Patient registry</h2>
                <p>Search, filter, and manage patient records</p>
            </div>
        </div>
        <div class="clinical-toolbar">
            <div class="clinical-grid-filters">
                <div>
                    <label class="clinical-label" for="search">Search</label>
                    <input type="text" id="search" class="form-control clinical-input" placeholder="Name or IC number…">
                </div>
                <div>
                    <label class="clinical-label" for="gender">Gender</label>
                    <select id="gender" class="form-control clinical-input">
                        <option value="">All</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div>
                    <label class="clinical-label" for="sort">Sort by</label>
                    <select id="sort" class="form-control clinical-input">
                        <option value="latest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="name">Name A-Z</option>
                    </select>
                </div>
                <div>
                    <label class="clinical-label">&nbsp;</label>
                    <button type="button" class="btn btn-clinical-primary btn-block" onclick="loadPatients()">
                        <i class="fas fa-sync-alt mr-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        <div class="clinical-panel__body clinical-panel__body--flush">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 clinical-data-table clinical-table">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th>IC</th>
                            <th>Patient name</th>
                            <th style="width:70px">Age</th>
                            <th style="width:90px">Gender</th>
                            <th style="width:150px">Registered</th>
                            <th class="text-center clinical-col-action">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="patientTable">
                        <tr>
                            <td colspan="7" class="clinical-loading-cell">
                                <span class="spinner-border spinner-border-sm text-primary mr-2"></span>Loading registry…
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav id="patientPagination" class="clinical-pagination-host" aria-label="Patient list pages"></nav>
        </div>
    </section>

</div>

<div class="modal fade" id="editPatientModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content clinical-modal">
            <div class="modal-header clinical-modal-header">
                <h5 class="modal-title mb-0 text-white"><i class="fas fa-edit mr-2"></i>Edit patient</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form id="editPatientForm">
                <div class="modal-body">
                    <input type="hidden" id="editPatientId">
                    <div class="form-group mb-3">
                        <label class="clinical-label">IC number</label>
                        <input type="text" id="editPatientIC" class="form-control clinical-input" readonly>
                    </div>
                    <div class="form-group mb-3">
                        <label class="clinical-label">Full name</label>
                        <input type="text" id="editPatientName" class="form-control clinical-input" required>
                    </div>
                    <div class="row">
                        <div class="col-6 form-group mb-3">
                            <label class="clinical-label">Age</label>
                            <input type="number" id="editPatientAge" class="form-control clinical-input" min="0" max="120" required>
                        </div>
                        <div class="col-6 form-group mb-3">
                            <label class="clinical-label">Gender</label>
                            <select id="editPatientGender" class="form-control clinical-input" required>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-clinical-outline" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-clinical-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$page_js = '<script src="' . BASE_URL . '/assets/js/clinical-pagination.js"></script>'
    . '<script src="' . BASE_URL . '/assets/js/pages/ophthalmologist/patient.js"></script>';
require BASE_PATH . '/includes/clinical_layout_end.php';
require_once BASE_PATH . '/includes/footer.php';
?>

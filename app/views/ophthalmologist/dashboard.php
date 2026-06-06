<?php
require_once BASE_PATH . '/app/helpers/DiagnosisHelper.php';
$page_css = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/pages/ophthalmologist/dashboard.css">';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidebar.php';

$clinicianName = htmlspecialchars($boot['clinician_name'] ?? 'Doctor');
$defaultFrom = $boot['filters']['date_from'] ?? date('Y-m-d', strtotime('-6 days'));
$defaultTo = $boot['filters']['date_to'] ?? date('Y-m-d');

$page_icon = 'fa-eye';
$page_subtitle = 'Your personal screening insights. Not shared with other doctors.';
$page_header_actions = '<span class="ai-status-pill offline" id="dashAiPill"><span class="dot"></span><span id="dashAiLabel">Checking AI…</span></span>';
require BASE_PATH . '/includes/clinical_layout_start.php';
?>

<div class="clinical-workspace olap-dashboard" id="olapDashboard">

    <div class="olap-top-bar">
        <div class="olap-slicer-compact" aria-label="Filter your screenings">
            <span class="olap-slicer-title d-none d-md-inline">Filter:</span>
            <input type="date" id="olapDateFrom" class="form-control form-control-sm" value="<?= htmlspecialchars($defaultFrom) ?>" title="From date">
            <input type="date" id="olapDateTo" class="form-control form-control-sm" value="<?= htmlspecialchars($defaultTo) ?>" title="To date">
            <select id="olapGender" class="form-control form-control-sm" title="Patient gender">
                <option value="">All genders</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
            <select id="olapRisk" class="form-control form-control-sm" title="Risk level">
                <option value="">All risk levels</option>
                <option value="Low">Low</option>
                <option value="Medium">Medium</option>
                <option value="High">High</option>
            </select>
            <select id="olapDiagnosis" class="form-control form-control-sm" title="AI diagnosis">
                <option value="">All findings</option>
                <?php foreach (DiagnosisHelper::all() as $dx): ?>
                    <option value="<?= htmlspecialchars($dx) ?>"><?= htmlspecialchars($dx) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="olapGranularity" class="form-control form-control-sm" title="Trend grouping">
                <option value="day">Daily trend</option>
                <option value="week">Weekly trend</option>
                <option value="month">Monthly trend</option>
            </select>
            <button type="button" class="btn btn-clinical-primary btn-sm" id="olapApply" title="Apply filters"><i class="fas fa-check"></i> Apply</button>
            <button type="button" class="btn btn-clinical-outline btn-sm" id="olapReset" title="Clear filters"><i class="fas fa-undo"></i></button>
        </div>
    </div>

    <div class="olap-meta-bar">
        <nav class="olap-breadcrumb" id="olapBreadcrumb" aria-label="Detail path"></nav>
        <p class="olap-hint mb-0" id="olapFilterSummary">Set dates and filters, then press Apply.</p>
        <button type="button" class="btn btn-clinical-outline btn-sm d-none" id="olapRollup"><i class="fas fa-arrow-left"></i> Back to overview</button>
        <span class="olap-loading d-none" id="olapLoading"><i class="fas fa-spinner fa-spin"></i> Updating…</span>
    </div>

    <div class="olap-deck" id="olapDeck">
        <div class="olap-toolbar">
            <button type="button" class="olap-nav-btn" id="olapPrev" aria-label="Previous"><i class="fas fa-chevron-left"></i></button>
            <span class="olap-page-label" id="olapPageLabel">Summary</span>
            <span class="olap-page-count" id="olapPageCount">1 / 5</span>
            <div class="olap-page-tabs" id="olapPageTabs">
                <button type="button" class="olap-page-tab active" data-slide="0">Summary</button>
                <button type="button" class="olap-page-tab" data-slide="1">Trends</button>
                <button type="button" class="olap-page-tab" data-slide="2">Findings</button>
                <button type="button" class="olap-page-tab" data-slide="3">Patients</button>
                <button type="button" class="olap-page-tab" data-slide="4">Recent</button>
            </div>
            <button type="button" class="olap-nav-btn" id="olapNext" aria-label="Next"><i class="fas fa-chevron-right"></i></button>
            <div class="olap-dots" id="olapDots"></div>
        </div>

        <div class="olap-viewport" id="olapViewport">
            <div class="olap-track" id="olapTrack">

                <section class="olap-slide olap-slide--summary" data-slide="0">
                    <h2 class="olap-slide-heading">Your practice at a glance</h2>
                    <div class="olap-kpi-grid olap-kpi-loading" id="olapKpis"></div>
                    <div class="olap-summary-row" id="olapExecutiveSummary"></div>
                    <div class="olap-empty-hint d-none" id="olapEmptyHint">
                        <i class="fas fa-eye"></i>
                        <p>No screenings yet. Run your first <a href="<?= BASE_URL ?>/ophthalmologist/predict">eye screening</a> and your dashboard will populate automatically.</p>
                    </div>
                </section>

                <section class="olap-slide" data-slide="1">
                    <h2 class="olap-slide-heading">How many patients you screened over time</h2>
                    <div class="olap-slide-split">
                        <div class="olap-tile olap-tile-grow">
                            <div class="olap-tile-head"><h6><i class="fas fa-chart-line"></i> Screening volume</h6><small>Tap a day to see details</small></div>
                            <div class="olap-chart-wrap"><canvas id="chartTrend"></canvas></div>
                        </div>
                        <div class="olap-tile">
                            <div class="olap-tile-head"><h6><i class="fas fa-chart-pie"></i> AI findings breakdown</h6><small>Tap a segment for list</small></div>
                            <div class="olap-chart-wrap"><canvas id="chartDiagnosis"></canvas></div>
                        </div>
                    </div>
                </section>

                <section class="olap-slide" data-slide="2">
                    <h2 class="olap-slide-heading">Risk level &amp; AI confidence</h2>
                    <div class="olap-slide-split olap-slide-split-even">
                        <div class="olap-tile">
                            <div class="olap-tile-head"><h6><i class="fas fa-shield-alt"></i> Patients needing attention</h6><small>By risk level</small></div>
                            <div class="olap-chart-wrap"><canvas id="chartRisk"></canvas></div>
                        </div>
                        <div class="olap-tile">
                            <div class="olap-tile-head"><h6><i class="fas fa-percentage"></i> AI certainty by finding</h6></div>
                            <div class="olap-chart-wrap"><canvas id="chartConfidence"></canvas></div>
                        </div>
                    </div>
                </section>

                <section class="olap-slide" data-slide="3">
                    <h2 class="olap-slide-heading">Who you screened (in this period)</h2>
                    <div class="olap-slide-split olap-slide-split-even">
                        <div class="olap-tile">
                            <div class="olap-tile-head"><h6><i class="fas fa-venus-mars"></i> By gender</h6></div>
                            <div class="olap-chart-wrap"><canvas id="chartGender"></canvas></div>
                        </div>
                        <div class="olap-tile">
                            <div class="olap-tile-head"><h6><i class="fas fa-birthday-cake"></i> By age group</h6></div>
                            <div class="olap-chart-wrap"><canvas id="chartAge"></canvas></div>
                        </div>
                    </div>
                </section>

                <section class="olap-slide" data-slide="4">
                    <h2 class="olap-slide-heading">Your latest screenings only</h2>
                    <div class="olap-tile olap-tile-fill">
                        <div class="olap-tile-head">
                            <h6><i class="fas fa-list"></i> Screenings you performed (not other doctors)</h6>
                            <a href="<?= BASE_URL ?>/ophthalmologist/predict" class="btn btn-clinical-primary btn-sm">New screening</a>
                        </div>
                        <div class="olap-table-wrap">
                            <table class="table table-sm table-striped clinical-data-table mb-0" id="olapRecentTable">
                                <thead>
                                    <tr><th>When</th><th>Patient</th><th>IC</th><th>Finding</th><th>Risk</th><th>AI %</th><th></th></tr>
                                </thead>
                                <tbody><tr><td colspan="7" class="text-muted text-center py-2">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </section>

            </div>

            <section class="olap-slide-drill d-none" id="olapDrillSlide">
                <div class="olap-tile olap-tile-fill">
                    <div class="olap-tile-head">
                        <h6 id="olapDrillTitle"><i class="fas fa-search-plus"></i> Screening list</h6>
                        <span class="olap-drill-count" id="olapDrillCount"></span>
                    </div>
                    <div class="olap-table-wrap">
                        <table class="table table-sm table-striped clinical-data-table mb-0" id="olapDrillTable">
                            <thead>
                                <tr>
                                    <th>When</th><th>Patient</th><th>IC</th><th>Age</th><th>Gender</th>
                                    <th>Finding</th><th>Risk</th><th>AI %</th><th>CNN</th><th>VGG</th><th>ResNet</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
window.DASHBOARD_BOOT = <?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>

<?php
$page_js = '<script src="' . BASE_URL . '/assets/js/pages/ophthalmologist/dashboard-olap.js" defer></script>';
require BASE_PATH . '/includes/clinical_layout_end.php';
require_once BASE_PATH . '/includes/footer.php';
?>

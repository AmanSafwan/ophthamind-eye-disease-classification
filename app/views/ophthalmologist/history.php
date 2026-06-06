<?php
require_once BASE_PATH . '/app/helpers/PaginationHelper.php';
$page_css = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/pages/ophthalmologist/history.css">'
    . '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/clinical-pagination.css">';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/sidebar.php';

$page_icon = 'fa-clipboard-list';
$logTotal = (int)($pagination['total'] ?? count($rows ?? []));
$page_subtitle = $logTotal . ' log entries'
    . (!empty($hasFilters) ? ' (filtered)' : '')
    . ' · page ' . (int)($pagination['page'] ?? 1) . ' of ' . (int)($pagination['total_pages'] ?? 1);
require BASE_PATH . '/includes/clinical_layout_start.php';

$moduleOrder = $moduleOrder ?? AuditHelper::MODULE_ORDER;
$paginationHtml = PaginationHelper::renderNav(
    $historyFormAction ?? (rtrim(BASE_URL, '/') . '/index.php'),
    $paginationQuery ?? ['url' => 'ophthalmologist/history'],
    $pagination ?? PaginationHelper::resolve(1, 0)
);
?>

<div class="clinical-workspace history-workspace">

    <div class="history-kpi-row" aria-label="Activity summary for your account">
        <div class="history-kpi history-kpi-clinical">
            <div class="val"><?= (int)($clinical['screenings'] ?? 0) ?></div>
            <div class="lbl">Screenings</div>
            <div class="hint">Your AI predictions</div>
        </div>
        <div class="history-kpi history-kpi-clinical">
            <div class="val"><?= (int)($clinical['patients'] ?? 0) ?></div>
            <div class="lbl">Patients</div>
            <div class="hint">Distinct patients screened</div>
        </div>
        <?php
        foreach ($moduleOrder as $module):
            $count = (int)($moduleCounts[$module] ?? 0);
            if ($count === 0) {
                continue;
            }
        ?>
            <div class="history-kpi history-kpi-module history-kpi-<?= strtolower(htmlspecialchars($module)) ?>">
                <div class="val"><?= $count ?></div>
                <div class="lbl"><?= htmlspecialchars($module) ?></div>
                <div class="hint">Audit log</div>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="history-kpi-note">
        <i class="fas fa-info-circle"></i>
        <?= htmlspecialchars($filterLabel ?? 'All time · your account only') ?>.
        Screenings and patients use the same doctor scope as the dashboard.
        Log modules count only your visible audit entries<?= !empty($hasFilters) ? ' matching the filters below' : '' ?>.
    </p>

    <section class="clinical-panel">
        <div class="clinical-panel__head">
            <div class="clinical-panel__head-main">
                <h2><i class="fas fa-shield-alt"></i> Clinical audit log</h2>
                <p>System activity for your clinician account</p>
            </div>
        </div>
        <div class="clinical-toolbar">
            <form method="get" action="<?= htmlspecialchars($historyFormAction) ?>" class="clinical-grid-filters history-filters">
                <input type="hidden" name="url" value="ophthalmologist/history">
                <div>
                    <label class="clinical-label" for="historySearch">Search</label>
                    <input type="text" id="historySearch" name="q" class="form-control clinical-input"
                        value="<?= htmlspecialchars($filters['q'] ?? '') ?>"
                        placeholder="Activity, patient, diagnosis…">
                </div>
                <div>
                    <label class="clinical-label" for="historyDateFrom">From date</label>
                    <input type="date" id="historyDateFrom" name="date_from" class="form-control clinical-input"
                        value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                </div>
                <div>
                    <label class="clinical-label" for="historyDateTo">To date</label>
                    <input type="date" id="historyDateTo" name="date_to" class="form-control clinical-input"
                        value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                </div>
                <div class="history-filter-actions">
                    <label class="clinical-label">&nbsp;</label>
                    <div class="history-filter-btns">
                        <button type="submit" class="btn btn-clinical-primary">
                            <i class="fas fa-filter mr-1"></i> Apply
                        </button>
                        <?php if (!empty($hasFilters)): ?>
                            <a href="<?= htmlspecialchars($historyFormAction) ?>?url=ophthalmologist/history" class="btn btn-clinical-light">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        <div class="clinical-panel__body clinical-panel__body--flush">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 clinical-data-table clinical-table">
                    <thead>
                        <tr>
                            <th style="width:150px">Timestamp</th>
                            <th style="width:100px">Module</th>
                            <th>Activity &amp; clinical context</th>
                            <th style="width:100px">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $entry):
                                $parsed = $entry['parsed'];
                                $module = $parsed['module'];
                                $badge = 'secondary';
                                if ($module === 'Prediction') $badge = 'primary';
                                elseif ($module === 'Patient') $badge = 'info';
                                elseif ($module === 'Report') $badge = 'dark';
                                elseif ($module === 'Auth') $badge = 'warning';
                            ?>
                                <tr>
                                    <td><?= date('d M Y, H:i', strtotime($entry['created_at'])) ?></td>
                                    <td><span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($module) ?></span></td>
                                    <td class="audit-activity-cell">
                                        <div class="audit-summary-line">
                                            <code class="audit-event-code"><?= htmlspecialchars(AuditHelper::eventCode($parsed['summary'])) ?></code>
                                            <?php $auditTitle = AuditHelper::eventTitle($parsed['summary']); ?>
                                            <?php if ($auditTitle !== ''): ?>
                                                <span class="audit-summary-title"><?= htmlspecialchars($auditTitle) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($parsed['detail_items'])): ?>
                                            <dl class="audit-detail-grid">
                                                <?php foreach ($parsed['detail_items'] as $item): ?>
                                                    <dt><?= htmlspecialchars($item['label']) ?></dt>
                                                    <dd><?= htmlspecialchars($item['value']) ?></dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        <?php elseif (!empty($parsed['details'])): ?>
                                            <div class="audit-details"><?= htmlspecialchars($parsed['details']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted"><?= htmlspecialchars($parsed['ip']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">
                                    <div class="clinical-empty-state">
                                        <i class="fas fa-search"></i>
                                        <strong>No records found</strong>
                                        <span>Adjust your search or date filters, or clear filters to see all entries</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= $paginationHtml ?>
        </div>
    </section>

</div>

<?php
require BASE_PATH . '/includes/clinical_layout_end.php';
require_once BASE_PATH . '/includes/footer.php';
?>

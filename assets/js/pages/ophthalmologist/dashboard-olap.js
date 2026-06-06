(function () {
    'use strict';

    const DX_COLORS = {
        Normal: '#10b981',
        Cataract: '#f59e0b',
        Glaucoma: '#7c3aed',
        'Diabetic Retinopathy': '#ef4444',
    };
    const RISK_COLORS = { Low: '#22c55e', Medium: '#f59e0b', High: '#ef4444' };
    const SLIDE_NAMES = ['Summary', 'Trends', 'Findings', 'Patients', 'Recent'];
    const SLIDE_COUNT = SLIDE_NAMES.length;
    const AUTOPLAY_MS = 7000;
    const CHARTS_PER_SLIDE = {
        1: ['trend', 'diagnosis'],
        2: ['risk', 'confidence'],
        3: ['gender', 'age'],
    };

    const state = { drill: '', drill_value: '', slide: 0, inDrill: false };
    let charts = {};
    let cachedSummary = null;
    let cachedCharts = null;
    let summaryAbort = null;
    let chartsAbort = null;
    let chartJsPromise = null;
    let chartsLoading = false;
    let autoplayTimer = null;
    let autoplayPaused = false;

    function el(id) { return document.getElementById(id); }

    function filtersFromForm() {
        return {
            date_from: el('olapDateFrom').value,
            date_to: el('olapDateTo').value,
            gender: el('olapGender').value,
            risk: el('olapRisk').value,
            diagnosis: el('olapDiagnosis').value,
            granularity: el('olapGranularity').value,
            drill: state.drill,
            drill_value: state.drill_value,
        };
    }

    function buildQuery(f) {
        const q = new URLSearchParams();
        Object.keys(f).forEach((k) => {
            if (f[k] !== undefined && f[k] !== null && String(f[k]) !== '') q.set(k, f[k]);
        });
        return q.toString();
    }

    function setLoading(on) {
        el('olapLoading').classList.toggle('d-none', !on);
    }

    function ensureChartJs() {
        if (window.Chart) return Promise.resolve();
        if (chartJsPromise) return chartJsPromise;
        const src = (window.DASHBOARD_BOOT && window.DASHBOARD_BOOT.chart_js) || '';
        chartJsPromise = new Promise((resolve, reject) => {
            if (!src) {
                reject(new Error('no chart'));
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = () => resolve();
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return chartJsPromise;
    }

    function fetchSummary() {
        if (summaryAbort) summaryAbort.abort();
        summaryAbort = new AbortController();
        setLoading(true);
        return clinicalFetch('ophthalmologist/dashboardSummary?' + buildQuery(filtersFromForm()), {
            signal: summaryAbort.signal,
        })
            .then(async (r) => {
                const data = await r.json().catch(() => null);
                if (!r.ok || !data) throw new Error('failed');
                return data;
            })
            .finally(() => setLoading(false));
    }

    function fetchCharts() {
        if (chartsAbort) chartsAbort.abort();
        chartsAbort = new AbortController();
        chartsLoading = true;
        return clinicalFetch('ophthalmologist/dashboardCharts?' + buildQuery(filtersFromForm()), {
            signal: chartsAbort.signal,
        })
            .then(async (r) => {
                const data = await r.json().catch(() => null);
                if (!r.ok || !data) throw new Error('failed');
                return data;
            })
            .finally(() => { chartsLoading = false; });
    }

    function mergedData() {
        if (!cachedSummary) return null;
        return Object.assign({}, cachedSummary, cachedCharts || {});
    }

    function riskClass(level) {
        const l = String(level || '').toLowerCase();
        if (l === 'high') return 'risk-high';
        if (l === 'medium') return 'risk-medium';
        return 'risk-low';
    }

    function formatDt(iso) {
        if (!iso) return 'N/A';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        const pad = (n) => String(n).padStart(2, '0');
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function destroyChart(key) {
        if (charts[key]) {
            charts[key].destroy();
            charts[key] = null;
        }
    }

    function destroyAllCharts() {
        Object.keys(charts).forEach(destroyChart);
    }

    function resizeCharts() {
        Object.keys(charts).forEach((k) => { if (charts[k]) charts[k].resize(); });
    }

    function stopAutoplay() {
        if (autoplayTimer) {
            clearInterval(autoplayTimer);
            autoplayTimer = null;
        }
    }

    function startAutoplay() {
        stopAutoplay();
        if (autoplayPaused || state.inDrill) return;
        autoplayTimer = setInterval(() => {
            if (autoplayPaused || state.inDrill) return;
            const next = (state.slide + 1) % SLIDE_COUNT;
            goToSlide(next, true);
        }, AUTOPLAY_MS);
    }

    function resetAutoplay() {
        startAutoplay();
    }

    /* -- Carousel -- */
    function buildDots() {
        const dots = el('olapDots');
        dots.innerHTML = '';
        for (let i = 0; i < SLIDE_COUNT; i++) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'olap-dot' + (i === state.slide ? ' active' : '');
            b.setAttribute('aria-label', SLIDE_NAMES[i]);
            b.addEventListener('click', () => goToSlide(i));
            dots.appendChild(b);
        }
    }

    function updateCarouselUi() {
        el('olapTrack').style.transform = 'translateX(-' + (state.slide * 100) + '%)';
        el('olapPageLabel').textContent = SLIDE_NAMES[state.slide];
        el('olapPageCount').textContent = (state.slide + 1) + ' / ' + SLIDE_COUNT;
        el('olapDots').querySelectorAll('.olap-dot').forEach((d, i) => d.classList.toggle('active', i === state.slide));
        document.querySelectorAll('.olap-page-tab').forEach((tab) => {
            tab.classList.toggle('active', parseInt(tab.dataset.slide, 10) === state.slide);
        });
        ensureChartsForSlide(state.slide);
        setTimeout(resizeCharts, 280);
    }

    function goToSlide(index, fromAutoplay) {
        if (state.inDrill) return;
        const prev = state.slide;
        state.slide = Math.max(0, Math.min(SLIDE_COUNT - 1, index));
        if (state.slide === prev && fromAutoplay) return;
        updateCarouselUi();
        if (!fromAutoplay) resetAutoplay();
        if (state.slide > 0 && !cachedCharts && !chartsLoading) {
            loadChartsInBackground();
        }
    }

    function initCarousel() {
        buildDots();
        el('olapPrev').addEventListener('click', () => {
            goToSlide(state.slide <= 0 ? SLIDE_COUNT - 1 : state.slide - 1);
        });
        el('olapNext').addEventListener('click', () => {
            goToSlide(state.slide >= SLIDE_COUNT - 1 ? 0 : state.slide + 1);
        });
        document.querySelectorAll('.olap-page-tab').forEach((tab) => {
            tab.addEventListener('click', () => goToSlide(parseInt(tab.dataset.slide, 10)));
        });
        document.addEventListener('keydown', (e) => {
            if (state.inDrill) return;
            if (e.key === 'ArrowLeft') goToSlide(state.slide - 1);
            if (e.key === 'ArrowRight') goToSlide(state.slide + 1);
        });

        const viewport = el('olapViewport');
        if (viewport) {
            viewport.addEventListener('mouseenter', () => {
                autoplayPaused = true;
                stopAutoplay();
            });
            viewport.addEventListener('mouseleave', () => {
                autoplayPaused = false;
                startAutoplay();
            });
        }

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopAutoplay();
            else if (!state.inDrill) startAutoplay();
        });

        if (typeof ResizeObserver !== 'undefined' && viewport) {
            const ro = new ResizeObserver(() => resizeCharts());
            ro.observe(viewport);
        }

        updateCarouselUi();
        startAutoplay();
    }

    function setDrillMode(on) {
        state.inDrill = on;
        el('olapDrillSlide').classList.toggle('d-none', !on);
        el('olapTrack').style.visibility = on ? 'hidden' : 'visible';
        el('olapPrev').disabled = on;
        el('olapNext').disabled = on;
        el('olapPageTabs').classList.toggle('olap-disabled', on);
        if (on) stopAutoplay();
        else {
            ensureChartsForSlide(state.slide);
            startAutoplay();
        }
    }

    /* -- KPIs & tables (instant) -- */
    function renderKpis(kpis) {
        const items = [
            { icon: 'fa-user-check', cls: 'primary', val: kpis.patients_seen ?? 0, lbl: 'Patients you screened' },
            { icon: 'fa-calendar-check', cls: 'info', val: kpis.screenings_in_range ?? 0, lbl: 'Screenings (selected dates)' },
            { icon: 'fa-sun', cls: 'success', val: kpis.screenings_today ?? 0, lbl: 'Screenings today' },
            { icon: 'fa-exclamation-circle', cls: 'danger', val: kpis.high_risk ?? 0, lbl: 'High-risk cases' },
            { icon: 'fa-history', cls: 'secondary', val: kpis.all_time_screenings ?? 0, lbl: 'All-time screenings' },
            { icon: 'fa-users', cls: 'info', val: kpis.unique_patients ?? 0, lbl: 'Different patients (period)' },
            { icon: 'fa-heartbeat', cls: 'warning', val: (kpis.high_risk_pct || 0) + '%', lbl: 'High-risk share' },
            { icon: 'fa-check-double', cls: 'success', val: (kpis.avg_confidence || 0) + '%', lbl: 'AI confidence (avg.)' },
        ];
        el('olapKpis').classList.remove('olap-kpi-loading');
        el('olapKpis').innerHTML = items.map((it) => `
            <div class="olap-kpi olap-kpi-${it.cls}">
                <i class="fas ${it.icon}"></i>
                <div class="olap-kpi-val">${it.val}</div>
                <div class="olap-kpi-lbl">${it.lbl}</div>
            </div>
        `).join('');
    }

    function renderExecutiveSummary(data) {
        const kpis = data.kpis || {};
        const agreement = data.model_agreement_pct ?? kpis.model_agreement_pct ?? 0;
        const total = data.total_screenings || 0;
        el('olapExecutiveSummary').innerHTML = `
            <div class="olap-summary-box"><span class="olap-summary-val text-primary">${total}</span><span class="olap-summary-lbl">Matching your filters</span></div>
            <div class="olap-summary-box"><span class="olap-summary-val text-danger">${kpis.high_risk_pct || 0}%</span><span class="olap-summary-lbl">Need follow-up (high risk)</span></div>
            <div class="olap-summary-box"><span class="olap-summary-val text-info">${kpis.avg_confidence || 0}%</span><span class="olap-summary-lbl">AI certainty (average)</span></div>
            <div class="olap-summary-box"><span class="olap-summary-val text-success">${agreement}%</span><span class="olap-summary-lbl">All 3 models agreed</span></div>
        `;
    }

    function toggleEmptyState(data) {
        const hint = el('olapEmptyHint');
        if (!hint) return;
        const allTime = (data.kpis && data.kpis.all_time_screenings) || 0;
        hint.classList.toggle('d-none', allTime > 0);
        el('olapExecutiveSummary').classList.toggle('d-none', allTime === 0);
    }

    function renderSummary(data) {
        const f = data.filters || {};
        const parts = [];
        parts.push(data.kpis?.date_range_label || '');
        if (f.gender) parts.push(f.gender);
        if (f.risk) parts.push(f.risk + ' risk');
        if (f.diagnosis) parts.push(f.diagnosis);
        parts.push((data.total_screenings || 0) + ' screenings');
        el('olapFilterSummary').textContent = parts.filter(Boolean).join(' · ');
    }

    function renderBreadcrumb(crumbs) {
        const nav = el('olapBreadcrumb');
        if (!crumbs || !crumbs.length) { nav.innerHTML = ''; return; }
        nav.innerHTML = crumbs.map((c, i) => {
            if (i < crumbs.length - 1 && c.level !== undefined) {
                return `<button type="button" class="olap-crumb" data-level="${c.level}">${escapeHtml(c.label)}</button>`;
            }
            return `<span class="olap-crumb-active">${escapeHtml(c.label)}</span>`;
        }).join('<span class="olap-crumb-sep">›</span>');
        nav.querySelectorAll('.olap-crumb[data-level]').forEach((btn) => {
            btn.addEventListener('click', () => rollupTo(btn.dataset.level));
        });
        el('olapRollup').classList.toggle('d-none', !(state.drill && state.drill_value));
    }

    function renderRecentTable(rows) {
        const tbody = el('olapRecentTable').querySelector('tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">No screenings in this period.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((r) => {
            const dx = normalizeDiagnosis(r.final_result);
            return `<tr>
                <td>${formatDt(r.created_at)}</td>
                <td><strong>${escapeHtml(r.name)}</strong></td>
                <td>${escapeHtml(r.ic)}</td>
                <td><span class="clinical-badge-dx ${getClinicalDxClass(dx)}">${escapeHtml(dx)}</span></td>
                <td><span class="clinical-badge-risk ${riskClass(r.risk_level)}">${escapeHtml(r.risk_level)}</span></td>
                <td>${r.confidence}%</td>
                <td class="clinical-action-cell"><a href="${clinicalPageUrl('ophthalmologist/predict', { ic: r.ic, view: 'history' })}" class="btn btn-clinical-outline btn-sm">View</a></td>
            </tr>`;
        }).join('');
    }

    function renderTablesAndKpis(data) {
        renderKpis(data.kpis || {});
        renderExecutiveSummary(data);
        renderSummary(data);
        renderBreadcrumb(data.breadcrumb);
        renderRecentTable(data.recent_screenings || []);
        toggleEmptyState(data);
    }

    /* -- Charts (lazy per slide) -- */
    function chartOptions(onClick, onPick) {
        const opts = {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 280 },
            legend: { position: 'bottom', labels: { boxWidth: 10, fontSize: 11, fontFamily: "'Inter', sans-serif" } },
        };
        if (onClick && onPick) {
            opts.onClick = (evt, els) => { if (els && els.length) onPick(els[0]._index); };
            opts.onHover = (evt, els) => { evt.target.style.cursor = els.length ? 'pointer' : 'default'; };
        }
        return opts;
    }

    function buildChart(key, data) {
        const dxLabels = window.CLINICAL_DIAGNOSES || [];
        if (key === 'trend') {
            const trend = data.trend || { labels: [], values: [] };
            charts.trend = new Chart(el('chartTrend').getContext('2d'), {
                type: 'line',
                data: {
                    labels: trend.labels,
                    datasets: [{ label: 'Screenings', data: trend.values, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.1)', fill: true, tension: 0.3, pointRadius: 3 }],
                },
                options: chartOptions(true, (idx) => {
                    const label = trend.labels[idx];
                    if (el('olapGranularity').value === 'day' && /^\d{4}-\d{2}-\d{2}$/.test(label)) drill('day', label);
                }),
            });
        } else if (key === 'diagnosis') {
            const dxData = dxLabels.map((d) => (data.diagnosis && data.diagnosis[d]) || 0);
            charts.diagnosis = new Chart(el('chartDiagnosis').getContext('2d'), {
                type: 'doughnut',
                data: { labels: dxLabels, datasets: [{ data: dxData, backgroundColor: dxLabels.map((d) => DX_COLORS[d] || '#94a3b8') }] },
                options: chartOptions(true, (idx) => { if (dxData[idx] > 0) drill('diagnosis', dxLabels[idx]); }),
            });
        } else if (key === 'risk') {
            const risks = ['Low', 'Medium', 'High'];
            const riskData = risks.map((r) => (data.risk && data.risk[r]) || 0);
            charts.risk = new Chart(el('chartRisk').getContext('2d'), {
                type: 'bar',
                data: { labels: risks, datasets: [{ data: riskData, backgroundColor: risks.map((r) => RISK_COLORS[r]) }] },
                options: { ...chartOptions(true, (idx) => { if (riskData[idx] > 0) drill('risk', risks[idx]); }), scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } },
            });
        } else if (key === 'confidence') {
            const conf = data.confidence_by_diagnosis || {};
            charts.confidence = new Chart(el('chartConfidence').getContext('2d'), {
                type: 'bar',
                data: { labels: dxLabels, datasets: [{ data: dxLabels.map((d) => conf[d] || 0), backgroundColor: dxLabels.map((d) => DX_COLORS[d] || '#94a3b8') }] },
                options: { legend: { display: false }, scales: { yAxes: [{ ticks: { beginAtZero: true, max: 100 } }] } },
            });
        } else if (key === 'gender') {
            const gender = data.gender || {};
            charts.gender = new Chart(el('chartGender').getContext('2d'), {
                type: 'horizontalBar',
                data: { labels: ['Male', 'Female'], datasets: [{ data: [gender.Male || 0, gender.Female || 0], backgroundColor: ['#3b82f6', '#ec4899'] }] },
                options: { legend: { display: false }, scales: { xAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } },
            });
        } else if (key === 'age') {
            const age = data.age_bands || {};
            const ageLabels = Object.keys(age);
            charts.age = new Chart(el('chartAge').getContext('2d'), {
                type: 'bar',
                data: { labels: ageLabels, datasets: [{ data: ageLabels.map((k) => age[k] || 0), backgroundColor: '#6366f1' }] },
                options: { legend: { display: false }, scales: { yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }] } },
            });
        }
    }

    function ensureChartsForSlide(slide) {
        if (state.inDrill || !CHARTS_PER_SLIDE[slide]) return;
        const data = mergedData();
        if (!data) return;

        ensureChartJs()
            .then(() => {
                const keys = CHARTS_PER_SLIDE[slide] || [];
                keys.forEach((key) => {
                    if (!charts[key]) buildChart(key, data);
                });
                resizeCharts();
            })
            .catch(() => {});
    }

    function loadChartsInBackground() {
        if (chartsLoading || state.inDrill) return;
        fetchCharts()
            .then((data) => {
                if (!data) return;
                cachedCharts = data;
                if (state.slide > 0) ensureChartsForSlide(state.slide);
                setTimeout(resizeCharts, 150);
            })
            .catch((err) => {
                if (err && err.name === 'AbortError') return;
            });
    }

    function applySummary(data) {
        cachedSummary = data;
        cachedCharts = null;
        destroyAllCharts();
        setDrillMode(false);
        renderTablesAndKpis(data);
        setTimeout(resizeCharts, 300);
        if ((data.kpis && data.kpis.all_time_screenings) > 0) {
            setTimeout(loadChartsInBackground, 50);
        }
    }

    function renderDrill(data) {
        cachedSummary = null;
        cachedCharts = null;
        destroyAllCharts();
        setDrillMode(true);
        renderBreadcrumb(data.breadcrumb);
        el('olapDrillTitle').innerHTML = '<i class="fas fa-search-plus"></i> ' + escapeHtml(data.drill_title || 'Detail');
        el('olapDrillCount').textContent = (data.drill_count || 0) + ' records';
        el('olapFilterSummary').textContent = (data.drill_count || 0) + ' screenings · Back to overview returns to charts';

        const rows = data.drill_table || [];
        const tbody = el('olapDrillTable').querySelector('tbody');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-muted text-center py-3">No records.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((r) => {
            const dx = normalizeDiagnosis(r.final_result);
            return `<tr>
                <td>${formatDt(r.created_at)}</td>
                <td><strong>${escapeHtml(r.name)}</strong></td>
                <td>${escapeHtml(r.ic)}</td>
                <td>${r.age}</td>
                <td>${escapeHtml(r.gender)}</td>
                <td><span class="clinical-badge-dx ${getClinicalDxClass(dx)}">${escapeHtml(dx)}</span></td>
                <td><span class="clinical-badge-risk ${riskClass(r.risk_level)}">${escapeHtml(r.risk_level)}</span></td>
                <td>${r.confidence}%</td>
                <td>${escapeHtml(normalizeDiagnosis(r.cnn))}</td>
                <td>${escapeHtml(normalizeDiagnosis(r.vgg))}</td>
                <td>${escapeHtml(normalizeDiagnosis(r.resnet))}</td>
            </tr>`;
        }).join('');
    }

    function drill(type, value) {
        state.drill = type;
        state.drill_value = value;
        refresh();
    }

    function rollupTo(level) {
        if (level === 'root') { state.drill = ''; state.drill_value = ''; }
        refresh();
    }

    function showLoadError(message) {
        el('olapFilterSummary').textContent = message || 'Could not load data. Please try again.';
        el('olapKpis').classList.remove('olap-kpi-loading');
    }

    function handleApiPayload(data) {
        if (!data) {
            showLoadError('No data returned from server.');
            return;
        }
        if (data.status === 'unauthenticated' || data.status === 'unauthorized') {
            showLoadError(data.message || 'Session expired. Please sign in again.');
            return;
        }
        if (data.error) {
            showLoadError(data.message || 'Could not load dashboard data.');
            return;
        }
        if (data.mode === 'drill') renderDrill(data);
        else applySummary(data);
    }

    function refresh() {
        cachedCharts = null;
        fetchSummary()
            .then((data) => handleApiPayload(data))
            .catch((err) => {
                if (err && err.name === 'AbortError') return;
                showLoadError('Could not load data. Check your connection and refresh the page.');
            });
    }

    function resetAll() {
        const boot = window.DASHBOARD_BOOT || {};
        const f = boot.filters || {};
        el('olapDateFrom').value = f.date_from || el('olapDateFrom').value;
        el('olapDateTo').value = f.date_to || el('olapDateTo').value;
        el('olapGender').value = '';
        el('olapRisk').value = '';
        el('olapDiagnosis').value = '';
        el('olapGranularity').value = 'day';
        state.drill = '';
        state.drill_value = '';
        refresh();
    }

    function loadAiPing() {
        const pill = document.getElementById('dashAiPill');
        const label = document.getElementById('dashAiLabel');
        if (!pill || !label) return;
        label.textContent = 'AI status…';
        clinicalFetch('ophthalmologist/dashboardAiPing')
            .then((r) => r.json())
            .then((data) => {
                const on = !!(data && data.online);
                pill.classList.toggle('online', on);
                pill.classList.toggle('offline', !on);
                label.textContent = on ? 'AI ready' : 'AI offline';
            })
            .catch(() => { pill.classList.add('offline'); label.textContent = 'AI offline'; });
    }

    function init() {
        initCarousel();
        setTimeout(loadAiPing, 2500);

        el('olapApply').addEventListener('click', () => { state.drill = ''; state.drill_value = ''; refresh(); });
        el('olapReset').addEventListener('click', resetAll);
        el('olapRollup').addEventListener('click', () => rollupTo('root'));

        let resizeT;
        window.addEventListener('resize', () => {
            clearTimeout(resizeT);
            resizeT = setTimeout(resizeCharts, 150);
        });

        refresh();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

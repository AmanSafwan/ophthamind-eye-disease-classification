let patientListPage = 1;

function debounce(fn, delay) {
    let timer;
    return function () {
        clearTimeout(timer);
        timer = setTimeout(() => fn(), delay);
    };
}

function loadPatients(page) {
    if (typeof page === 'number' && page > 0) {
        patientListPage = page;
    }

    const search = document.getElementById('search').value;
    const gender = document.getElementById('gender').value;
    const sort = document.getElementById('sort').value;

    clinicalFetch('ophthalmologist/patientData', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `search=${encodeURIComponent(search)}&gender=${encodeURIComponent(gender)}&sort=${encodeURIComponent(sort)}&page=${encodeURIComponent(patientListPage)}`
    })
    .then(res => res.json())
    .then(data => {
        const table = document.getElementById('patientTable');
        const items = Array.isArray(data) ? data : (data.items || []);
        const pagination = Array.isArray(data) ? null : (data.pagination || null);

        if (!Array.isArray(items) || items.length === 0) {
            table.innerHTML = `<tr><td colspan="7"><div class="clinical-empty-state"><i class="fas fa-user-slash"></i><strong>No patients found</strong><span>Try a different search or register via AI screening</span></div></td></tr>`;
            if (typeof renderClinicalPagination === 'function') {
                renderClinicalPagination('patientPagination', pagination || { total: 0, page: 1, total_pages: 1, from: 0, to: 0 }, loadPatients);
            }
            return;
        }

        const offset = pagination ? (pagination.from - 1) : 0;
        let html = '';
        items.forEach((p, index) => {
            html += `
                <tr>
                    <td>${offset + index + 1}</td>
                    <td><code class="clinical-ic">${escapeHtml(p.ic ?? '-')}</code></td>
                    <td><strong>${escapeHtml(p.name ?? '-')}</strong></td>
                    <td>${escapeHtml(String(p.age ?? '-'))}</td>
                    <td>${escapeHtml(p.gender ?? '-')}</td>
                    <td class="small">${formatDate(p.created_at)}</td>
                    <td class="clinical-action-cell">
                        <div class="clinical-action-group">
                            <button type="button" class="btn btn-clinical-edit" onclick="editPatient(${p.id})" title="Edit patient">
                                <i class="fas fa-edit"></i><span>Edit</span>
                            </button>
                            <button type="button" class="btn btn-clinical-delete" onclick='deletePatient(${p.id}, ${JSON.stringify(p.name ?? "")})' title="Delete patient">
                                <i class="fas fa-trash-alt"></i><span>Delete</span>
                            </button>
                            <button type="button" class="btn btn-clinical-predict"
                                    data-patient-ic="${escapeHtml(p.ic ?? '')}"
                                    onclick="openPatientPredictions(this.getAttribute('data-patient-ic'))"
                                    title="Open AI screening &amp; prediction history">
                                <i class="fas fa-chart-line"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        table.innerHTML = html;

        if (pagination && typeof renderClinicalPagination === 'function') {
            patientListPage = pagination.page;
            renderClinicalPagination('patientPagination', pagination, loadPatients);
        }
    })
    .catch(() => {
        document.getElementById('patientTable').innerHTML =
            '<tr><td colspan="7" class="text-center text-danger py-4">Failed to load patients</td></tr>';
    });
}

function resetPatientPageAndLoad() {
    patientListPage = 1;
    loadPatients(1);
}

function openPatientPredictions(ic) {
    const clean = String(ic || '').replace(/\D/g, '').slice(0, 12);
    if (clean.length !== 12) {
        showPatientToast('Invalid IC for this patient', 'error');
        return;
    }

    const target = typeof clinicalPageUrl === 'function'
        ? clinicalPageUrl('ophthalmologist/predict', { ic: clean, view: 'history' })
        : `${window.APP_BASE || ''}/ophthalmologist/predict?ic=${encodeURIComponent(clean)}&view=history`;

    window.location.href = target;
}

function editPatient(id) {
    clinicalFetch(`ophthalmologist/patientGet&id=${encodeURIComponent(id)}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.patient) {
                showPatientToast(data.message || 'Patient not found', 'error');
                return;
            }
            const p = data.patient;
            document.getElementById('editPatientId').value = p.id;
            document.getElementById('editPatientIC').value = p.ic;
            document.getElementById('editPatientName').value = p.name;
            document.getElementById('editPatientAge').value = p.age;
            const g = String(p.gender || '').toLowerCase();
            document.getElementById('editPatientGender').value = g === 'female' || g === 'f' ? 'Female' : 'Male';
            $('#editPatientModal').modal('show');
        })
        .catch(() => showPatientToast('Failed to load patient', 'error'));
}

function deletePatient(id, name) {
    if (!confirm(
        `Delete patient "${name}"?\n\n` +
        'This will permanently remove the patient and ALL AI screening results, images, and history for this record.\n\nContinue?'
    )) return;

    clinicalFetch('ophthalmologist/patientDelete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${encodeURIComponent(id)}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const n = data.predictions_removed ?? 0;
            const msg = n > 0
                ? `Patient deleted (${n} screening record${n === 1 ? '' : 's'} removed)`
                : 'Patient deleted';
            showPatientToast(msg, 'success');
            loadPatients(patientListPage);
        } else {
            showPatientToast(data.message || 'Delete failed', 'error');
        }
    })
    .catch(() => showPatientToast('Delete request failed', 'error'));
}

function showPatientToast(message, type) {
    if (typeof showToast === 'function') showToast(message, type);
    else alert(message);
}

function formatDate(datetime) {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString('en-MY', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.getElementById('search').addEventListener('input', debounce(resetPatientPageAndLoad, 300));
document.getElementById('gender').addEventListener('change', resetPatientPageAndLoad);
document.getElementById('sort').addEventListener('change', resetPatientPageAndLoad);

const editPatientFormEl = document.getElementById('editPatientForm');
if (editPatientFormEl) {
    editPatientFormEl.addEventListener('submit', function (e) {
        e.preventDefault();
        const id = document.getElementById('editPatientId').value;
        const name = document.getElementById('editPatientName').value.trim();
        const age = document.getElementById('editPatientAge').value;
        const gender = document.getElementById('editPatientGender').value;

        if (!name) {
            showPatientToast('Please enter the patient name', 'error');
            return;
        }
        const ageNum = parseInt(age, 10);
        if (Number.isNaN(ageNum) || ageNum < 0 || ageNum > 120) {
            showPatientToast('Age must be between 0 and 120', 'error');
            return;
        }

        clinicalFetch('ophthalmologist/patientUpdate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${encodeURIComponent(id)}&name=${encodeURIComponent(name)}&age=${encodeURIComponent(ageNum)}&gender=${encodeURIComponent(gender)}`
        })
        .then(async (res) => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (parseErr) {
                console.error('patientUpdate raw:', text);
                throw new Error('Server returned an invalid response');
            }
        })
        .then(data => {
            if (data && (data.status === 'unauthenticated' || data.status === 'unauthorized')) {
                showPatientToast(data.message || 'Please log in again', 'error');
                return;
            }
            if (data.success) {
                $('#editPatientModal').modal('hide');
                showPatientToast('Patient updated', 'success');
                loadPatients(patientListPage);
            } else {
                showPatientToast(data.message || 'Update failed', 'error');
            }
        })
        .catch((err) => showPatientToast(err.message || 'Update failed', 'error'));
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('sort').value = 'latest';
    loadPatients(1);
});

window.loadPatients = loadPatients;
window.editPatient = editPatient;
window.deletePatient = deletePatient;
window.openPatientPredictions = openPatientPredictions;

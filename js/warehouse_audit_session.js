/**
 * Warehouse Audit Session - page controller
 *
 * Wires together: scanner (html5-qrcode via AuditScanner), offline queue
 * (AuditOffline), three scan tiers (barcode/AI/manual), photo capture,
 * equipment checks, counter updates, and keepalive ping.
 *
 * Expects window.AUDIT_BOOTSTRAP to be set by warehouse_audit_session.php
 * with { session_id, csrf_token, expected_wattages, expected_modules_per_pallet,
 * ai_vision_enabled, initial_state, ... }.
 */
(function () {
    'use strict';

    var B = window.AUDIT_BOOTSTRAP || {};
    if (!B.session_id) {
        console.error('AUDIT_BOOTSTRAP missing');
        return;
    }

    var state = {
        scans: (B.initial_state && B.initial_state.scans) || [],
        equipment: (B.initial_state && B.initial_state.equipment) || [],
        photo_counts: (B.initial_state && B.initial_state.photo_counts) || { scans: {}, equipment: {} },
        reconciliation: (B.initial_state && B.initial_state.reconciliation) || {},
        quick_wattage: null,
        ai_vision_call_count: B.ai_vision_call_count || 0,
        ai_vision_call_cap: B.ai_vision_call_cap || 2000,
    };

    // -----------------------------------------------------------------------
    // Utility: uuid v4
    // -----------------------------------------------------------------------
    function uuidv4() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function toast(msg, kind) {
        var t = document.createElement('div');
        t.className = 'audit-toast ' + (kind || 'info');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('visible'); }, 10);
        setTimeout(function () {
            t.classList.remove('visible');
            setTimeout(function () { t.remove(); }, 300);
        }, 2800);
    }

    // -----------------------------------------------------------------------
    // Tabs
    // -----------------------------------------------------------------------
    function initTabs() {
        document.querySelectorAll('.audit-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = btn.getAttribute('data-tab');
                document.querySelectorAll('.audit-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
                document.querySelectorAll('.tab-panel').forEach(function (p) {
                    p.classList.toggle('active', p.getAttribute('data-panel') === tab);
                });
                // Stop the scanner when leaving scan tab
                if (tab !== 'scan' && window.AuditScanner && AuditScanner.isActive()) {
                    AuditScanner.stop();
                }
            });
        });
    }

    // -----------------------------------------------------------------------
    // Counter / progress / scan list rendering
    // -----------------------------------------------------------------------
    function updateCounters() {
        var r = state.reconciliation || {};
        var expected = r.expected_count || 0;
        var verified = r.verified_count || 0;
        var discrepancy = r.discrepancy_count || 0;

        var el;
        el = document.getElementById('counterVerified');    if (el) el.textContent = verified;
        el = document.getElementById('counterExpected');    if (el) el.textContent = expected || '–';
        el = document.getElementById('counterDiscrepancy'); if (el) el.textContent = discrepancy;

        var pct = expected > 0 ? Math.min(100, Math.round((verified / expected) * 100)) : 0;
        var bar = document.getElementById('progressBar');
        if (bar) bar.style.width = pct + '%';

        var lb = document.getElementById('listBadge');    if (lb) lb.textContent = state.scans.length;
        var eb = document.getElementById('equipmentBadge'); if (eb) eb.textContent = state.equipment.length;
    }

    function renderScanList() {
        var container = document.getElementById('scanList');
        if (!container) return;
        var q = (document.getElementById('scanSearchInput').value || '').toLowerCase();
        var filter = document.getElementById('scanFilter').value;

        var filtered = state.scans.filter(function (s) {
            if (filter === 'verified' && s.is_discrepancy == 1) return false;
            if (filter === 'discrepancy' && s.is_discrepancy != 1) return false;
            if (q) {
                var hay = ((s.raw_value || '') + ' ' + (s.notes || '') + ' ' + (s.normalized_pallet_id || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        if (filtered.length === 0) {
            container.innerHTML = '<div class="scan-list-empty">No scans yet.</div>';
            return;
        }

        container.innerHTML = filtered.map(function (s) {
            var cls = s.is_discrepancy == 1 ? 'scan-row scan-discrepancy' : 'scan-row scan-verified';
            var codes = s.discrepancy_codes ? s.discrepancy_codes.split(',') : [];
            var codeBadges = codes.map(function (c) {
                return '<span class="scan-code-badge">' + escapeHtml(c.replace(/_/g, ' ').toLowerCase()) + '</span>';
            }).join('');
            var methodIcon = { barcode: '▥', qr: '▣', ai_vision: '✨', manual: '⌨' }[s.scan_method] || '•';
            return '<div class="' + cls + '" data-scan-id="' + s.id + '">' +
                '<div class="scan-row-seq">#' + s.sequence_number + '</div>' +
                '<div class="scan-row-main">' +
                    '<div class="scan-row-id">' + methodIcon + ' ' + escapeHtml(s.raw_value || s.normalized_pallet_id || '') + '</div>' +
                    '<div class="scan-row-meta">' +
                        (s.parsed_wattage ? '<span>' + s.parsed_wattage + 'W</span>' : '') +
                        (s.quantity ? ' · <span>' + s.quantity + ' mods</span>' : '') +
                        (codeBadges ? ' · ' + codeBadges : '') +
                    '</div>' +
                '</div>' +
                '<div class="scan-row-actions">' +
                    '<button class="scan-row-btn" data-action="photo" data-scan-id="' + s.id + '">📷</button>' +
                    '<button class="scan-row-btn" data-action="edit" data-scan-id="' + s.id + '">✎</button>' +
                    '<button class="scan-row-btn scan-row-btn-del" data-action="delete" data-scan-id="' + s.id + '">×</button>' +
                '</div>' +
            '</div>';
        }).join('');

        container.querySelectorAll('.scan-row-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                var id = parseInt(btn.getAttribute('data-scan-id'), 10);
                if (action === 'delete') { deleteScan(id); }
                else if (action === 'photo') { openPhotoModal(id); }
                else if (action === 'edit') { openManualModalForEdit(id); }
            });
        });
    }

    function renderEquipmentList() {
        var container = document.getElementById('equipmentList');
        if (!container) return;
        if (state.equipment.length === 0) {
            container.innerHTML = '<div class="equipment-empty">No equipment to check for this session.</div>';
            return;
        }
        container.innerHTML = state.equipment.map(function (eq) {
            var statusCls = 'eq-status-' + eq.status;
            var photoCount = (state.photo_counts && state.photo_counts.equipment && state.photo_counts.equipment[eq.id]) || 0;
            var photoBadge = photoCount > 0
                ? '<span class="eq-photo-count" title="' + photoCount + ' photo(s) attached">📷 ' + photoCount + '</span>'
                : '';
            return '<div class="eq-row ' + statusCls + '" data-eq-id="' + eq.id + '">' +
                '<div class="eq-main">' +
                    '<div class="eq-type">' + escapeHtml(eq.equipment_type) + ' ' + photoBadge + '</div>' +
                    '<div class="eq-sub">' + escapeHtml(eq.manufacturer_name || '') +
                        (eq.serial_number ? ' · S/N ' + escapeHtml(eq.serial_number) : '') + '</div>' +
                '</div>' +
                '<div class="eq-status-label">' + escapeHtml(eq.status.replace(/_/g, ' ')) + '</div>' +
                '<button class="btn-small" data-eq-action="edit" data-eq-id="' + eq.id + '">Verify</button>' +
            '</div>';
        }).join('');
        container.querySelectorAll('[data-eq-action="edit"]').forEach(function (btn) {
            btn.addEventListener('click', function () { openEquipmentModal(parseInt(btn.getAttribute('data-eq-id'), 10)); });
        });
    }

    function bumpEquipmentPhotoCount(eq_id, delta) {
        if (!state.photo_counts) state.photo_counts = { scans: {}, equipment: {} };
        if (!state.photo_counts.equipment) state.photo_counts.equipment = {};
        state.photo_counts.equipment[eq_id] = (state.photo_counts.equipment[eq_id] || 0) + (delta || 1);
        renderEquipmentList();
    }

    function uploadEquipmentPhoto(equipment_check_id, file, photo_role) {
        var fd = new FormData();
        fd.append('csrf_token', B.csrf_token);
        fd.append('session_id', B.session_id);
        fd.append('equipment_check_id', equipment_check_id);
        fd.append('photo_role', photo_role);
        fd.append('file', file);
        return window.AuditOffline.enqueuePhoto(fd);
    }

    // -----------------------------------------------------------------------
    // State sync from API responses
    // -----------------------------------------------------------------------
    function applyScanResponse(data) {
        if (!data || !data.scan) return;
        var idx = state.scans.findIndex(function (s) { return s.id == data.scan.id; });
        if (idx >= 0) state.scans[idx] = data.scan;
        else state.scans.unshift(data.scan);
        state.scans.sort(function (a, b) { return (b.sequence_number || 0) - (a.sequence_number || 0); });
        if (data.reconciliation) state.reconciliation = data.reconciliation;
        updateCounters();
        renderScanList();
    }

    function applyEquipmentResponse(eq) {
        var idx = state.equipment.findIndex(function (e) { return e.id == eq.id; });
        if (idx >= 0) state.equipment[idx] = eq;
        else state.equipment.push(eq);
        renderEquipmentList();
        updateCounters();
    }

    // -----------------------------------------------------------------------
    // Scan submission via the offline queue
    // -----------------------------------------------------------------------
    function submitScan(payload) {
        payload.session_id = B.session_id;
        payload.csrf_token = B.csrf_token;
        payload.client_uuid = payload.client_uuid || uuidv4();
        payload.client_captured_at = payload.client_captured_at || new Date().toISOString().slice(0, 19).replace('T', ' ');
        if (state.quick_wattage && !payload.parsed_wattage) {
            payload.parsed_wattage = state.quick_wattage;
        }
        if (!payload.quantity && B.expected_modules_per_pallet) {
            payload.quantity = B.expected_modules_per_pallet;
        }
        return window.AuditOffline.enqueueScan(payload)
            .then(function (data) {
                applyScanResponse(data);
                toast('Scan saved', 'success');
                return data;
            })
            .catch(function (err) {
                toast('Offline — queued', 'warn');
                return null;
            });
    }

    function deleteScan(scan_id) {
        if (!confirm('Delete this scan?')) return;
        var fd = new FormData();
        fd.append('csrf_token', B.csrf_token);
        fd.append('session_id', B.session_id);
        fd.append('scan_id', scan_id);
        fetch('api/audit/delete_scan.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { toast(data.error || 'Delete failed', 'error'); return; }
                state.scans = state.scans.filter(function (s) { return s.id != scan_id; });
                if (data.reconciliation) state.reconciliation = data.reconciliation;
                updateCounters();
                renderScanList();
                toast('Scan removed', 'success');
            })
            .catch(function (err) { toast('Delete failed: ' + err.message, 'error'); });
    }

    // -----------------------------------------------------------------------
    // Tier 1: barcode/QR scan button
    // -----------------------------------------------------------------------
    function handleBarcodeScanButton() {
        if (!window.AuditScanner) {
            toast('Scanner module not loaded — use manual entry', 'warn');
            setTimeout(openManualModal, 400);
            return;
        }
        // Specific pre-flight issue (secure context, missing lib, no getUserMedia)
        var issue = AuditScanner.getSupportIssue && AuditScanner.getSupportIssue();
        if (issue) {
            toast(issue, 'warn');
            // Camera is never going to work in this context — go straight to
            // manual entry instead of leaving the user stranded.
            setTimeout(openManualModal, 600);
            return;
        }
        if (AuditScanner.isActive()) {
            AuditScanner.stop();
            toast('Scanner stopped', 'info');
            return;
        }
        AuditScanner.start(
            'scannerViewport',
            function (result) {
                var payload = {
                    scan_method: result.format && /qr/i.test(result.format) ? 'qr' : 'barcode',
                    raw_value: result.decodedText,
                };
                submitScan(payload);
            },
            function (err) {
                // Runtime camera failure (e.g. permission denied, hardware in
                // use by another app). Surface the message and auto-open the
                // manual entry modal so the auditor can keep moving without
                // touching any other control.
                var msg = (err && err.message) ? err.message : String(err);
                toast('Camera unavailable: ' + msg + ' — switching to manual', 'warn');
                setTimeout(openManualModal, 600);
            }
        );
    }

    // -----------------------------------------------------------------------
    // Tier 2: AI vision label reader
    // -----------------------------------------------------------------------
    function handleAiScanButton() {
        if (!B.ai_vision_enabled) {
            toast('AI vision disabled for this session', 'error');
            return;
        }
        // Use a hidden file input with environment camera capture
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.setAttribute('capture', 'environment');
        input.style.display = 'none';
        document.body.appendChild(input);

        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) {
                input.remove();
                return;
            }
            var file = input.files[0];
            input.remove();

            toast('Reading label…', 'info');
            var fd = new FormData();
            fd.append('csrf_token', B.csrf_token);
            fd.append('session_id', B.session_id);
            fd.append('file', file);

            fetch('api/audit/parse_label_with_ai.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); })
                .then(function (data) {
                    if (!data.success) {
                        toast('AI read failed: ' + (data.error || 'unknown') + ' — please enter manually', 'warn');
                        openManualModalWithAiPrefill(null, file, null);
                        return;
                    }
                    state.ai_vision_call_count++;
                    var aiCount = document.getElementById('aiCallCount');
                    if (aiCount) aiCount.textContent = state.ai_vision_call_count;

                    var f = data.fields || {};
                    var confidence = f.confidence !== undefined ? f.confidence : 0;
                    if (confidence < 0.6) {
                        toast('Low confidence — please verify', 'warn');
                    }
                    openManualModalWithAiPrefill(f, file, data);
                })
                .catch(function (err) {
                    toast('AI request failed: ' + err.message, 'error');
                    openManualModalWithAiPrefill(null, file, null);
                });
        });
        input.click();
    }

    // -----------------------------------------------------------------------
    // Tier 3: Manual entry modal
    // -----------------------------------------------------------------------
    var pendingAiPhoto = null;
    var pendingAiResponse = null;
    var editingScanId = null;

    function openManualModal() {
        pendingAiPhoto = null;
        pendingAiResponse = null;
        editingScanId = null;
        document.getElementById('m_raw_value').value = '';
        document.getElementById('m_wattage').value = '';
        document.getElementById('m_quantity').value = B.expected_modules_per_pallet || '';
        document.getElementById('m_notes').value = '';
        document.getElementById('m_label_unreadable').checked = false;
        showModal('manualModal');
    }

    function openManualModalWithAiPrefill(fields, photoFile, fullResponse) {
        pendingAiPhoto = photoFile || null;
        pendingAiResponse = fullResponse || null;
        editingScanId = null;
        document.getElementById('m_raw_value').value = (fields && fields.pallet_id) || '';
        document.getElementById('m_wattage').value = (fields && fields.wattage) || '';
        document.getElementById('m_quantity').value = (fields && fields.modules_per_pallet) || B.expected_modules_per_pallet || '';
        document.getElementById('m_notes').value = '';
        document.getElementById('m_label_unreadable').checked = false;
        showModal('manualModal');
    }

    function openManualModalForEdit(scan_id) {
        var scan = state.scans.find(function (s) { return s.id == scan_id; });
        if (!scan) return;
        pendingAiPhoto = null;
        pendingAiResponse = null;
        editingScanId = scan_id;
        document.getElementById('m_raw_value').value = scan.raw_value || '';
        document.getElementById('m_wattage').value = scan.parsed_wattage || '';
        document.getElementById('m_quantity').value = scan.quantity || '';
        document.getElementById('m_notes').value = scan.notes || '';
        document.getElementById('m_label_unreadable').checked = false;
        showModal('manualModal');
    }

    function submitManualEntry() {
        var raw_value = document.getElementById('m_raw_value').value.trim();
        if (!raw_value) { toast('Pallet ID required', 'error'); return; }
        var wattage = parseInt(document.getElementById('m_wattage').value, 10) || null;
        var quantity = parseInt(document.getElementById('m_quantity').value, 10) || null;
        var notes = document.getElementById('m_notes').value.trim();
        var unreadable = document.getElementById('m_label_unreadable').checked;

        var payload = {
            raw_value: raw_value,
            parsed_wattage: wattage,
            quantity: quantity,
            notes: notes,
            label_unreadable: unreadable ? 1 : 0,
            scan_method: pendingAiResponse ? 'ai_vision' : 'manual',
        };
        if (pendingAiResponse) {
            var fields = pendingAiResponse.fields || {};
            payload.ai_confidence = fields.confidence;
            payload.ai_raw_response = JSON.stringify(pendingAiResponse.raw_response || {});
            payload.supplier = fields.supplier || null;
            payload.equipment_code = fields.equipment_type || null;
        }
        if (editingScanId) {
            // Route through edit endpoint instead (so we don't duplicate)
            payload.scan_id = editingScanId;
            payload.csrf_token = B.csrf_token;
            payload.session_id = B.session_id;
            var fd = new FormData();
            Object.keys(payload).forEach(function (k) { if (payload[k] !== null && payload[k] !== undefined) fd.append(k, payload[k]); });
            fetch('api/audit/edit_scan.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { toast(data.error || 'Edit failed', 'error'); return; }
                    applyScanResponse(data);
                    toast('Scan updated', 'success');
                    closeModal('manualModal');
                })
                .catch(function (err) { toast('Edit failed: ' + err.message, 'error'); });
            return;
        }

        submitScan(payload).then(function (data) {
            closeModal('manualModal');
            // If there's a pending AI photo, upload it to the scan we just created
            if (pendingAiPhoto && data && data.scan) {
                var fd = new FormData();
                fd.append('csrf_token', B.csrf_token);
                fd.append('session_id', B.session_id);
                fd.append('scan_id', data.scan.id);
                fd.append('photo_role', 'label');
                fd.append('file', pendingAiPhoto);
                window.AuditOffline.enqueuePhoto(fd).catch(function (err) {
                    toast('Photo upload failed: ' + err.message, 'warn');
                });
                pendingAiPhoto = null;
                pendingAiResponse = null;
            }
        });
    }

    // -----------------------------------------------------------------------
    // Photo modal
    // -----------------------------------------------------------------------
    var photoModalScanId = null;
    function openPhotoModal(scan_id) {
        photoModalScanId = scan_id || null;
        document.getElementById('p_file').value = '';
        document.getElementById('p_caption').value = '';
        document.getElementById('p_role').value = scan_id ? 'label' : 'context';
        showModal('photoModal');
    }

    function submitPhoto() {
        var file = document.getElementById('p_file').files[0];
        if (!file) { toast('Choose a photo', 'error'); return; }
        var role = document.getElementById('p_role').value;
        var caption = document.getElementById('p_caption').value.trim();

        var fd = new FormData();
        fd.append('csrf_token', B.csrf_token);
        fd.append('session_id', B.session_id);
        if (photoModalScanId) fd.append('scan_id', photoModalScanId);
        fd.append('photo_role', role);
        if (caption) fd.append('caption', caption);
        fd.append('file', file);

        toast('Uploading photo…', 'info');
        window.AuditOffline.enqueuePhoto(fd)
            .then(function () {
                toast('Photo uploaded', 'success');
                closeModal('photoModal');
            })
            .catch(function (err) {
                toast('Upload failed: ' + err.message, 'error');
            });
    }

    // -----------------------------------------------------------------------
    // Equipment modal
    // -----------------------------------------------------------------------
    var editingEqId = null;
    function openEquipmentModal(eq_id) {
        editingEqId = eq_id || null;
        var eq = eq_id ? state.equipment.find(function (e) { return e.id == eq_id; }) : null;
        document.getElementById('eq_id').value = eq_id || '';
        document.getElementById('eq_type').value   = eq ? (eq.equipment_type    || '') : '';
        document.getElementById('eq_mfg').value    = eq ? (eq.manufacturer_name || '') : '';
        document.getElementById('eq_model').value  = eq ? (eq.model_number      || '') : '';
        document.getElementById('eq_serial').value = eq ? (eq.serial_number     || '') : '';
        document.getElementById('eq_tag').value    = eq ? (eq.asset_tag         || '') : '';
        document.getElementById('eq_status').value = eq ? (eq.status            || 'not_verified') : 'not_verified';
        document.getElementById('eq_notes').value  = eq ? (eq.notes             || '') : '';

        // Reset staged photo inputs and show existing photo count
        document.getElementById('eq_tag_photo').value = '';
        document.getElementById('eq_overview_photo').value = '';
        var existingCount = (eq_id && state.photo_counts && state.photo_counts.equipment && state.photo_counts.equipment[eq_id]) || 0;
        var existingEl = document.getElementById('eqExistingPhotos');
        if (existingEl) {
            existingEl.innerHTML = existingCount > 0
                ? '<div class="eq-existing-photos-note">📷 ' + existingCount + ' photo(s) already attached</div>'
                : '';
        }

        showModal('equipmentModal');
    }

    function submitEquipment() {
        var fd = new FormData();
        fd.append('csrf_token', B.csrf_token);
        fd.append('session_id', B.session_id);
        if (editingEqId) fd.append('id', editingEqId);
        fd.append('equipment_type',    document.getElementById('eq_type').value.trim());
        fd.append('manufacturer_name', document.getElementById('eq_mfg').value.trim());
        fd.append('model_number',      document.getElementById('eq_model').value.trim());
        fd.append('serial_number',     document.getElementById('eq_serial').value.trim());
        fd.append('asset_tag',         document.getElementById('eq_tag').value.trim());
        fd.append('status',            document.getElementById('eq_status').value);
        fd.append('notes',             document.getElementById('eq_notes').value.trim());

        var submitBtn = document.getElementById('btnSubmitEquipment');
        submitBtn.disabled = true;

        fetch('api/audit/update_equipment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    toast(data.error || 'Save failed', 'error');
                    submitBtn.disabled = false;
                    return;
                }
                applyEquipmentResponse(data.equipment);
                toast('Equipment saved', 'success');

                // If photos were staged, upload them with the freshly-saved equipment_check_id
                var equipment_check_id = data.equipment && data.equipment.id;
                var tagFile = document.getElementById('eq_tag_photo').files[0];
                var overviewFile = document.getElementById('eq_overview_photo').files[0];
                var uploads = [];
                if (equipment_check_id && tagFile) {
                    uploads.push(uploadEquipmentPhoto(equipment_check_id, tagFile, 'equipment_tag')
                        .then(function () { bumpEquipmentPhotoCount(equipment_check_id, 1); })
                        .catch(function (err) { toast('Tag photo upload failed: ' + err.message, 'warn'); }));
                }
                if (equipment_check_id && overviewFile) {
                    uploads.push(uploadEquipmentPhoto(equipment_check_id, overviewFile, 'equipment_overview')
                        .then(function () { bumpEquipmentPhotoCount(equipment_check_id, 1); })
                        .catch(function (err) { toast('Overview photo upload failed: ' + err.message, 'warn'); }));
                }

                Promise.all(uploads).then(function () {
                    submitBtn.disabled = false;
                    closeModal('equipmentModal');
                    if (uploads.length > 0) {
                        toast('Photos attached', 'success');
                    }
                });
            })
            .catch(function (err) {
                toast('Save failed: ' + err.message, 'error');
                submitBtn.disabled = false;
            });
    }

    // -----------------------------------------------------------------------
    // Modal helpers
    // -----------------------------------------------------------------------
    function showModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.add('visible');
        el.setAttribute('aria-hidden', 'false');
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('visible');
        el.setAttribute('aria-hidden', 'true');
    }

    // -----------------------------------------------------------------------
    // Keepalive (PHP session) ping every 120s
    // -----------------------------------------------------------------------
    function startKeepalive() {
        setInterval(function () {
            fetch('api/audit/keepalive.php?session_id=' + B.session_id, {
                method: 'GET', credentials: 'same-origin'
            }).catch(function () { /* ignore */ });
        }, 120000);
    }

    // -----------------------------------------------------------------------
    // Offline queue pill
    // -----------------------------------------------------------------------
    function initOfflinePill() {
        if (!window.AuditOffline) return;
        window.AuditOffline.onStatusChange(function (status) {
            var pill = document.getElementById('offlinePill');
            if (!pill) return;
            if (status.pending === 0 && status.failed === 0) {
                pill.className = 'offline-pill synced';
                pill.textContent = '● Synced';
            } else if (status.failed > 0) {
                pill.className = 'offline-pill failed';
                pill.textContent = '● ' + status.failed + ' failed';
            } else {
                pill.className = 'offline-pill pending';
                pill.textContent = '● ' + status.pending + ' pending';
            }
        });
    }

    // -----------------------------------------------------------------------
    // Quick wattage selection
    // -----------------------------------------------------------------------
    function initQuickWattage() {
        document.querySelectorAll('.quick-wattage-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var w = parseInt(chip.getAttribute('data-wattage'), 10);
                if (state.quick_wattage === w) {
                    state.quick_wattage = null;
                    chip.classList.remove('selected');
                } else {
                    state.quick_wattage = w;
                    document.querySelectorAll('.quick-wattage-chip').forEach(function (c) { c.classList.remove('selected'); });
                    chip.classList.add('selected');
                }
            });
        });
    }

    // -----------------------------------------------------------------------
    // Wire up event listeners and initial render
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initQuickWattage();
        initOfflinePill();

        document.getElementById('btnScanBarcode').addEventListener('click', handleBarcodeScanButton);
        document.getElementById('btnScanAi').addEventListener('click', handleAiScanButton);
        document.getElementById('btnManualEntry').addEventListener('click', openManualModal);
        document.getElementById('btnSubmitManual').addEventListener('click', submitManualEntry);
        document.getElementById('btnSubmitPhoto').addEventListener('click', submitPhoto);
        document.getElementById('btnSubmitEquipment').addEventListener('click', submitEquipment);
        document.getElementById('btnGoToReview').addEventListener('click', function () {
            window.location.href = 'warehouse_audit_review?s=' + B.session_id;
        });

        document.getElementById('scanSearchInput').addEventListener('input', renderScanList);
        document.getElementById('scanFilter').addEventListener('change', renderScanList);

        // Expose for inline onclick="AuditSession.closeModal(...)"
        window.AuditSession = { closeModal: closeModal, showModal: showModal };

        // Initial render
        updateCounters();
        renderScanList();
        renderEquipmentList();

        // Keepalive
        startKeepalive();
    });
})();

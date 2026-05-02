/* EasySender — Bulk CSV Upload (Test Email tab) */
(function () {
    'use strict';

    /* ---- Helpers ---- */
    function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function formatNumber(n) { return Number(n).toLocaleString(); }
    function $(id) { return document.getElementById(id); }

    /* ---- Single-email test (reuses existing easysenderTestData nonce) ---- */
    var singleBtn   = $('esb-single-btn');
    var singleInput = $('esb-single-input');
    var singleArea  = $('esb-single-result');
    if (singleBtn && singleInput && singleArea) {
        function doSingleTest() {
            var email = singleInput.value.trim();
            if (!email) { singleInput.focus(); return; }
            singleBtn.disabled = true;
            singleBtn.textContent = '\u23F3 Verifying\u2026';
            singleArea.innerHTML = '<span style="color:var(--ed-text-tertiary);font-size:12px;">Checking\u2026</span>';

            var params = new URLSearchParams({
                action: 'easysender_test_email',
                _wpnonce: easysenderTestData.nonce,
                email: email
            });

            fetch(ajaxurl, { method:'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:params })
            .then(function(r){return r.json();})
            .then(function(data){
                if (!data || !data.success) {
                    var msg = (data&&data.data&&data.data.message) ? data.data.message : 'Verification failed.';
                    singleArea.innerHTML = '<div class="esb-result-row"><span class="esb-result-email">'+esc(email)+'</span><span class="esb-status-pill esb-pill-undeliverable">\u2717 '+esc(msg)+'</span></div>';
                    return;
                }
                var d = data.data;
                var st = (d.status||'unknown').toLowerCase();
                var pillClass = 'esb-pill-undeliverable', pillLabel = '\u2717 Undeliverable';
                if (st==='deliverable') { pillClass='esb-pill-deliverable'; pillLabel='\u2713 Deliverable'; }
                else if (st==='risky')  { pillClass='esb-pill-risky'; pillLabel='\u26A0 Risky'; }
                else if (st==='unknown'){ pillClass='esb-pill-unknown'; pillLabel='? Unknown'; }

                var details = d.details || {};
                var typeLabel = st.charAt(0).toUpperCase() + st.slice(1);
                if (st === 'invalid_format') typeLabel = 'Invalid Format';
                var mxActive = 'Unknown';
                if (details.mx_found !== undefined) mxActive = details.mx_found ? 'Active' : 'No MX';
                else if (details.has_mx !== undefined) mxActive = details.has_mx ? 'Active' : 'No MX';
                else if (st==='deliverable'||st==='risky') mxActive = 'Active';
                else if (st==='undeliverable') mxActive = 'No MX';
                var spamTrap = 'Unknown';
                if (details.spam_trap !== undefined) spamTrap = details.spam_trap === 'clean' ? 'Clean' : (details.spam_trap === 'flagged' ? 'Flagged' : 'Unknown');
                var disposable = 'Unknown';
                if (details.disposable !== undefined) disposable = details.disposable ? 'Yes' : 'No';

                function valClass(v) {
                    if (['Deliverable','Active','Clean','No'].indexOf(v)!==-1) return 'esb-detail-positive';
                    if (['Undeliverable','Invalid Format','No MX','Flagged','Yes'].indexOf(v)!==-1) return 'esb-detail-negative';
                    if (['Risky'].indexOf(v)!==-1) return 'esb-detail-warning';
                    return 'esb-detail-neutral';
                }

                var html = '<div class="esb-result-row"><span class="esb-result-email">'+esc(email)+'</span><span class="esb-status-pill '+pillClass+'">'+pillLabel+'</span></div>';
                html += '<div class="esb-detail-grid">';
                html += '<div class="esb-detail-item"><div class="esb-detail-label">Type</div><div class="esb-detail-value '+valClass(typeLabel)+'">'+typeLabel+'</div></div>';
                html += '<div class="esb-detail-item"><div class="esb-detail-label">Domain MX</div><div class="esb-detail-value '+valClass(mxActive)+'">'+mxActive+'</div></div>';
                html += '<div class="esb-detail-item"><div class="esb-detail-label">Spam Trap</div><div class="esb-detail-value '+valClass(spamTrap)+'">'+spamTrap+'</div></div>';
                html += '<div class="esb-detail-item"><div class="esb-detail-label">Disposable</div><div class="esb-detail-value '+valClass(disposable)+'">'+disposable+'</div></div>';
                html += '</div>';
                singleArea.innerHTML = html;
            })
            .catch(function(){
                singleArea.innerHTML = '<span class="esb-status-pill esb-pill-undeliverable">\u2717 Network error</span>';
            })
            .finally(function(){ singleBtn.disabled=false; singleBtn.textContent='Verify Email'; });
        }
        singleBtn.addEventListener('click', doSingleTest);
        singleInput.addEventListener('keydown', function(e){ if(e.key==='Enter'){e.preventDefault();doSingleTest();} });
    }

    /* ---- Bulk CSV Upload ---- */
    var dropzone     = $('esb-dropzone');
    var fileInput    = $('esb-file-input');
    var previewWrap  = $('esb-preview-wrap');
    var progressWrap = $('esb-progress-wrap');
    var resultsWrap  = $('esb-results-wrap');
    var dzError      = $('esb-dropzone-error');
    if (!dropzone || !fileInput) return;

    var bulkNonce = (typeof easysenderBulkData !== 'undefined') ? easysenderBulkData.nonce : '';
    var currentJob = null;
    var currentMode = 'header';

    /* Mode selector */
    document.querySelectorAll('.esb-mode-card').forEach(function(card){
        card.addEventListener('click', function(){
            document.querySelectorAll('.esb-mode-card').forEach(function(c){c.classList.remove('active');});
            card.classList.add('active');
            currentMode = card.getAttribute('data-mode') || 'header';
        });
    });

    /* Drop zone interaction */
    dropzone.setAttribute('tabindex','0');
    dropzone.setAttribute('role','button');
    dropzone.addEventListener('click', function(){ fileInput.click(); });
    dropzone.addEventListener('keydown', function(e){
        if (e.key==='Enter'||e.key===' '){ e.preventDefault(); fileInput.click(); }
    });
    dropzone.addEventListener('dragover', function(e){ e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', function(){ dropzone.classList.remove('drag-over'); });
    dropzone.addEventListener('drop', function(e){
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) handleFile(e.dataTransfer.files[0]);
    });
    fileInput.addEventListener('change', function(){
        if (fileInput.files.length) handleFile(fileInput.files[0]);
    });

    function showError(msg) {
        if (dzError) { dzError.textContent = msg; dzError.style.display = 'block'; }
    }
    function clearError() {
        if (dzError) { dzError.textContent = ''; dzError.style.display = 'none'; }
    }

    function handleFile(file) {
        clearError();
        if (!file.name.toLowerCase().endsWith('.csv')) {
            showError('Only CSV files are supported. Please upload a .csv file.');
            return;
        }
        if (file.size > 10*1024*1024) {
            showError('File exceeds the 10 MB size limit.');
            return;
        }
        uploadFile(file);
    }

    function uploadFile(file) {
        var fd = new FormData();
        fd.append('action', 'easysender_bulk_upload_csv');
        fd.append('_wpnonce', bulkNonce);
        fd.append('csv_file', file);
        fd.append('mode', currentMode);

        dropzone.style.display = 'none';
        if (previewWrap) previewWrap.innerHTML = '<div style="padding:16px;text-align:center;"><span class="esb-skeleton" style="display:inline-block;width:200px;height:16px;"></span></div>';
        if (previewWrap) previewWrap.style.display = 'block';

        fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:fd })
        .then(function(r){return r.json();})
        .then(function(data){
            if (!data||!data.success) {
                var msg = (data&&data.data&&data.data.message)||'Upload failed.';
                resetToInitial();
                showError(msg);
                return;
            }
            currentJob = data.data;
            renderPreview(currentJob, file);
        })
        .catch(function(){
            resetToInitial();
            showError('Network error during upload.');
        });
    }

    function renderPreview(job, file) {
        var fileSizeKB = file ? Math.round(file.size/1024) : 0;
        var html = '';
        // File bar
        html += '<div class="esb-file-bar">';
        html += '<span class="esb-file-bar-icon">\u2714</span>';
        html += '<span class="esb-file-bar-name">'+esc(job.job_id ? (file?file.name:'uploaded.csv') : '')+'</span>';
        html += '<span class="esb-file-bar-meta">'+formatNumber(job.total_emails)+' email addresses detected \u00B7 '+formatNumber(fileSizeKB)+' KB</span>';
        html += '<button type="button" class="esb-file-bar-remove" aria-label="Remove file" id="esb-remove-file">\u2715</button>';
        html += '</div>';

        // Preview table
        html += '<table class="esb-preview-table"><thead><tr><th>#</th><th>Email Address</th><th>Status</th></tr></thead><tbody>';
        (job.preview||[]).forEach(function(r){
            html += '<tr><td>'+r.row+'</td><td class="email-cell">'+esc(r.email)+'</td><td><span class="esb-status-pill esb-pill-pending">\u23F8 Pending</span></td></tr>';
        });
        html += '</tbody></table>';
        var remaining = job.total_emails - (job.preview||[]).length;
        if (remaining > 0) html += '<div class="esb-preview-more">+ '+formatNumber(remaining)+' more emails not shown</div>';
        html += '<div class="esb-credit-note">\u2248 '+formatNumber(job.total_emails)+' credits will be used</div>';

        // Action row
        html += '<div class="esb-action-row">';
        html += '<button type="button" class="esb-btn esb-btn-primary" id="esb-start-verify">\u25B6 Start Bulk Verification</button>';
        html += '<button type="button" class="esb-btn esb-btn-secondary" id="esb-remove-file-2">Remove File</button>';
        html += '<span class="esb-credit-note" style="margin-left:auto;">Uses '+formatNumber(job.total_emails)+' credits</span>';
        html += '</div>';

        if (previewWrap) { previewWrap.innerHTML = html; previewWrap.style.display = 'block'; }

        // Bind events
        var removeBtn = $('esb-remove-file');
        var removeBtn2 = $('esb-remove-file-2');
        var startBtn = $('esb-start-verify');
        if (removeBtn) removeBtn.addEventListener('click', resetToInitial);
        if (removeBtn2) removeBtn2.addEventListener('click', resetToInitial);
        if (startBtn) startBtn.addEventListener('click', function(){ startVerification(job.job_id, job.total_emails); });
    }

    function startVerification(jobId, total) {
        var startBtn = $('esb-start-verify');
        if (startBtn) startBtn.disabled = true;

        if (progressWrap) {
            progressWrap.innerHTML =
                '<div class="esb-progress-labels"><span id="esb-prog-left">Verifying 0 of '+formatNumber(total)+' emails\u2026</span><span id="esb-prog-right">0%</span></div>'+
                '<div class="esb-progress-bar"><div class="esb-progress-fill" id="esb-prog-fill" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>';
            progressWrap.style.display = 'block';
        }

        processChunk(jobId, total);
    }

    function processChunk(jobId, total) {
        var params = new URLSearchParams({
            action: 'easysender_bulk_verify_chunk',
            _wpnonce: bulkNonce,
            job_id: jobId
        });

        fetch(ajaxurl, { method:'POST', credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body:params })
        .then(function(r){return r.json();})
        .then(function(data){
            if (!data||!data.success) {
                var errMsg = (data&&data.data&&data.data.message)||'Verification failed.';
                var isQuota = data&&data.data&&data.data.error==='credit_quota';
                if (progressWrap) {
                    progressWrap.innerHTML += '<div style="color:var(--ed-error-text);margin-top:8px;font-size:13px;">'+(isQuota?'\u26A0 Credit quota exhausted. Verification stopped.':esc(errMsg))+'</div>';
                    progressWrap.innerHTML += '<div style="margin-top:8px;"><button type="button" class="esb-btn esb-btn-secondary" onclick="document.getElementById(\'esb-reset-trigger\').click();">\u21BA Verify Another File</button></div>';
                }
                return;
            }
            var d = data.data;
            var pct = Math.round((d.progress||0)*100);
            var fill = $('esb-prog-fill');
            var left = $('esb-prog-left');
            var right= $('esb-prog-right');
            if (fill) { fill.style.width = pct+'%'; fill.setAttribute('aria-valuenow',pct); }
            if (left) left.textContent = 'Verifying '+formatNumber(d.cursor)+' of '+formatNumber(total)+' emails\u2026';
            if (right) right.textContent = pct+'%';

            if (d.complete) {
                renderResults(d.breakdown, jobId);
            } else {
                setTimeout(function(){ processChunk(jobId, total); }, 100);
            }
        })
        .catch(function(){
            if (progressWrap) progressWrap.innerHTML += '<div style="color:var(--ed-error-text);margin-top:8px;font-size:13px;">Network error during verification.</div>';
        });
    }

    /* ---- Results View ---- */
    function renderResults(breakdown, jobId) {
        if (previewWrap) previewWrap.style.display = 'none';
        if (progressWrap) progressWrap.style.display = 'none';
        if (!resultsWrap) return;

        var b = breakdown;
        var total = b.total || 1;

        // Header
        var html = '<div class="esb-results-header"><div class="esb-results-meta">';
        html += '<strong>'+esc(b.filename)+'</strong><br>';
        html += 'Verified on '+esc(b.verified_on)+'<br>';
        html += 'Total verified emails: <strong>'+formatNumber(b.total)+'</strong><br>';
        html += 'Duplicates removed: <strong>'+formatNumber(b.duplicates_removed)+'</strong>';
        html += '</div><div style="color:#B45309;font-size:14px;font-weight:600;">Deliverable rate: '+b.deliverable_rate_pct+'%</div></div>';

        // Donut chart + legend
        var segments = [
            { label:'Deliverable', count:b.deliverable, color:'#86EFAC' },
            { label:'Risky', count:b.risky, color:'#93C5FD' },
            { label:'Undeliverable', count:b.undeliverable, color:'#9CA3AF' },
            { label:'Unknown', count:b.unknown, color:'#A78BFA' },
        ];

        html += '<div class="esb-results-chart-wrap">';
        html += '<div class="esb-donut-container"><svg class="esb-donut-svg" viewBox="0 0 200 200">'+buildDonut(segments, total)+'</svg>';
        html += '<div class="esb-donut-center"><div class="esb-donut-center-num">'+formatNumber(total)+'</div><div class="esb-donut-center-label">total</div></div></div>';

        html += '<div class="esb-legend">';
        segments.forEach(function(s){
            html += '<div class="esb-legend-item"><div class="esb-legend-dot" style="background:'+s.color+';"></div><span class="esb-legend-count">'+formatNumber(s.count)+'</span><span class="esb-legend-label">'+s.label+'</span></div>';
        });
        html += '</div></div>';

        // Breakdown grid
        html += '<div class="esb-breakdown-grid">';
        html += '<div><div class="esb-breakdown-col-title amber">RISKY</div>';
        html += '<div class="esb-breakdown-row"><span>Free account</span><span class="esb-breakdown-val">'+formatNumber(b.free_account)+'</span></div>';
        html += '<div class="esb-breakdown-row"><span>Role account</span><span class="esb-breakdown-val">'+formatNumber(b.role_account)+'</span></div></div>';
        html += '<div><div class="esb-breakdown-col-title purple">UNKNOWN</div>';
        html += '<div class="esb-breakdown-row"><span>Disposable</span><span class="esb-breakdown-val">'+formatNumber(b.disposable)+'</span></div>';
        html += '<div class="esb-breakdown-row"><span>Full Inbox</span><span class="esb-breakdown-val">'+formatNumber(b.full_inbox)+'</span></div></div>';
        html += '</div>';

        // Action row
        html += '<div class="esb-action-row">';
        html += '<button type="button" class="esb-btn esb-btn-primary" id="esb-export-btn">\u2193 Export Results</button>';
        html += '<button type="button" class="esb-btn esb-btn-secondary" id="esb-reset-trigger">\u21BA Verify Another File</button>';
        html += '</div>';

        resultsWrap.innerHTML = html;
        resultsWrap.style.display = 'block';

        // Store breakdown for export modal
        resultsWrap._breakdown = b;
        resultsWrap._jobId = jobId;

        $('esb-export-btn').addEventListener('click', function(){ openExportModal(b, jobId); });
        $('esb-reset-trigger').addEventListener('click', resetToInitial);
    }

    /* SVG Donut */
    function buildDonut(segments, total) {
        if (total<=0) return '';
        var cx=100, cy=100, r=75, svg='';
        var startAngle = -90;
        segments.forEach(function(seg){
            if (seg.count<=0) return;
            var angle = (seg.count/total)*360;
            var endAngle = startAngle + angle;
            var largeArc = angle > 180 ? 1 : 0;
            var x1 = cx + r * Math.cos(startAngle * Math.PI/180);
            var y1 = cy + r * Math.sin(startAngle * Math.PI/180);
            var x2 = cx + r * Math.cos(endAngle * Math.PI/180);
            var y2 = cy + r * Math.sin(endAngle * Math.PI/180);

            if (angle >= 359.99) {
                // Full circle
                svg += '<circle cx="'+cx+'" cy="'+cy+'" r="'+r+'" fill="none" stroke="'+seg.color+'" stroke-width="25"/>';
            } else {
                svg += '<path d="M'+x1+' '+y1+' A'+r+' '+r+' 0 '+largeArc+' 1 '+x2+' '+y2+'" fill="none" stroke="'+seg.color+'" stroke-width="25"/>';
            }

            // Label if > 8%
            if (seg.count/total > 0.08) {
                var midAngle = startAngle + angle/2;
                var lx = cx + (r-2) * Math.cos(midAngle * Math.PI/180);
                var ly = cy + (r-2) * Math.sin(midAngle * Math.PI/180);
                var pct = Math.round(seg.count/total*100);
                svg += '<text x="'+lx+'" y="'+ly+'" text-anchor="middle" dominant-baseline="central" fill="#333" font-size="11" font-weight="600">'+pct+'%</text>';
            }
            startAngle = endAngle;
        });
        // Inner circle (hole)
        svg += '<circle cx="'+cx+'" cy="'+cy+'" r="50" fill="white"/>';
        return svg;
    }

    /* ---- Export Modal ---- */
    function openExportModal(breakdown, jobId) {
        var b = breakdown;
        var overlay = document.createElement('div');
        overlay.className = 'esb-modal-overlay';
        overlay.id = 'esb-export-overlay';

        var html = '<div class="esb-modal" role="dialog" aria-modal="true" aria-label="Export Verification Results">';
        html += '<div class="esb-modal-header"><span class="esb-modal-title">Export Verification Results</span><button type="button" class="esb-modal-close" aria-label="Close" id="esb-modal-close">\u2715</button></div>';
        html += '<div class="esb-modal-body">';
        html += '<div class="esb-modal-desc">Choose which email categories to include in your exported CSV. Sub-categories let you get even more specific.</div>';
        html += '<div class="esb-modal-section-label">Categories to export</div>';

        // Deliverable
        html += filterItem('deliverable', '\u2713 Deliverable', 'Confirmed valid, inbox-ready email addresses.', b.deliverable, 'green', true, []);
        // Risky
        html += filterItem('risky', '\u26A0 Risky', 'Address exists but carries deliverability risk.', b.risky, 'amber', true, [
            { id:'risky_free_account', label:'Free account (Gmail, Yahoo, etc.)', count:b.free_account, checked:true },
            { id:'risky_role_account', label:'Role account (info@, support@, etc.)', count:b.role_account, checked:true },
        ]);
        // Undeliverable
        html += filterItem('undeliverable', '\u2717 Undeliverable', 'Invalid addresses that will bounce. Safe to remove.', b.undeliverable, 'red', false, []);
        // Unknown
        html += filterItem('unknown', '? Unknown', 'Could not be fully verified. Review before sending.', b.unknown, 'purple', false, [
            { id:'unknown_disposable', label:'Disposable / temporary email', count:b.disposable, checked:false },
            { id:'unknown_full_inbox', label:'Full inbox \u2014 could not verify', count:b.full_inbox, checked:false },
        ]);

        html += '</div>';
        html += '<div class="esb-modal-footer"><span class="esb-modal-footer-note" id="esb-export-count">Exporting '+formatNumber(b.deliverable+b.risky)+' emails</span><div style="display:flex;gap:8px;">';
        html += '<button type="button" class="esb-btn esb-btn-secondary" id="esb-modal-cancel">Cancel</button>';
        html += '<button type="button" class="esb-btn esb-btn-primary" id="esb-download-btn">\u2193 Download CSV</button>';
        html += '</div></div>';
        html += '</div>';

        overlay.innerHTML = html;
        document.body.appendChild(overlay);

        // Focus trap
        var modal = overlay.querySelector('.esb-modal');
        var focusable = modal.querySelectorAll('button, input, [tabindex]');
        if (focusable.length) focusable[0].focus();

        function closeModal() { overlay.remove(); }
        $('esb-modal-close').addEventListener('click', closeModal);
        $('esb-modal-cancel').addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e){ if(e.target===overlay) closeModal(); });
        overlay.addEventListener('keydown', function(e){
            if (e.key==='Escape') closeModal();
            // Trap focus
            if (e.key==='Tab' && focusable.length) {
                var first=focusable[0], last=focusable[focusable.length-1];
                if (e.shiftKey && document.activeElement===first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement===last) { e.preventDefault(); first.focus(); }
            }
        });

        // Checkbox logic
        function updateCount() {
            var total = 0;
            var cb = function(id){ var el=overlay.querySelector('#esb-filter-'+id); return el && el.checked; };
            if (cb('deliverable')) total += b.deliverable;
            if (cb('risky')) {
                total += b.risky;
                if (!cb('risky_free_account')) total -= b.free_account;
                if (!cb('risky_role_account')) total -= b.role_account;
            }
            if (cb('undeliverable')) total += b.undeliverable;
            if (cb('unknown')) {
                total += b.unknown;
                if (!cb('unknown_disposable')) total -= b.disposable;
                if (!cb('unknown_full_inbox')) total -= b.full_inbox;
            }
            if (total<0) total=0;
            var countEl = $('esb-export-count');
            if (countEl) countEl.textContent = 'Exporting '+formatNumber(total)+' emails';
            var dlBtn = $('esb-download-btn');
            if (dlBtn) dlBtn.disabled = (total===0);
        }

        overlay.querySelectorAll('input[type="checkbox"]').forEach(function(cb){
            cb.addEventListener('change', function(){
                // Toggle sub-filters visibility
                var item = cb.closest('.esb-filter-item');
                if (item) {
                    var sub = item.nextElementSibling;
                    if (sub && sub.classList.contains('esb-sub-filters')) {
                        sub.style.display = cb.checked ? 'block' : 'none';
                    }
                    item.classList.toggle('checked', cb.checked);
                }
                updateCount();
            });
        });
        updateCount();

        // Download
        $('esb-download-btn').addEventListener('click', function(){
            var dlBtn = $('esb-download-btn');
            dlBtn.disabled = true;
            dlBtn.textContent = '\u2713 Downloading\u2026';

            var include = {};
            overlay.querySelectorAll('input[type="checkbox"]').forEach(function(cb){
                include[cb.id.replace('esb-filter-','')] = cb.checked;
            });

            // Use a form submission for file download
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = ajaxurl;
            form.style.display = 'none';

            function addField(name, val) {
                var inp = document.createElement('input');
                inp.type='hidden'; inp.name=name; inp.value=val;
                form.appendChild(inp);
            }
            addField('action', 'easysender_bulk_export_csv');
            addField('_wpnonce', bulkNonce);
            addField('job_id', jobId);
            addField('include', JSON.stringify(include));

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);

            setTimeout(function(){
                dlBtn.disabled = false;
                dlBtn.textContent = '\u2193 Download CSV';
                closeModal();
            }, 1500);
        });
    }

    function filterItem(id, label, sublabel, count, colorClass, checked, subs) {
        var html = '<div class="esb-filter-item'+(checked?' checked':'')+'">';
        html += '<input type="checkbox" id="esb-filter-'+id+'"'+(checked?' checked':'')+'>';
        html += '<div class="esb-filter-content"><div class="esb-filter-label">'+label+'</div><div class="esb-filter-sublabel">'+esc(sublabel)+'</div></div>';
        html += '<span class="esb-filter-count esb-filter-count-'+colorClass+'">'+formatNumber(count)+'</span>';
        html += '</div>';
        if (subs.length) {
            html += '<div class="esb-sub-filters" style="display:'+(checked?'block':'none')+'">';
            subs.forEach(function(s){
                html += '<label class="esb-sub-filter"><input type="checkbox" id="esb-filter-'+s.id+'"'+(s.checked?' checked':'')+'> '+esc(s.label)+' <span class="esb-sub-filter-count">'+formatNumber(s.count)+'</span></label>';
            });
            html += '</div>';
        }
        return html;
    }

    /* ---- Reset ---- */
    function resetToInitial() {
        currentJob = null;
        fileInput.value = '';
        clearError();
        if (dropzone) dropzone.style.display = '';
        if (previewWrap) { previewWrap.innerHTML = ''; previewWrap.style.display = 'none'; }
        if (progressWrap) { progressWrap.innerHTML = ''; progressWrap.style.display = 'none'; }
        if (resultsWrap) { resultsWrap.innerHTML = ''; resultsWrap.style.display = 'none'; }
    }
})();

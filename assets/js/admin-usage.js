/* EasyDMARC — Usage tab: auto-load credit stats */
(function () {
    var block = document.getElementById('easysender-usage-block');
    var tiles = document.getElementById('easysender-stat-tiles');
    if (!block) return;

    function fmt(n) { return Number(n || 0).toLocaleString(); }

    fetch(ajaxurl, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body:        new URLSearchParams({ action: 'easysender_get_usage', _wpnonce: easysenderUsageData.nonce })
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success) throw new Error((data && data.data && data.data.message) || 'Failed to load usage');

            var allocated = Number(data.data.allocated || 0);
            var balance   = Number(data.data.balance   || 0);
            var spent     = Number(data.data.spent     || 0);
            var total     = Number(data.data.total     || Math.max(allocated, balance + spent) || allocated);
            var pct       = Math.max(0, Math.min(100, Number(data.data.pct || 0)));
            var quota     = !!data.data.quota;

            // Stat tiles
            if (tiles) {
                var pctLabel = total > 0 ? ((spent / total) * 100).toFixed(2) + '% of plan' : '0% of plan';
                tiles.innerHTML =
                    '<div class="es-stat"><span class="es-stat__label">Allocated this period</span><span class="es-stat__value">' + fmt(allocated) + '</span><span class="es-stat__meta">Monthly</span></div>' +
                    '<div class="es-stat"><span class="es-stat__label">Used</span><span class="es-stat__value">' + fmt(spent) + '</span><span class="es-stat__meta"><svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="#059669" stroke-width="1.6" stroke-linecap="round"><path d="M3 7l3 3 3-7"/></svg>' + pctLabel + '</span></div>' +
                    '<div class="es-stat"><span class="es-stat__label">Remaining</span><span class="es-stat__value" style="color: var(--es-primary, #4F46E5);">' + fmt(balance) + '</span><span class="es-stat__meta">' + (quota ? 'Quota reached' : 'Active') + '</span></div>';
            }

            // Donut chart
            var r2    = 48;
            var circ  = +(2 * Math.PI * r2).toFixed(2);
            var usedArc = +((pct / 100) * circ).toFixed(2);

            var color = quota ? '#ef4444' : (pct >= 90 ? '#ef4444' : pct >= 75 ? '#f59e0b' : 'var(--es-primary, #4F46E5)');

            block.innerHTML =
                '<div style="display: grid; grid-template-columns: 220px 1fr; gap: 32px; align-items: center;">' +
                    '<div style="display: grid; place-items: center; position: relative;">' +
                        '<svg viewBox="0 0 120 120" width="200" height="200">' +
                            '<circle cx="60" cy="60" r="48" fill="none" stroke="var(--es-neutral-50,#F1F5F9)" stroke-width="14"/>' +
                            '<circle cx="60" cy="60" r="48" fill="none" stroke="' + color + '" stroke-width="14" stroke-dasharray="' + usedArc + ' ' + circ + '" stroke-linecap="round" transform="rotate(-90 60 60)"/>' +
                        '</svg>' +
                        '<div style="position: absolute; text-align: center;">' +
                            '<div style="font-size: 28px; font-weight: 700; letter-spacing: -0.02em; font-variant-numeric: tabular-nums;">' + fmt(balance) + '</div>' +
                            '<div style="font-size: 12px; color: var(--es-text-2, #475569);">credits left</div>' +
                        '</div>' +
                    '</div>' +
                    '<div>' +
                        '<div style="display: grid; gap: 14px;">' +
                            '<div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:999px;background:' + color + ';margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span>Available</span><span style="font-variant-numeric:tabular-nums;">' + fmt(balance) + '</span></div><div style="font-size:12.5px;color:var(--es-text-2,#475569);">Use for email verification across all enabled forms.</div></div></div>' +
                            '<div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:999px;background:var(--es-text-3,#94A3B8);margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span>Used</span><span style="font-variant-numeric:tabular-nums;">' + fmt(spent) + '</span></div><div style="font-size:12.5px;color:var(--es-text-2,#475569);">Verifications sent in the current billing cycle.</div></div></div>' +
                            '<div style="display: flex; gap: 12px; align-items: flex-start;"><span style="width:10px;height:10px;border-radius:2px;background:var(--es-neutral-100,#E2E8F0);margin-top:6px;flex-shrink:0;"></span><div style="flex:1;"><div style="display:flex;justify-content:space-between;font-weight:600;"><span>Allocated</span><span style="font-variant-numeric:tabular-nums;">' + fmt(allocated) + '</span></div><div style="font-size:12.5px;color:var(--es-text-2,#475569);">Total credits in current cycle.</div></div></div>' +
                        '</div>' +
                        '<hr class="es-divider" style="margin:18px 0;">' +
                        '<div style="display:flex;justify-content:space-between;font-size:12.5px;color:var(--es-text-2,#475569);margin-bottom:6px;"><span>' + fmt(spent) + ' of ' + fmt(total) + ' used</span><span>' + pct + '%</span></div>' +
                        '<div class="es-progress"><div class="es-progress__bar" style="width: ' + Math.max(pct, 0.5) + '%;"></div></div>' +
                    '</div>' +
                '</div>';
        })
        .catch(function (err) {
            block.innerHTML = '<div style="color:#B91C1C;padding:16px;text-align:center;">\u2716 ' + (err && err.message ? err.message : 'Unable to load usage at this time.') + '</div>';
            if (tiles) {
                tiles.innerHTML =
                    '<div class="es-stat"><span class="es-stat__label">Allocated</span><span class="es-stat__value">\u2014</span><span class="es-stat__meta">&nbsp;</span></div>' +
                    '<div class="es-stat"><span class="es-stat__label">Used</span><span class="es-stat__value">\u2014</span><span class="es-stat__meta">&nbsp;</span></div>' +
                    '<div class="es-stat"><span class="es-stat__label">Remaining</span><span class="es-stat__value">\u2014</span><span class="es-stat__meta">&nbsp;</span></div>';
            }
        });
})();

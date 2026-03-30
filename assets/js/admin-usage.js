/* EasySender — Usage tab: auto-load credit stats */
(function () {
    var block = document.getElementById('easysender-usage-block');
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

            // Donut geometry
            var r     = 44;
            var circ  = +(2 * Math.PI * r).toFixed(2);
            var freeArc = +(((100 - pct) / 100) * circ).toFixed(2);

            var color = quota ? '#ef4444' : (pct >= 90 ? '#ef4444' : pct >= 75 ? '#f59e0b' : '#2563eb');

            var statusHtml = quota
                ? '<span class="easysender-badge badge-warn">\u274c Quota reached</span>'
                : '<span class="easysender-badge badge-ok">\u2705 Active</span>';

            var donut =
                '<svg class="es-donut" viewBox="0 0 100 100">' +
                    '<circle cx="50" cy="50" r="' + r + '" fill="none" stroke="#f3f4f6" stroke-width="13"/>' +
                    '<circle cx="50" cy="50" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="13"' +
                    ' stroke-dasharray="' + freeArc + ' ' + circ + '"' +
                    ' stroke-linecap="round"/>' +
                '</svg>' +
                '<div class="es-donut-inner">' +
                    '<div class="es-donut-num">' + fmt(balance) + '</div>' +
                    '<div class="es-donut-sub">credits left</div>' +
                '</div>';

            block.innerHTML =
                '<div class="es-usage-visual">' +
                    '<div class="es-donut-wrap">' + donut + '</div>' +
                    '<div class="es-legend">' +
                        '<div class="es-legend-row">' +
                            '<div class="es-legend-dot" style="background:' + color + '"></div>' +
                            '<div><div class="es-legend-val">' + fmt(balance) + '</div>' +
                                '<div class="es-legend-lbl">Credits available &mdash; use these for email verification</div></div>' +
                        '</div>' +
                        '<div class="es-legend-row">' +
                            '<div class="es-legend-dot" style="background:#e5e7eb;border:1px solid #d1d5db"></div>' +
                            '<div><div class="es-legend-val">' + fmt(spent) + '</div>' +
                                '<div class="es-legend-lbl">Credits used &mdash; verifications sent</div></div>' +
                        '</div>' +
                        '<div class="es-legend-row">' +
                            '<div class="es-legend-dot" style="background:#bfdbfe"></div>' +
                            '<div><div class="es-legend-val">' + fmt(allocated) + '</div>' +
                                '<div class="es-legend-lbl">Allocated this period &mdash; credits added in current cycle</div></div>' +
                        '</div>' +
                        '<div style="margin-top:4px;">Status: ' + statusHtml + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="easysender-usage-subtle">' + fmt(spent) + ' of ' + fmt(total) + ' credits used (' + pct + '%)</div>';
        })
        .catch(function (err) {
            block.innerHTML = '<div style="color:#b91c1c;">\u2716 ' + (err && err.message ? err.message : 'Unable to load usage at this time.') + '</div>';
        });
})();

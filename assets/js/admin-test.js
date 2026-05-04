/* EasyDMARC — Test Email tab */
(function () {
    var btn   = document.getElementById('easysender-test-run');
    var input = document.getElementById('easysender-test-email');
    var box   = document.getElementById('easysender-test-result');
    if (!btn || !box) return;

    function setStatus(html) { box.innerHTML = html; }

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    btn.addEventListener('click', function () {
        var email = (input && input.value || '').trim();
        if (!email) {
            setStatus('<span class="easysender-pill pill-invalid">\u2716 Please enter an email address.</span>');
            input && input.focus();
            return;
        }

        btn.disabled = true;
        setStatus('<span class="easysender-subtle">Checking...</span>');

        var params = new URLSearchParams({
            action:   'easysender_test_email',
            _wpnonce: easysenderTestData.nonce,
            email:    email
        });

        fetch(ajaxurl, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:        params
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    var msg = (data && data.data && data.data.message) ? data.data.message : 'Verification failed.';
                    setStatus('<span class="easysender-pill pill-invalid">\u2716 ' + esc(msg) + '</span>');
                    return;
                }

                var v      = (data.data && data.data.verdict) || 'invalid';
                var st     = (data.data && data.data.status)  || 'unknown';
                var apiMsg = (data.data && data.data.message) ? data.data.message : '';

                var pillClass = 'pill-invalid', label = '\u2716 Invalid';
                if (v === 'valid') { pillClass = 'pill-valid';  label = '\u2714 Valid'; }
                if (v === 'risky') { pillClass = 'pill-risky';  label = '\u26a0\ufe0f Risky'; }

                var extra = '';
                if (st)     extra += '<div class="easysender-subtle">Engine status: <code>' + esc(st) + '</code></div>';
                if (apiMsg) extra += '<div class="easysender-subtle">API: ' + esc(apiMsg) + '</div>';
                var meta = (data.data && data.data.details && data.data.details.meta) || null;
                if (meta && meta.requestId) extra += '<div class="easysender-subtle">Request ID: <code>' + esc(meta.requestId) + '</code></div>';

                setStatus('<span class="easysender-pill ' + pillClass + '">' + label + '</span>' + extra);
            })
            .catch(function () {
                setStatus('<span class="easysender-pill pill-invalid">\u2716 Network error</span>');
            })
            .finally(function () { btn.disabled = false; });
    });
})();

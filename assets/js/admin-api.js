/* EasySender — Verify API Key button (API Settings tab) */
(function () {
    var btn    = document.getElementById('easysender-verify-api-key');
    var status = document.getElementById('easysender-verify-status');
    if (!btn) return;

    btn.addEventListener('click', function () {
        status.textContent = 'Checking...';
        status.style.color = '';
        btn.disabled = true;

        var form      = document.querySelector('form[action="options.php"]');
        var cidInput  = form && form.querySelector('[name="easysender_settings[client_id]"]');
        var csInput   = form && form.querySelector('[name="easysender_settings[client_secret]"]');
        var client_id     = cidInput ? cidInput.value.trim() : '';
        var client_secret = csInput  ? csInput.value.trim()  : '';

        if (!client_id || !client_secret) {
            status.textContent = '\u2716 Please enter Client ID and Client Secret first.';
            status.style.color = '#b91c1c';
            btn.disabled = false;
            return;
        }

        var payload = new FormData();
        payload.append('action', 'easysender_verify_api_key');
        payload.append('_wpnonce', easysenderApiData.nonce);
        payload.append('client_id', client_id);
        payload.append('client_secret', client_secret);

        fetch(ajaxurl, { method: 'POST', body: payload, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    var successMsg = (data.data && data.data.message) ? data.data.message : 'Verified & saved';
                    status.textContent = '\u2714 ' + successMsg;
                    status.style.color = '#059669';
                } else {
                    var msg = (data && data.data && data.data.message) ? data.data.message : 'Verification failed';
                    status.textContent = '\u2716 ' + msg;
                    status.style.color = '#b91c1c';
                }
            })
            .catch(function () {
                status.textContent = '\u2716 Network error';
                status.style.color = '#b91c1c';
            })
            .finally(function () { btn.disabled = false; });
    });
})();

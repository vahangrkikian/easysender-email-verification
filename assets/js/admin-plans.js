/* EasyDMARC — Buy Credits tab */
(function () {
    'use strict';

    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmt(n) { return Number(n).toLocaleString(); }
    function $(id) { return document.getElementById(id); }

    function track(event, props) {
        if (window.dataLayer) window.dataLayer.push(Object.assign({ event: event }, props || {}));
    }

    var plansNonce = (typeof easysenderPlansData !== 'undefined') ? easysenderPlansData.nonce : '';
    var usageNonce = (typeof easysenderPlansData !== 'undefined') ? easysenderPlansData.usage_nonce : '';

    var balanceEl   = $('esb-balance-area');
    var gridEl      = $('esb-plans-grid');
    var panelEl     = $('esb-right-panel-body');
    var subBtnEl    = $('esb-subscribe-btn');
    var fallbackEl  = $('esb-popup-fallback');

    var plans = [];
    var selectedPlan = null;
    var currentAllocated = 0;
    var usageData = null;
    var catalogCurrency = '$';

    /* ---- Load data ---- */
    Promise.all([ fetchPlans(), fetchUsage() ])
    .then(function(results) {
        var catalog = results[0] || {};
        plans = catalog.plans || [];
        catalogCurrency = catalog.currency_sign || '$';
        usageData = results[1];
        renderBalanceStrip(usageData);
        renderPlansGrid(plans, usageData);

        // Default selection: current plan or popular
        var defaultPlan = null;
        if (usageData && usageData.allocated) {
            currentAllocated = usageData.allocated;
            plans.forEach(function(p) {
                if (p.verifications_per_month === currentAllocated) defaultPlan = p;
            });
        }
        if (!defaultPlan) {
            plans.forEach(function(p) { if (p.popular) defaultPlan = p; });
        }
        if (!defaultPlan && plans.length) defaultPlan = plans[0];
        if (defaultPlan) selectPlan(defaultPlan.id);

        track('pricing_tab_viewed', {
            current_plan_id: defaultPlan ? defaultPlan.id : null,
            credits_remaining: usageData ? usageData.balance : 0
        });
    })
    .catch(function() {
        if (balanceEl) balanceEl.innerHTML = '<span class="esb-balance-count">\u2014</span><span class="esb-balance-sub">credits remaining this month</span>';
        if (gridEl) gridEl.innerHTML = '<div class="esb-plans-error">Unable to load plans. Please refresh the page or visit <a href="https://easydmarc.com/billing" target="_blank" rel="noopener noreferrer">easydmarc.com/billing</a>.</div>';
        track('pricing_tab_api_error', { error_code: 'network' });
    });

    function fetchPlans() {
        return fetch(ajaxurl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action:'easysender_get_plans', _wpnonce:plansNonce })
        }).then(function(r){return r.json();}).then(function(d){ return d && d.success ? d.data : {}; });
    }

    function fetchUsage() {
        return fetch(ajaxurl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ action:'easysender_get_usage', _wpnonce:usageNonce })
        }).then(function(r){return r.json();}).then(function(d){ return d && d.success ? d.data : null; });
    }

    /* ---- Helpers ---- */
    function formatPrice(amount, sign) {
        sign = sign || catalogCurrency;
        var n = Number(amount);
        if (n % 1 === 0) return sign + fmt(n);
        return sign + n.toFixed(2);
    }

    function formatCpv(amount, sign) {
        sign = sign || catalogCurrency;
        return sign + Number(amount).toFixed(4);
    }

    /* ---- Balance Strip ---- */
    function renderBalanceStrip(usage) {
        if (!balanceEl) return;
        if (!usage) {
            balanceEl.innerHTML = '<span class="esb-balance-count">\u2014</span><span class="esb-balance-sub">credits remaining this month</span>';
            var pillArea = $('esb-balance-pill-area');
            if (pillArea) pillArea.innerHTML = '<span class="esb-balance-pill esb-balance-pill-error">Unable to load</span>';
            return;
        }
        balanceEl.innerHTML = '<span class="esb-balance-count" aria-live="polite">'+fmt(usage.balance)+'</span><span class="esb-balance-sub">credits remaining this month</span>';
        var pillArea = $('esb-balance-pill-area');
        if (pillArea) {
            if (usage.quota === false || usage.balance > 0) {
                var planLabel = '';
                if (usage.allocated) planLabel = fmt(usage.allocated)+'/mo';
                pillArea.innerHTML = '<span class="esb-balance-pill esb-balance-pill-active">\u25CF Active'+(planLabel ? ' \u2014 '+planLabel : '')+'</span>';
            } else {
                pillArea.innerHTML = '<span class="esb-balance-pill esb-balance-pill-none">No active plan</span>';
            }
        }
    }

    /* ---- Plans Grid ---- */
    function renderPlansGrid(plans, usage) {
        if (!gridEl) return;
        var html = '';
        plans.forEach(function(p) {
            var sign = p.currency_sign || catalogCurrency;
            var isCurrent = usage && usage.allocated && p.verifications_per_month === usage.allocated;
            var classes = 'esb-plan-card';
            if (isCurrent) classes += ' current-plan';
            if (p.popular) classes += ' popular-card';

            html += '<div class="'+classes+'" data-plan-id="'+esc(p.id)+'" tabindex="0" role="button" aria-pressed="false"';
            if (p.popular) html += ' aria-label="'+esc(p.label)+' verifications per month, '+formatPrice(p.price, sign)+'/mo \u2014 Most Popular"';
            else html += ' aria-label="'+esc(p.label)+' verifications per month, '+formatPrice(p.price, sign)+'/mo"';
            html += '>';
            if (p.popular) html += '<div class="esb-popular-badge">\u2B50 Most Popular</div>';
            html += '<div class="esb-plan-verifs">'+esc(p.label)+'</div>';
            html += '<div class="esb-plan-unit">verifications / month</div>';
            if (p.savings_pct > 0) html += '<div class="esb-savings-badge">Save '+p.savings_pct+'%</div>';
            html += '<div class="esb-plan-price"><span class="esb-plan-price-amount">'+formatPrice(p.price, sign)+'</span> <span class="esb-plan-price-suffix">/ mo</span></div>';
            html += '<div class="esb-plan-cpp">'+formatCpv(p.cost_per_verification, sign)+' per verification</div>';
            if (isCurrent) html += '<div class="esb-plan-current-chip">\u2713 Your current plan</div>';
            html += '<div class="esb-plan-radio"></div>';
            html += '</div>';
        });
        gridEl.innerHTML = html;

        // Bind click events
        gridEl.querySelectorAll('.esb-plan-card').forEach(function(card) {
            card.addEventListener('click', function() { selectPlan(card.getAttribute('data-plan-id')); });
            card.addEventListener('keydown', function(e) {
                if (e.key==='Enter'||e.key===' ') { e.preventDefault(); selectPlan(card.getAttribute('data-plan-id')); }
            });
        });
    }

    function selectPlan(planId) {
        selectedPlan = null;
        plans.forEach(function(p) { if (p.id === planId) selectedPlan = p; });
        if (!selectedPlan) return;

        // Update card states
        if (gridEl) {
            gridEl.querySelectorAll('.esb-plan-card').forEach(function(card) {
                var isThis = card.getAttribute('data-plan-id') === planId;
                card.classList.toggle('selected', isThis);
                card.setAttribute('aria-pressed', isThis ? 'true' : 'false');
            });
        }

        // Update right panel
        updateRightPanel(selectedPlan);

        track('plan_card_selected', {
            plan_id: selectedPlan.id,
            price: selectedPlan.price,
            verifications: selectedPlan.verifications_per_month
        });
    }

    function updateRightPanel(plan) {
        if (!panelEl) return;
        var sign = plan.currency_sign || catalogCurrency;
        var html = '';
        html += '<div class="esb-selected-verifs">'+esc(plan.label)+'</div>';
        html += '<span class="esb-selected-verifs-label">verifications</span>';
        html += '<div class="esb-selected-monthly-note">Included every month, automatically</div>';
        html += '<div class="esb-selected-row"><span>Cost per verification</span><span class="esb-selected-row-val">'+formatCpv(plan.cost_per_verification, sign)+'</span></div>';
        html += '<div class="esb-selected-row"><span>Billing</span><span class="esb-selected-row-val">Monthly</span></div>';
        html += '<div class="esb-selected-row"><span>Contract</span><span class="esb-selected-row-val">None \u2014 cancel anytime</span></div>';
        html += '<div class="esb-selected-total-row"><span>Monthly total</span><span><span class="esb-selected-total-price">'+formatPrice(plan.price, sign)+'</span> <span class="esb-selected-total-suffix">/mo</span></span></div>';

        html += '<div class="esb-selected-features">';
        ['\u2713 Credits reset automatically each month', '\u2713 Works across all connected plugins', '\u2713 Real-time API access included', '\u2713 Upgrade or cancel from your dashboard'].forEach(function(f){
            html += '<div class="esb-selected-feature"><span class="esb-selected-feature-check">\u2713</span> '+f.substring(2)+'</div>';
        });
        html += '</div>';

        panelEl.innerHTML = html;

        // Update subscribe button
        if (subBtnEl) {
            subBtnEl.textContent = 'Subscribe \u2014 '+formatPrice(plan.price, sign)+' / mo \u2197';
            subBtnEl.setAttribute('aria-label', 'Subscribe to '+plan.label+' verifications plan for '+formatPrice(plan.price, sign)+' per month \u2014 opens EasyDMARC.com');
        }
    }

    /* ---- Subscribe ---- */
    if (subBtnEl) {
        subBtnEl.addEventListener('click', function() {
            if (!selectedPlan) return;

            track('subscribe_button_clicked', {
                plan_id: selectedPlan.id,
                price_id: selectedPlan.monthly_price_id,
                price: selectedPlan.price,
                verifications: selectedPlan.verifications_per_month,
                current_plan_id: currentAllocated || null
            });

            subBtnEl.classList.add('loading');
            subBtnEl.textContent = '\u23F3 Opening EasyDMARC\u2026';

            var priceId = selectedPlan.monthly_price_id || selectedPlan.id;
            var url = 'https://app.easydmarc.com/checkout'
                + '?ga_ref='
                + '&price=' + encodeURIComponent(priceId);
            var popup = window.open(url, '_blank', 'noopener,noreferrer');

            if (!popup && fallbackEl) {
                fallbackEl.style.display = 'block';
                fallbackEl.innerHTML = 'Your browser blocked the popup. <a href="'+esc(url)+'" target="_blank" rel="noopener noreferrer">Click here to open EasyDMARC billing.</a>';
            }

            setTimeout(function() {
                subBtnEl.classList.remove('loading');
                var sign = selectedPlan.currency_sign || catalogCurrency;
                subBtnEl.textContent = 'Subscribe \u2014 '+formatPrice(selectedPlan.price, sign)+' / mo \u2197';
            }, 1500);
        });
    }

    /* ---- Contact Sales ---- */
    var contactBtn = $('esb-contact-sales');
    if (contactBtn) {
        contactBtn.addEventListener('click', function() {
            track('contact_sales_clicked', {});
        });
    }
})();

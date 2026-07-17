/**
 * EasyPC E-commerce — single shared alert modal (centered, close button).
 * EasyPC_UI.alert(message, type, durationMs) — durationMs 0 = until closed.
 */
const IAS_UI = {
    alert: function (message, type = 'success', duration = 0) {
        const existing = document.getElementById('ias-alert-overlay');
        if (existing) existing.remove();

        let icon = '✔';
        let color = '#27ae60';
        let title = 'Success!';
        if (type === 'error') {
            icon = '✖';
            color = '#e74c3c';
            title = 'Error';
        } else if (type === 'info') {
            icon = 'ℹ';
            color = '#0998a8';
            title = 'Notice';
        }

        const overlay = document.createElement('div');
        overlay.id = 'ias-alert-overlay';
        overlay.style.cssText = [
            'position:fixed', 'inset:0', 'background:rgba(0,0,0,0.45)',
            'display:flex', 'justify-content:center', 'align-items:center',
            'z-index:9999', 'padding:20px'
        ].join(';');

        const box = document.createElement('div');
        box.style.cssText = [
            'background:#fff', 'padding:28px 36px 24px', 'border-radius:14px',
            'box-shadow:0 20px 50px rgba(0,0,0,0.2)', 'text-align:center',
            'max-width:400px', 'width:100%', 'position:relative', 'font-family:Arial,sans-serif'
        ].join(';');

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';
        closeBtn.style.cssText = [
            'position:absolute', 'top:10px', 'right:14px', 'border:none',
            'background:none', 'font-size:28px', 'line-height:1', 'cursor:pointer', 'color:#888'
        ].join(';');

        function removeAlert() {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            if (window.history.replaceState) {
                const u = new URL(window.location.href);
                ['alert', 'success', 'logged_out', 'registered', 'added'].forEach(function (k) {
                    u.searchParams.delete(k);
                });
                window.history.replaceState({}, '', u);
            }
        }

        closeBtn.onclick = removeAlert;
        overlay.onclick = function (e) {
            if (e.target === overlay) removeAlert();
        };

        box.innerHTML =
            '<div style="font-size:44px;color:' + color + ';margin-bottom:12px;font-weight:800;">' + icon + '</div>' +
            '<h3 style="margin:0 0 10px;color:#2c3e50;">' + title + '</h3>' +
            '<p style="margin:0 0 20px;color:#555;line-height:ft1.5;font-size:15px;"></p>' +
            '<button type="button" class="ias-alert-ok" style="background:#0998a8;color:#fff;border:none;' +
            'padding:11px 28px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;">OK</button>';

        box.querySelector('p').textContent = message;
        box.querySelector('.ias-alert-ok').onclick = removeAlert;

        box.insertBefore(closeBtn, box.firstChild);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        if (duration > 0) {
            setTimeout(removeAlert, duration);
        }
    }
};

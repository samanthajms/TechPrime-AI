<?php
/**
 * Full-page "can't connect" screen, shown when the database (Supabase) can't be reached.
 *
 * Self-contained on purpose: no fonts, icons or scripts from a CDN and no DB/session helpers,
 * because it has to render when the internet (or the database) is down. JSON/fetch requests
 * get {ok:false,error:'service_unavailable',message} instead of HTML.
 */

/** 'offline' when the server could not reach the network at all (DNS/route/timeout), else 'service'. */
function ias_service_unavailable_reason(string $errorMessage): string {
    $offlineHints = [
        'could not translate host name', 'name or service not known', 'no such host',
        'temporary failure in name resolution', 'network is unreachable', 'timeout expired',
        'connection timed out', 'no route to host', 'getaddrinfo',
    ];
    $msg = strtolower($errorMessage);
    foreach ($offlineHints as $hint) {
        if (strpos($msg, $hint) !== false) {
            return 'offline';
        }
    }
    return 'service';
}

/** True when the caller expects JSON (API endpoints, fetch/XHR, or a JSON Content-Type already set). */
function ias_service_unavailable_wants_json(): bool {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
        return true;
    }
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/backend/api/') !== false || preg_match('#_api\.php$#', $script)) {
        return true;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
            return true;
        }
    }
    return false;
}

/** Web path of the app folder (same approach as ias_app_base_url(), without loading security.php). */
function ias_service_unavailable_base_url(): string {
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
    $file = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($file !== '' && stripos($file, $root . '/') === 0) {
        $rel = substr($file, strlen($root));
        if (strlen($script) >= strlen($rel) && strcasecmp(substr($script, -strlen($rel)), $rel) === 0) {
            return substr($script, 0, -strlen($rel));
        }
    }
    $docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($docRoot !== '' && stripos($root, $docRoot) === 0) {
        return substr($root, strlen($docRoot));
    }
    return '';
}

/** Sends the 503 response (HTML page or JSON) and stops the request. */
function ias_service_unavailable(string $reason = 'service'): void {
    $reason = $reason === 'offline' ? 'offline' : 'service';
    $message = $reason === 'offline'
        ? 'No internet connection. Check your connection and try again.'
        : 'Service temporarily unavailable. Please try again later.';

    // Drop any half-rendered page so the screen isn't appended to broken markup.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store');
    }

    if (ias_service_unavailable_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'service_unavailable', 'reason' => $reason, 'message' => $message]);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $base = htmlspecialchars(ias_service_unavailable_base_url(), ENT_QUOTES, 'UTF-8');
    $isPost = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    $copy = $reason === 'offline'
        ? [
            'title' => 'No internet connection',
            'eyebrow' => 'Connection lost',
            'lead' => 'We couldn’t reach the EasyPC servers. Check your connection — we’ll try again as soon as you’re back online.',
            'tips' => [
                'Check that Wi-Fi or the network cable is connected',
                'Turn off airplane mode or any VPN',
                'Restart your router or modem',
            ],
        ]
        : [
            'title' => 'We’ll be right back',
            'eyebrow' => 'Service temporarily unavailable',
            'lead' => 'Our system is having trouble connecting right now. Your data is safe — please try again in a moment.',
            'tips' => [
                'Wait a minute, then select Try again',
                'Make sure your internet connection is working',
                'If this keeps happening, contact the store or your system admin',
            ],
        ];
    $icon = $reason === 'offline'
        // wifi with a slash
        ? '<path d="M2 8.8a15 15 0 0 1 4.2-2.6"/><path d="M9.8 5.3A15 15 0 0 1 22 8.8"/><path d="M5 12.6a10 10 0 0 1 4.4-2.3"/><path d="M14.6 10.6A10 10 0 0 1 19 12.6"/><path d="M8.5 16.4a5 5 0 0 1 7 0"/><path d="M12 20h.01"/><path d="M3 3l18 18"/>'
        // cloud with a slash
        : '<path d="M7.2 7.4A6 6 0 0 0 6.5 19H17"/><path d="M10.2 5.1A6 6 0 0 1 17.5 9h.5a4 4 0 0 1 2.8 6.9"/><path d="M3 3l18 18"/>';
    $tipsHtml = '';
    foreach ($copy['tips'] as $tip) {
        $tipsHtml .= '<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
            . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $title = htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8');
    $eyebrow = htmlspecialchars($copy['eyebrow'], ENT_QUOTES, 'UTF-8');
    $lead = htmlspecialchars($copy['lead'], ENT_QUOTES, 'UTF-8');
    $reasonAttr = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $isPostJs = $isPost ? 'true' : 'false';

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title} · EasyPC</title>
<script>try{var t=localStorage.getItem('ep_theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<style>
:root{
  --green-500:#62b236;--green-700:#3d7422;--green-800:#2c5618;
  --bg:#f2f5f1;--surface:#fff;--surface-2:#f7f9f6;--border:#e0e6dd;
  --text:#16201a;--text-2:#45514a;--muted:#5c6862;
  --accent-soft:#eef8e6;--warn-text:#7a5c00;--warn-soft:#fff8d6;--warn-border:#f2e3a0;
  --ok-text:#3d7422;--off-text:#b42318;--off-soft:#fef3f2;
  --action:#3d7422;--action-hover:#2c5618;--on-action:#fff;--focus:#3d7422;
  --shadow:0 1px 2px rgba(22,32,26,.06),0 24px 48px -24px rgba(22,32,26,.22);
  --ease:cubic-bezier(.23,1,.32,1);
  color-scheme:light;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0b130a;--surface:#131d12;--surface-2:#182417;--border:#263525;
  --text:#e8efe5;--text-2:#bccab7;--muted:#9aa995;
  --accent-soft:#1c2e15;--warn-text:#f3d266;--warn-soft:#2a2410;--warn-border:#4a3f17;
  --ok-text:#9ad776;--off-text:#ff9b8f;--off-soft:#2e1614;
  --action:#62b236;--action-hover:#78c74b;--on-action:#0b1407;--focus:#9ad776;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 24px 48px -24px rgba(0,0,0,.6);
  color-scheme:dark;
}}
:root[data-theme="dark"]{
  --bg:#0b130a;--surface:#131d12;--surface-2:#182417;--border:#263525;
  --text:#e8efe5;--text-2:#bccab7;--muted:#9aa995;
  --accent-soft:#1c2e15;--warn-text:#f3d266;--warn-soft:#2a2410;--warn-border:#4a3f17;
  --ok-text:#9ad776;--off-text:#ff9b8f;--off-soft:#2e1614;
  --action:#62b236;--action-hover:#78c74b;--on-action:#0b1407;--focus:#9ad776;
  --shadow:0 1px 2px rgba(0,0,0,.4),0 24px 48px -24px rgba(0,0,0,.6);
  color-scheme:dark;
}
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{
  min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;
  background:var(--bg);color:var(--text);
  font:15px/1.55 "Fira Sans",system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  -webkit-font-smoothing:antialiased;
}
.su-top{display:flex;align-items:center;justify-content:center;padding:28px 16px 0}
.su-logo{display:block;height:30px;width:auto}
.su-logo-dark{display:none}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .su-logo-light{display:none}:root:not([data-theme="light"]) .su-logo-dark{display:block}}
:root[data-theme="dark"] .su-logo-light{display:none}
:root[data-theme="dark"] .su-logo-dark{display:block}
.su-main{flex:1;display:flex;align-items:center;justify-content:center;padding:28px 16px 40px}
.su-card{
  width:100%;max-width:520px;background:var(--surface);border:1px solid var(--border);
  border-radius:20px;box-shadow:var(--shadow);padding:40px 36px 32px;text-align:center;
  animation:su-in .32s var(--ease) both;
}
@keyframes su-in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.su-icon{
  position:relative;width:88px;height:88px;margin:0 auto 22px;border-radius:50%;
  display:grid;place-items:center;background:var(--accent-soft);color:var(--green-700);
}
:root[data-theme="dark"] .su-icon{color:var(--ok-text)}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .su-icon{color:var(--ok-text)}}
.su-icon::after{
  content:"";position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--green-500);
  opacity:0;animation:su-ring 2.4s var(--ease) infinite;
}
@keyframes su-ring{0%{opacity:.45;transform:scale(.9)}70%,100%{opacity:0;transform:scale(1.12)}}
.su-icon svg{width:42px;height:42px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.su-eyebrow{
  display:inline-block;margin:0 0 10px;padding:4px 10px;border-radius:999px;
  background:var(--warn-soft);border:1px solid var(--warn-border);color:var(--warn-text);
  font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;
}
h1{margin:0 0 10px;font-size:26px;line-height:1.25;font-weight:700;letter-spacing:-.01em}
.su-lead{margin:0 auto 22px;max-width:40ch;color:var(--text-2)}
.su-tips{
  list-style:none;margin:0 0 24px;padding:14px 16px;text-align:left;
  background:var(--surface-2);border:1px solid var(--border);border-radius:12px;
}
.su-tips li{display:flex;gap:10px;align-items:flex-start;padding:5px 0;color:var(--text-2);font-size:14px}
.su-tips svg{flex:none;width:18px;height:18px;margin-top:2px;fill:none;stroke:var(--green-500);stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}
.su-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
.su-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;
  border-radius:8px;border:1px solid transparent;font:inherit;font-weight:600;font-size:15px;
  text-decoration:none;cursor:pointer;transition:background-color .18s var(--ease),transform .15s var(--ease);
}
.su-btn:active{transform:scale(.98)}
.su-btn:focus-visible{outline:2px solid var(--focus);outline-offset:2px}
.su-btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.su-btn-primary{background:var(--action);color:var(--on-action)}
.su-btn-primary:hover{background:var(--action-hover)}
.su-btn-outline{background:transparent;color:var(--text);border-color:var(--border)}
.su-btn-outline:hover{background:var(--surface-2)}
.su-btn[aria-busy="true"] svg{animation:su-spin .8s linear infinite}
@keyframes su-spin{to{transform:rotate(360deg)}}
.su-status{
  display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 14px;
  margin:22px 0 0;padding-top:18px;border-top:1px solid var(--border);color:var(--muted);font-size:13px;
}
.su-chip{display:inline-flex;align-items:center;gap:6px;font-weight:600}
.su-dot{width:8px;height:8px;border-radius:50%;background:currentColor}
.su-chip[data-state="online"]{color:var(--ok-text)}
.su-chip[data-state="offline"]{color:var(--off-text)}
.su-foot{padding:0 16px 24px;text-align:center;color:var(--muted);font-size:13px}
.su-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (max-width:480px){
  .su-card{padding:32px 20px 24px;border-radius:16px}
  h1{font-size:22px}
  .su-actions .su-btn{flex:1 1 100%}
}
@media (prefers-reduced-motion:reduce){
  .su-card,.su-icon::after,.su-btn[aria-busy="true"] svg{animation:none}
  .su-btn{transition:none}
}
</style>
</head>
<body data-reason="{$reasonAttr}">
<header class="su-top">
  <a href="{$base}/index.php" aria-label="EasyPC home">
    <img class="su-logo su-logo-light" src="{$base}/assets/logo-color.png" alt="EasyPC" width="127" height="30">
    <img class="su-logo su-logo-dark" src="{$base}/assets/logo.png" alt="EasyPC" width="128" height="30">
  </a>
</header>
<main class="su-main">
  <section class="su-card" aria-labelledby="su-title">
    <div class="su-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{$icon}</svg></div>
    <p class="su-eyebrow">{$eyebrow}</p>
    <h1 id="su-title">{$title}</h1>
    <p class="su-lead">{$lead}</p>
    <ul class="su-tips" aria-label="Things to try">{$tipsHtml}</ul>
    <div class="su-actions">
      <button type="button" class="su-btn su-btn-primary" id="su-retry">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/></svg>
        <span>Try again</span>
      </button>
      <a class="su-btn su-btn-outline" href="{$base}/index.php">Go to homepage</a>
    </div>
    <div class="su-status">
      <span class="su-chip" id="su-net" data-state="online"><span class="su-dot" aria-hidden="true"></span><span id="su-net-text">Checking connection…</span></span>
      <span id="su-auto"></span>
    </div>
    <p class="su-sr" role="status" aria-live="polite" id="su-live"></p>
  </section>
</main>
<footer class="su-foot">EasyPC One Oasis Branch · Rosario, Pasig</footer>
<script>
(function () {
  var isPost = {$isPostJs};
  var btn = document.getElementById('su-retry');
  var net = document.getElementById('su-net');
  var netText = document.getElementById('su-net-text');
  var auto = document.getElementById('su-auto');
  var live = document.getElementById('su-live');
  var wait = 30, left = wait, timer = null;

  function retry() {
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.lastElementChild.textContent = 'Reconnecting…';
    // A reload of a POST would ask to resubmit the form; open the same page with GET instead.
    if (isPost) { window.location.href = window.location.href; } else { window.location.reload(); }
  }

  function tick() {
    left -= 1;
    if (left <= 0) { retry(); return; }
    auto.textContent = 'Retrying automatically in ' + left + 's';
  }

  function update() {
    var online = navigator.onLine !== false;
    net.setAttribute('data-state', online ? 'online' : 'offline');
    netText.textContent = online ? 'Your device is online' : 'Your device is offline';
    clearInterval(timer);
    if (online) {
      left = wait;
      auto.textContent = 'Retrying automatically in ' + left + 's';
      timer = setInterval(tick, 1000);
    } else {
      auto.textContent = 'We’ll retry when you’re back online';
    }
  }

  btn.addEventListener('click', retry);
  window.addEventListener('online', function () { live.textContent = 'Back online. Reconnecting…'; retry(); });
  window.addEventListener('offline', function () { live.textContent = 'Your device went offline.'; update(); });
  update();
})();
</script>
</body>
</html>
HTML;
    exit;
}

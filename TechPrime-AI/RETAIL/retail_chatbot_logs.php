<?php
session_start();
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'retail_officer') {
    header("Location: ../login.html");
    exit;
}

function sampleSessions() {
    return [
        ['id'=>1,'user'=>'Alice','intent'=>'Hardware Compatibility Query'],
        ['id'=>2,'user'=>'Bob','intent'=>'Return Request'],
        ['id'=>3,'user'=>'Carlos','intent'=>'Order Status'],
    ];
}

$sessions = sampleSessions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Chatbot Support | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .split { display: grid; grid-template-columns: 360px 1fr; gap: 24px; }
        .session-item { padding: 16px 18px; border: 1px solid var(--ias-border); border-radius: 16px; cursor: pointer; transition: background 0.2s ease, transform 0.2s ease; }
        .session-item:hover { background: #f7fbfc; transform: translateY(-1px); }
        .session-item.active { background: rgba(9, 152, 168, 0.12); border-color: rgba(9, 152, 168, 0.28); }
        .intent { font-weight: 700; color: var(--ias-teal); }
        .transcript { min-height: 320px; }
        .btn-primary { min-width: 180px; }
        @media (max-width: 1120px) { .split { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php $active = 'chatbot'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">AI Chatbot Support</h1>
                <p class="page-subtitle">Review active conversational sessions and intervene when the bot needs help.</p>
            </div>
        </div>

        <div class="split">
            <section class="card">
                <div class="section-title">Active Sessions</div>
                <div class="section-body session-list">
                    <?php foreach($sessions as $s): ?>
                        <button type="button" class="session-item" data-id="<?php echo (int)$s['id']; ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center; gap: 12px;">
                                <div>
                                    <div style="font-weight:800; color: var(--ias-ink);"><?php echo h($s['user']); ?></div>
                                    <div class="intent"><?php echo h($s['intent']); ?></div>
                                </div>
                                <div style="font-size:12px; color: var(--ias-slate);">ID <?php echo (int)$s['id']; ?></div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card">
                <div class="section-title">Transcript Log</div>
                <div class="section-body transcript" id="transcript">
                    <div style="text-align:center; color: var(--ias-slate);">
                        <div style="font-size: 48px;">🧠</div>
                        <p style="margin-top: 14px; font-weight: 700;">Select a session to view its transcript.</p>
                    </div>
                </div>
                <div style="margin-top: 18px; display: flex; justify-content: flex-end;">
                    <button class="btn btn-primary" id="humanIntervene" type="button">Human Intervention</button>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
    document.querySelectorAll('.session-item').forEach(el => el.addEventListener('click', () => {
        const id = el.getAttribute('data-id');
        document.getElementById('transcript').innerHTML = `
            <div style="font-weight:800; margin-bottom:8px">Session ID ${id}</div>
            <div><strong>Bot:</strong> Hello, how can I help you today?</div>
            <div><strong>User:</strong> Example user message for session ${id}</div>
            <div><strong>Bot:</strong> (SVM intent classified) — Suggested resolution snippet.</div>
        `;
    }));

    document.getElementById('humanIntervene').addEventListener('click', () => {
        alert('Human intervention requested. Create a ticket or escalate to staff.');
    });
</script>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center. All Rights Reserved.</footer>

<?php ias_alert_footer(); ?>
</body>
</html>

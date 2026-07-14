<?php
// Shared header + sidebar for RETAIL pages
// Usage: set $active = 'dashboard'|'inventory'|'orders'|'replenishment'|'chatbot'|'settings' before including
$active = $active ?? 'dashboard';
?>
<header class="retail-header">
    <div class="logo-text">TechPrime AI — Retail Center</div>
</header>

<div class="retail-layout">
    <aside class="retail-sidebar">
        <button class="sidebar-item <?= $active === 'dashboard' ? 'active' : '' ?>" onclick="location.href='retail_dashboard.php'">📊 Dashboard</button>
        <button class="sidebar-item <?= in_array($active, ['inventory','products']) ? 'active' : '' ?>" onclick="location.href='retail_inventory.php'">🏷️ Inventory & Barcodes</button>
        <button class="sidebar-item <?= $active === 'replenishment' ? 'active' : '' ?>" onclick="location.href='retail_replenishment.php'">📈 Replenishment Planning</button>
        <button class="sidebar-item <?= $active === 'chatbot' ? 'active' : '' ?>" onclick="location.href='retail_chatbot_logs.php'">🤖 AI Chatbot Support</button>
        <button class="sidebar-item <?= $active === 'settings' ? 'active' : '' ?>" onclick="location.href='retail_settings.php'">⚙️ Settings</button>
        <button class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <!-- main content should follow: <main class="retail-main"> -->

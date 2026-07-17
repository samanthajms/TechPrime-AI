<?php
session_start();
// Correct paths to reach includes/backend from the CLIENT folder
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $isLoggedIn ? h($_SESSION['name']) : "Guest";

// Categories list matching your buttons
$categories = ['Accessories', 'Audio', 'Cables and Adapters', 'Camera', 'Combo', 'Cooling', 'Customization', 'Display', 'Gaming Surface', 'Graphic Card', 'Hard Disk', 'Home & Office Furniture',
'Keyboard', 'Laptop GA2', 'Laptop GA3', 'Laptop PR2', 'Laptop PR3', 'Memory', 'Mini PC', 'Motherboard', 'Mouse', 'Network Device', 'PC Case', 'Power Station', 'Power Supply', 'Printer and Scanner', 
'Processor', 'Promotional', 'Recorder', 'Services', 'Software', 'Solid State Drive', 'Speaker', 'UPS & AVR', 'Value Plus'];
$deviceCategories     = ['Laptops', 'Desktop', 'Display'];
$peripheralCategories = ['Mobile', 'Cameras', 'Accessories'];

// Categories shown in the PERIPHERALS nav dropdown (label => actual category value).
// Left column fills first (7 items), then the right column (6 items).
$peripheralNavCategories = [
    'CCTV'                 => 'CCTV',
    'Headset'              => 'Headset',
    'Keyboard'             => 'Keyboard',
    'Keyboard And Mouse'   => 'Keyboard And Mouse',
    'Display'              => 'Display',
    'Mouse'                => 'Mouse',
    'Network Device'       => 'Network Device',
    'Printer & Scanner'    => 'Printer and Scanner',
    'Projector'            => 'Projector',
    'Recorder'             => 'Recorder',
    'Speaker'              => 'Speaker',
    'UPS & AVR'            => 'UPS & AVR',
    'Web & Digital Camera' => 'Web & Digital Camera',
];

// Icon badges per category tile (decorative)
$categoryIcons = [
    'Laptops'     => 'fa-laptop',
    'Desktop'     => 'fa-desktop',
    'Mobile'      => 'fa-mobile-alt',
    'Cameras'     => 'fa-camera',
    'Accessories' => 'fa-headphones',
];

// Fetch products from Database (joining users to get the Seller's name)
$productQuery = "SELECT p.*, u.name AS seller_name
                 FROM products p
                 INNER JOIN users u ON p.seller_id = u.id
                 WHERE " . ias_client_product_list_sql_condition('p') . "
                 ORDER BY p.id DESC
                 LIMIT 40";
$productResult = $db->query($productQuery);
$allDisplayProducts = ias_client_filter_products_for_display(
    $productResult ? $productResult->fetch_all(MYSQLI_ASSOC) : [],
    12
);

// Split into two sections so the page doesn't just repeat the same row twice
$topSellers      = array_slice($allDisplayProducts, 0, 8);
$recommendations = array_slice($allDisplayProducts, 0, 6);
if (empty($recommendations)) {
    $recommendations = $topSellers;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EASYPC</title>
    <link rel="stylesheet" href="styles.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        *{box-sizing:border-box;}
        body.ep-body{
            font-family:'Poppins',sans-serif;
            margin:0;
            background:var(--ep-white);
            color:var(--ep-black);
        }
        a{text-decoration:none;color:inherit;}
        .full-width{width:100%;}

        /* ===== Header ===== */
        .ep-header{
            position:fixed; top:0; left:0; z-index:100;
            background:var(--ep-green);
            display:flex; flex-wrap:wrap; align-items:center;
            padding:14px 32px;
            box-shadow:0 2px 10px rgba(0,0,0,.15);
        }
        .ep-logo{display:flex;align-items:center;gap:10px;cursor:pointer;font-family: 'Poppins', sans-serif;}
        .ep-logo-img{height:34px;width:auto;display:block;}
        .search-wrap{flex:1;max-width:480px;margin:0 24px;}
        .search-wrap form{display:flex;background:var(--ep-white);border-radius:24px;overflow:hidden;}
        .search-wrap input{flex:1;border:none;outline:none;padding:9px 16px;font-family:'Poppins',sans-serif;font-size:.9rem;}
        .search-wrap .search-icon{border:none;color:#000;padding:0 16px;cursor:pointer;}
        .header-icons{display:flex;align-items:center;gap:10px;margin-left:auto;}
        .icon-badge-btn{
            position:relative;background:transparent;border:1px solid rgba(255,255,255,.25);
            color:#fff;width:38px;height:38px;border-radius:50%;cursor:pointer;
            display:flex;align-items:center;justify-content:center;font-size:.95rem;
        }
        .icon-badge-btn:hover{background:rgba(255,255,255,.1);}
        .icon-badge-btn.active i{color:var(--ep-yellow);}
        .icon-badge-btn .badge{
            position:absolute;top:-4px;right:-4px;background:var(--ep-green);
            color:#fff;font-size:.65rem;font-weight:700;border-radius:50%;
            min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;
            padding:0 3px;
        }
        .ep-account-btn{width:auto;padding:0 12px;border-radius:20px;gap:8px;}
        .ep-account-label{font-size:.75rem;line-height:1.1;white-space:nowrap;}

        /* ===== Nav (inside header) ===== */
        .ep-nav{
            width:100%;
            display:flex; align-items:center; justify-content:center; gap:34px;
            flex-wrap:wrap;
            border-top:1px solid rgba(255,255,255,.08);
            padding-top:12px;
            background:var(--ep-black)
        }
        .ep-nav-link{
            color:#e9e9ee; font-size:.85rem; font-weight:600; letter-spacing:.03em;
            padding:6px 2px; position:relative;
        }
        .ep-nav-link:hover, .ep-nav-link.active{color:var(--ep-green);}
        .ep-nav-dropdown{position:relative;}
        .ep-nav-dropdown-btn{background:none;border:none;cursor:pointer;font-family:inherit;}
        .ep-dropdown-menu{
            display:none; position:absolute; top:calc(100% + 14px); left:50%; transform:translateX(-50%);
            background:#fff; color:#111; border-radius:10px; padding:14px 18px;
            columns:2; column-gap:22px; width:340px; box-shadow:0 12px 30px rgba(0,0,0,.2); z-index:50;
        }
        .ep-dropdown-menu.open{display:block;}
        .ep-dropdown-menu a{display:block; padding:6px 0; font-size:.82rem; font-weight:500; color:#222;}
        .ep-dropdown-menu a:hover{color:var(--ep-green);}
        .notifications-panel{
            position:fixed; top:70px; right:20px; background:#fff; color:#111;
            border-radius:10px; padding:16px; width:260px; box-shadow:0 12px 30px rgba(0,0,0,.25); z-index:200;
        }
        .notifications-panel.hidden{display:none;}
        .notifications-panel ul{padding-left:18px;margin:8px 0 0;font-size:.82rem;}

        /* ===== Hero ===== */
        .ep-hero{
            background:linear-gradient(135deg,#1a1b21 0%,#101114 100%);
            color:#fff; padding:70px 8vw 60px; display:flex; align-items:center;
            justify-content:space-between; gap:40px; flex-wrap:wrap; min-height:400px;
        }
        .ep-hero-content{max-width:560px;}
        .ep-hero-kicker{
            color:var(--ep-white); font-weight:700; letter-spacing:.12em;
            font-size:.8rem; margin:0 0 10px;
        }
        .ep-hero-title{
            font-size:2.6rem; font-weight:800; line-height:1.15; margin:0 0 16px;
        }
        .ep-hero-subtitle{color:#c9cad0; font-size:1rem; margin:0 0 28px;}
        .ep-btn{
            display:inline-block; border:none; cursor:pointer; font-family:'Poppins',sans-serif;
            font-weight:700; font-size:.85rem; letter-spacing:.02em; border-radius:6px;
            padding:13px 30px;
        }
        .ep-btn-primary{background:var(--ep-green); color:#fff;}
        .ep-btn-primary:hover{background:var(--ep-green-dark);}
        .ep-btn-yellow{background:var(--ep-yellow); color:#14151a;}
        .ep-btn-yellow:hover{filter:brightness(.95);}
        .ep-hero-visual{
            display:flex; align-items:center; justify-content:center; gap:18px;
            flex:0 0 auto;
        }
        .ep-hero-icon{
            background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12);
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            color:var(--ep-green); font-size:2rem; overflow:hidden;
        }
        .ep-hero-icon img{
            width:100%; height:100%; object-fit:cover; border-radius:50%;
        }
        .ep-hero-icon.lg{width:250px;height:250px;font-size:3.4rem;}
        .ep-hero-icon.md{width:210px;height:210px;font-size:2.4rem;margin-top:40px;}
        .ep-hero-icon.sm{width:180px;height:180px;font-size:1.8rem;color:var(--ep-yellow);margin-top:-30px;}

        /* ===== Feature strip ===== */
        .ep-feature-strip{
            background:#1e1f26; color:#fff; display:flex; justify-content:center;
            gap:48px; flex-wrap:wrap; padding:16px 24px; font-size:.82rem; font-weight:500;
        }
        .ep-feature-strip span{display:flex;align-items:center;gap:10px;}
        .ep-feature-strip i{color:var(--ep-green);font-size:1rem;}

        /* ===== Main / Sections ===== */
        .ep-main{max-width:1280px;margin:0 125px;padding:0 24px;}
        .ep-section{padding:48px 0 8px;}
        .ep-section-head{
            display:flex;align-items:center;justify-content:space-between;
            margin-bottom:22px;
        }
        .ep-section-head h3{font-size:1.4rem;font-weight:700;margin:0;}
        .ep-see-more{color:var(--ep-green);font-weight:600;font-size:.85rem;}
        .ep-see-more:hover{text-decoration:underline;}

        .ep-carousel-wrap{position:relative;display:flex;align-items:center;gap:10px;}
        .ep-carousel{
            display:flex; gap:20px; overflow-x:auto; scroll-behavior:smooth; padding:6px 2px 14px;
            flex:1; scrollbar-width:thin;
        }
        .ep-arrow{
            flex:0 0 auto; width:38px;height:38px;border-radius:50%;border:1px solid var(--ep-border);
            background:#fff; color:var(--ep-black); cursor:pointer; display:flex; align-items:center; justify-content:center;
        }
        .ep-arrow:hover{background:var(--ep-green);color:#fff;border-color:var(--ep-green);}

        .ep-product-card{
            flex:0 0 220px; background:#fff; border:1px solid var(--ep-border); border-radius:10px;
            padding:14px; display:flex; flex-direction:column; transition:box-shadow .15s, transform .15s;
        }
        .ep-product-card:hover{box-shadow:0 10px 24px rgba(0,0,0,.08); transform:translateY(-2px);}
        .ep-product-img{
            width:100%; height:150px; object-fit:contain; background:var(--ep-gray-bg);
            border-radius:8px; margin-bottom:12px;
        }
        .ep-product-name{font-weight:600; font-size:.92rem; margin-bottom:4px; line-height:1.3;}
        .ep-product-cat{color:var(--ep-gray-text); font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px;}
        .ep-product-price{font-weight:700; font-size:1.02rem; color:var(--ep-black); margin-bottom:12px;}
        .ep-card-actions{display:flex; align-items:center; gap:8px; margin-top:auto;}
        .ep-heart-btn{
            background:#fff; border:1px solid var(--ep-border); width:36px;height:36px;border-radius:50%;
            cursor:pointer; display:flex;align-items:center;justify-content:center; color:var(--ep-black); flex:0 0 auto;
        }
        .ep-heart-btn.active i{color:#e0453c;font-weight:900;}
        .ep-heart-btn.active{border-color:#e0453c;}
        .ep-buy-form{display:flex; align-items:center; gap:8px; flex:1;}
        .ep-cart-icon{
            background:#fff; border:1px solid var(--ep-border); width:36px;height:36px;border-radius:50%;
            cursor:pointer; display:flex;align-items:center;justify-content:center; color:var(--ep-black); flex:0 0 auto;
        }
        .ep-cart-icon:hover{background:var(--ep-yellow); border-color:var(--ep-yellow);}
        .ep-buy-btn, .ep-view-btn{
            flex:1; background:var(--ep-green); color:#fff; border:none; border-radius:6px;
            padding:10px 8px; font-weight:700; font-size:.72rem; letter-spacing:.03em; cursor:pointer;
        }
        .ep-buy-btn:hover, .ep-view-btn:hover{background:var(--ep-green-dark);}
        .ep-view-btn{width:100%;}

        /* ===== Promo banner ===== */
        .ep-promo-banner{
            margin:44px 0; border-radius:14px; overflow:hidden; position:relative;
            background:linear-gradient(120deg,#1a1b21,#26272f);
            color:#fff; padding:44px 40px; display:flex; align-items:center; justify-content:space-between;
            gap:24px; flex-wrap:wrap;
        }
        .ep-promo-banner h2{font-size:1.7rem; margin:0 0 8px; font-weight:800;}
        .ep-promo-banner p{margin:0 0 20px; color:#c9cad0;}
        .ep-promo-icons{display:flex; gap:14px; font-size:2.6rem; color:var(--ep-green); opacity:.9;}
        .ep-promo-icons i:nth-child(2){color:var(--ep-yellow);}

        /* ===== Categories grid ===== */
        .ep-categories-grid{
            display:grid; grid-template-columns:repeat(6,1fr); gap:16px; margin:0 0 8px;
        }
        .ep-cat-tile{
            background:#fff; border:1px solid var(--ep-border); border-radius:10px;
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:10px; padding:20px 10px; text-align:center; transition:border-color .15s, transform .15s;
        }
        .ep-cat-tile:hover{border-color:var(--ep-green); transform:translateY(-2px);}
        .ep-cat-badge{
            width:44px;height:44px;border-radius:50%;background:var(--ep-gray-bg);
            color:var(--ep-green); display:flex; align-items:center; justify-content:center; font-size:1.1rem;
        }
        .ep-cat-label{font-size:.7rem; font-weight:700; letter-spacing:.02em;}
        .ep-pagination{display:flex; justify-content:center; gap:6px; margin:20px 0 40px; flex-wrap:wrap;}
        .ep-page-link{
            padding:7px 13px; border-radius:6px; border:1px solid var(--ep-border);
            font-size:.8rem; font-weight:600; color:var(--ep-black);
        }
        .ep-page-link.active{background:var(--ep-green); border-color:var(--ep-green); color:#fff;}
        .ep-page-link.disabled{opacity:.4; pointer-events:none;}
        .ep-page-link:hover:not(.active):not(.disabled){border-color:var(--ep-green); color:var(--ep-green);}

        /* ===== Two-tile section (New Arrivals / Best Sellers) ===== */
        .ep-duo-grid{display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:8px 0 44px;}
        .ep-duo-tile{
            border-radius:12px; padding:30px; min-height:180px; display:flex; flex-direction:column;
            justify-content:flex-end; color:#fff; position:relative; overflow:hidden;
        }
        .ep-duo-tile.new-arrivals{background:linear-gradient(120deg,#232530,#14151a);}
        .ep-duo-tile.best-sellers{background:linear-gradient(120deg,#2b2116,#14151a);}
        .ep-duo-tile h3{margin:0 0 14px; font-size:1.3rem; font-weight:800;}
        .ep-duo-tile .ep-duo-icon{
            position:absolute; right:18px; top:18px; font-size:3rem; opacity:.15;
        }

        /* ===== Tech and Match ===== */
        .ep-tech-match{margin:10px 0 50px; padding:34px; background:var(--ep-gray-bg); border-radius:14px;}
        .ep-match-title{margin:0 0 4px; font-size:1.3rem; font-weight:800;}
        .ep-match-subtitle{margin:0 0 22px; color:var(--ep-gray-text); font-size:.88rem;}
        .ep-match-panel{background:#fff; border-radius:12px; padding:24px; border:1px solid var(--ep-border);}
        .ep-match-grid{display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:26px;}
        .ep-match-left h4, .ep-match-right h4{margin:0 0 12px; font-size:.95rem; font-weight:700;}
        .ep-radio{display:flex; align-items:center; gap:8px; font-size:.88rem; margin-bottom:10px; cursor:pointer;}
        .ep-radio input{accent-color:var(--ep-green);}
        .ep-display-box{background:var(--ep-gray-bg); border-radius:8px; padding:14px 18px;}
        .ep-display-list{list-style:none; margin:0; padding:0; font-size:.85rem;}
        .ep-display-list li{padding:4px 0;}
        .ep-display-list a{color:var(--ep-black); font-weight:600;}
        .ep-display-list a:hover{color:var(--ep-green);}
        .ep-recommendations h4{margin:0 0 12px; font-size:.95rem; font-weight:700;}
        .ep-rec-wrap{display:flex; align-items:center; gap:10px;}
        .ep-rec-box{flex:1; overflow:hidden;}
        .ep-rec-card{flex:0 0 190px;}
        .empty-state{color:var(--ep-gray-text); font-size:.85rem; padding:20px 0;}

        /* ===== Footer ===== */
        .ep-footer{background:var(--ep-black); color:#d8d9dd; margin-top:40px;}
        .ep-newsletter-bar{
            display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
            gap:18px; padding:30px 40px; border-bottom:1px solid rgba(255,255,255,.08);
            max-width:1280px; margin:0 auto;
        }
        .ep-newsletter-bar h4{margin:0 0 4px; color:#fff; font-size:1.15rem; font-weight:800;}
        .ep-newsletter-bar p{margin:0; font-size:.82rem; color:#9d9ea6;}
        .ep-newsletter-form{display:flex; gap:0; border-radius:6px; overflow:hidden; min-width:320px;}
        .ep-newsletter-form input{
            border:none; outline:none; padding:12px 16px; font-family:'Poppins',sans-serif;
            font-size:.85rem; flex:1;
        }
        .ep-newsletter-form button{
            background:var(--ep-green); color:#fff; border:none; padding:0 22px; font-weight:700;
            font-size:.82rem; cursor:pointer;
        }
        .ep-newsletter-form button:hover{background:var(--ep-green-dark);}
        .ep-footer-grid{
            max-width:1280px; margin:0 auto; padding:36px 40px 20px;
            display:grid; grid-template-columns:1.6fr 1fr 1fr 1fr; gap:26px;
        }
        .ep-footer-logo-img{height:30px;margin-bottom:14px;}
        .ep-social-row{display:flex; gap:10px;}
        .ep-social-row a{
            width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.08);
            display:flex;align-items:center;justify-content:center; color:#fff; font-size:.82rem;
        }
        .ep-social-row a:hover{background:var(--ep-green);}
        .ep-footer-col h5{color:#fff; font-size:.85rem; font-weight:700; margin:0 0 14px; letter-spacing:.03em;}
        .ep-footer-col a{display:block; font-size:.82rem; color:#a7a8af; margin-bottom:9px;}
        .ep-footer-col a:hover{color:var(--ep-yellow);}
        .ep-footer-bottom{
            text-align:center; font-size:.75rem; color:#7c7d85; padding:16px 24px;
            border-top:1px solid rgba(255,255,255,.08);
        }

        .messages-float-btn{
            position:fixed; bottom:24px; right:24px; width:52px; height:52px; border-radius:50%;
            background:var(--ep-green); color:#fff; border:none; font-size:1.2rem; cursor:pointer;
            box-shadow:0 8px 20px rgba(0,0,0,.25); z-index:80;
        }
        .messages-float-btn:hover{background:var(--ep-green-dark);}

        @media (max-width:960px){
            .ep-categories-grid{grid-template-columns:repeat(3,1fr);}
            .ep-match-grid{grid-template-columns:1fr;}
            .ep-duo-grid{grid-template-columns:1fr;}
            .ep-footer-grid{grid-template-columns:1fr 1fr;}
            .search-wrap{order:3; max-width:100%; margin:12px 0 0;}
        }
        @media (max-width:640px){
            .ep-categories-grid{grid-template-columns:repeat(2,1fr);}
            .ep-hero-title{font-size:1.9rem;}
            .ep-hero{padding:50px 6vw 40px;}
            .ep-hero-visual{display:none;}
            .ep-footer-grid{grid-template-columns:1fr;}
            .ep-newsletter-bar{flex-direction:column; align-items:flex-start;}
        }
    </style>
</head>
<body class="ep-body">

    <header class="top-header ep-header full-width">
        <div class="logo ep-logo" onclick="location.href='index.php'">
            <img src="../assets/logo.png" alt="EasyPC" class="ep-logo-img">
            <span>One Oasis</span>
        </div>
        <div class="search-wrap">
            <form action="search.php" method="GET">
                <input name="q" type="text" placeholder="Search products...">
                <button type="submit" class="search-icon"><i class="fas fa-search"></i></button>
            </form>
        </div>
        <div class="header-icons">
            <button class="icon-badge-btn" title="Wishlist" onclick="this.classList.toggle('active')">
                <i class="far fa-heart"></i>
            </button>
            <button id="notifBtn" class="icon-badge-btn" title="Notifications" onclick="document.getElementById('notificationsPanel').classList.toggle('hidden')">
                <i class="far fa-bell"></i>
            </button>
            <button id="cartBtn" class="icon-badge-btn" title="Cart" onclick="location.href='cart.php'">
                <i class="fas fa-shopping-bag"></i>
                <?php if(isset($_SESSION['cart']) && count($_SESSION['cart']) > 0): ?>
                    <span class="badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </button>
            <button id="profileBtn" class="icon-badge-btn profile-outline-btn ep-account-btn"
                onclick="location.href='<?php echo isset($_SESSION['user_id']) ? 'user_dashboard.php' : '../login.php'; ?>'">
                <i class="far fa-user"></i>
                <span class="ep-account-label">
                    <?php if ($isLoggedIn): ?>My Account<?php else: ?>Login /<br>Sign In<?php endif; ?>
                </span>
            </button>
        </div>
        <div id="notificationsPanel" class="notifications-panel hidden">
            <strong>Notifications</strong>
            <ul>
                <li>Welcome to EasyPC, <?php echo $userName; ?>!</li>
                <li>Track your orders anytime from your dashboard.</li>
            </ul>
        </div>

        
    </header>

        <nav class="ep-nav">
            <a href="index.php" class="ep-nav-link active">HOME</a>
            <a href="category.php?type=Desktop" class="ep-nav-link">DESKTOP</a>
            <a href="category.php?type=Laptops" class="ep-nav-link">LAPTOP</a>

            <div class="ep-nav-dropdown">
                <button type="button" class="ep-nav-link ep-nav-dropdown-btn" onclick="epToggleDropdown(this)">PERIPHERALS <i class="fas fa-chevron-down"></i></button>
                <div class="ep-dropdown-menu ep-dropdown-cols">
                    <?php foreach ($peripheralNavCategories as $label => $value): ?>
                        <a href="category.php?type=<?php echo urlencode($value); ?>"><?php echo h($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <a href="category.php?type=Brands" class="ep-nav-link">BRANDS</a>
        </nav>
    <script>
        // Keep page content clear of the fixed header at all viewport sizes.
        (function () {
            function epSetHeaderOffset() {
                var header = document.querySelector('.ep-header');
                if (header) document.body.style.paddingTop = header.offsetHeight + 'px';
            }
            epSetHeaderOffset();
            window.addEventListener('resize', epSetHeaderOffset);
        })();
    </script>

    <section class="ep-hero full-width">
        <div class="ep-hero-content">
            <p class="ep-hero-kicker">TECH IT EASY AT</p>
            <h1 class="ep-hero-title">Easy PC<br>One Oasis Branch</h1>
            <p class="ep-hero-subtitle">Explore the latest PCs, laptops &amp; accessories from EasyPC.</p>
            <a href="products.php" class="ep-btn ep-btn-primary">Shop Now</a>
        </div>
        <div class="ep-hero-visual" aria-hidden="true">
            <span class="ep-hero-icon md"><img src="../assets/headset.png" alt="Headset"></span>
            <span class="ep-hero-icon lg"><img src="../assets/desktop.png" alt="Desktop"></span>
            <span class="ep-hero-icon sm"><img src="../assets/mouse.png" alt="Mouse"></span>
        </div>
    </section>

    <section class="ep-feature-strip full-width">
        <span><i class="fas fa-shipping-fast"></i> Free Shipping on Orders Over &#8369;2,500</span>
        <span><i class="fas fa-undo"></i> 30-Day Money Back Guarantee</span>
        <span><i class="fas fa-headset"></i> 24/7 Customer Support</span>
    </section>

    <main class="ep-main">

        <section class="ep-section">
            <div class="ep-section-head">
                <h3>Featured Products</h3>
                <a href="products.php" class="ep-see-more">See more</a>
            </div>

            <div class="ep-carousel-wrap">
                <button class="ep-arrow ep-arrow-left" onclick="epScroll('topSellersRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>

                <div class="ep-carousel" id="topSellersRow">
                    <?php if (!empty($topSellers)): ?>
                        <?php foreach ($topSellers as $p): ?>
                            <div class="ep-product-card">
                                <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                                <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                <div class="ep-card-actions">
                                    <button type="button" class="ep-heart-btn" onclick="this.classList.toggle('active')" aria-label="Save"><i class="far fa-heart"></i></button>
                                    <form action="products.php" method="POST" class="ep-buy-form">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                                        <input type="hidden" name="add_to_cart" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <button  type="submit" class="ep-cart-icon"><i class="fas fa-shopping-cart"></i></button>
                                        <button class="ep-buy-btn">BUY NOW</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No products available yet. Check back later!</div>
                    <?php endif; ?>
                </div>

                <button class="ep-arrow ep-arrow-right" onclick="epScroll('topSellersRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
            </div>
        </section>

        <div class="ep-promo-banner">
            <div>
                <h2>Boost Your Productivity</h2>
                <p>Essential accessories for work &amp; play.</p>
                <a href="category.php?type=Accessories" class="ep-btn ep-btn-primary">Browse Now</a>
            </div>
            <div class="ep-promo-icons" aria-hidden="true">
                <i class="fas fa-laptop"></i>
                <i class="fas fa-keyboard"></i>
                <i class="fas fa-mouse"></i>
            </div>
        </div>

        <div class="ep-duo-grid">
            <a class="ep-duo-tile new-arrivals" href="products.php">
                <i class="fas fa-vr-cardboard ep-duo-icon"></i>
                <h3>New Arrivals</h3>
                <span class="ep-btn ep-btn-primary">Shop New</span>
            </a>
        </div>

        <div class="ep-section-head">
                <h3>Categories</h3>
        </div>
        <section class="ep-categories-grid" id="epCategoriesGrid">
            <?php foreach ($categories as $cat): ?>
                <a class="ep-cat-tile" href="category.php?type=<?php echo urlencode($cat); ?>">
                    <span class="ep-cat-badge"><i class="fas <?php echo h($categoryIcons[$cat] ?? 'fa-wrench'); ?>"></i></span>
                    <span class="ep-cat-label"><?php echo strtoupper(h($cat)); ?></span>
                </a>
            <?php endforeach; ?>

        </section>
        <nav class="ep-pagination" id="epCategoriesPagination" aria-label="Categories pagination"></nav>

        <section class="ep-tech-match">
            <h2 class="ep-match-title">Tech and Match</h2>
            <p class="ep-match-subtitle">Find compatible devices for your setup.</p>

            <div class="ep-match-panel">
                <div class="ep-match-grid">
                    <div class="ep-match-left">
                        <h4>Find a match for...</h4>
                        <label class="ep-radio">
                            <input type="radio" name="epMatchType" value="device" checked onchange="epUpdateMatch()"> Device
                        </label>
                        <label class="ep-radio">
                            <input type="radio" name="epMatchType" value="peripherals" onchange="epUpdateMatch()"> Peripherals
                        </label>
                    </div>
                    <div class="ep-match-right">
                        <h4>Device/Peripherals</h4>
                        <div class="ep-display-box">
                               
                            <ul id="epMatchList" class="ep-display-list">
                                <?php foreach ($deviceCategories as $dc): ?>
                                    <li>- <a href="category.php?type=<?php echo urlencode($dc); ?>"><?php echo h($dc); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="ep-recommendations">
                    <h4>Recommendations</h4>
                    <div class="ep-rec-wrap">
                        <button class="ep-arrow ep-arrow-left" onclick="epScroll('epRecRow', -1)" aria-label="Scroll left"><i class="fas fa-arrow-left"></i></button>
                        <div class="ep-rec-box">
                            <div class="ep-carousel ep-rec-carousel" id="epRecRow">
                                <?php if (!empty($recommendations)): ?>
                                    <?php foreach ($recommendations as $p): ?>
                                        <div class="ep-product-card ep-rec-card">
                                            <img src="<?php echo h(ias_client_product_image_url($p)); ?>" class="ep-product-img" alt="<?php echo h($p['name']); ?>">
                                            <div class="ep-product-name"><?php echo h($p['name']); ?></div>
                                            <div class="ep-product-cat"><?php echo h($p['category'] ?: 'Uncategorized'); ?></div>
                                            <div class="ep-product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                            <div class="ep-card-actions">
                                                <button type="button" class="ep-heart-btn" onclick="this.classList.toggle('active')" aria-label="Save"><i class="far fa-heart"></i></button>
                                                <button type="button" class="ep-view-btn" onclick="location.href='products.php?id=<?php echo (int)$p['id']; ?>'">VIEW PRODUCT</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">No recommendations yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="ep-arrow ep-arrow-right" onclick="epScroll('epRecRow', 1)" aria-label="Scroll right"><i class="fas fa-arrow-right"></i></button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <button id="messagesFloatBtn" class="messages-float-btn ep-chat-float" onclick="location.href='messages.php'">
        <i class="fas fa-comment-dots"></i>
    </button>

    <footer class="ep-footer full-width">
        <div class="ep-newsletter-bar">
            <div>
                <h4>Join Our Newsletter</h4>
                <p>Get the latest deals &amp; updates</p>
            </div>
            <form class="ep-newsletter-form" action="#" method="POST" onsubmit="return false;">
                <input type="email" name="newsletter_email" placeholder="Enter your email" required>
                <button type="submit">Subscribe</button>
            </form>
        </div>
        <div class="ep-footer-grid">
            <div class="ep-footer-brand">
                <div class="ep-footer-logo"><img src="../assets/easypc-logo-transparent.png" alt="EasyPC" class="ep-footer-logo-img"></div>
                <div class="ep-social-row">
                    <a href="#" aria-label="X"><i class="fab fa-twitter"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fab fa-youtube"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="ep-footer-col">
                <h5>Shop</h5>
                <a href="category.php?type=Desktop">Desktop</a>
                <a href="category.php?type=Laptops">Laptop</a>
                <a href="category.php?type=Accessories">Accessories</a>
                <a href="products.php">All Products</a>
            </div>
            <div class="ep-footer-col">
                <h5>Explore</h5>
                <a href="index.php">Home</a>
                <a href="cart.php">Cart</a>
                <a href="user_dashboard.php">My Orders</a>
            </div>
            <div class="ep-footer-col">
                <h5>Resources</h5>
                <a href="privacy_policy.php">Privacy Policy</a>
                <a href="<?php echo $isLoggedIn ? 'user_dashboard.php' : '../login.php'; ?>">My Account</a>
                <a href="messages.php">Help Center</a>
            </div>
        </div>
        <div class="ep-footer-bottom">&copy; 2026 EASYPC E-Commerce. All Rights Reserved.</div>
    </footer>

    <script src="../includes/ui_alerts.js"></script>
    <script>
        // Check for "added to cart" message from products.php
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('added') && typeof IAS_UI !== 'undefined') {
                IAS_UI.alert('Added to cart!', 'success');
            }
        });

        // ── Categories grid: paginate two rows at a time ────────────────────
        (function () {
            const grid = document.getElementById('epCategoriesGrid');
            const pagination = document.getElementById('epCategoriesPagination');
            if (!grid || !pagination) return;

            const tiles = Array.from(grid.children);
            const ROWS_PER_PAGE = 2;
            let currentPage = 1;

            function getColumnCount() {
                const cols = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean);
                return Math.max(1, cols.length);
            }

            function render() {
                const perPage = getColumnCount() * ROWS_PER_PAGE;
                const totalPages = Math.max(1, Math.ceil(tiles.length / perPage));
                if (currentPage > totalPages) currentPage = totalPages;

                const start = (currentPage - 1) * perPage;
                const end = start + perPage;
                tiles.forEach((tile, i) => {
                    tile.style.display = (i >= start && i < end) ? '' : 'none';
                });

                pagination.innerHTML = '';
                if (totalPages <= 1) return;

                const makeLink = (label, page, opts = {}) => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'ep-page-link' + (opts.nav ? ' ep-page-nav' : '') +
                        (opts.active ? ' active' : '') + (opts.disabled ? ' disabled' : '');
                    a.innerHTML = label;
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (opts.disabled) return;
                        currentPage = page;
                        render();
                        grid.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                    return a;
                };

                pagination.appendChild(makeLink('<i class="fas fa-arrow-left"></i> Previous', currentPage - 1, { nav: true, disabled: currentPage <= 1 }));
                for (let p = 1; p <= totalPages; p++) {
                    pagination.appendChild(makeLink(String(p), p, { active: p === currentPage }));
                }
                pagination.appendChild(makeLink('Next <i class="fas fa-arrow-right"></i>', currentPage + 1, { nav: true, disabled: currentPage >= totalPages }));
            }

            render();
            window.addEventListener('resize', render);
        })();

        // ── Carousel scroll helper ──────────────────────────────────────────
        function epScroll(id, dir) {
            const row = document.getElementById(id);
            if (!row) return;
            row.scrollBy({ left: dir * 320, behavior: 'smooth' });
        }

        // ── Nav dropdown toggle ─────────────────────────────────────────────
        function epToggleDropdown(btn) {
            const menu = btn.nextElementSibling;
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            if (!isOpen) menu.classList.add('open');
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.ep-nav-dropdown')) {
                document.querySelectorAll('.ep-dropdown-menu.open').forEach(m => m.classList.remove('open'));
            }
        });

        // ── Tech and Match: device vs peripherals list ──────────────────────
        const epDeviceCats = <?php echo json_encode($deviceCategories); ?>;
        const epPeripheralCats = <?php echo json_encode($peripheralCategories); ?>;
        function epUpdateMatch() {
            const type = document.querySelector('input[name="epMatchType"]:checked').value;
            const cats = type === 'device' ? epDeviceCats : epPeripheralCats;
            const list = document.getElementById('epMatchList');
            list.innerHTML = cats.map(c => `<li>- <a href="category.php?type=${encodeURIComponent(c)}">${c}</a></li>`).join('');
        }
    </script>
<?php ias_alert_footer(); ?>
</body>
</html>
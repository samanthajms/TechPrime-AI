<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'seller') {
    header('Location: ../login.php');
    exit;
}

$seller_id = (int)$_SESSION['user_id'];

if (isset($_POST['add_product'])) {
<<<<<<< HEAD
<<<<<<< Updated upstream
    $name = $db->real_escape_string($_POST['name']);
=======
    $name  = $_POST['name'];
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $desc = $db->real_escape_string($_POST['description']);
    $img = $db->real_escape_string($_POST['image_url']);
    $allowed_categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];
    $category = in_array($_POST['category'], $allowed_categories) ? $_POST['category'] : 'Accessories';

    $stmt = $db->prepare("INSERT INTO products (seller_id, name, price, stock, description, image_url, category) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdisss", $seller_id, $name, $price, $stock, $desc, $img, $category);
    $stmt->execute();
    header("Location: seller_products.php?success=added"); exit;
=======
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $imageFile = ias_handle_product_upload($seller_id);

    if ($name !== '' && $price > 0 && $stock >= 0 && $imageFile) {
        $stmt = $db->prepare('INSERT INTO products (seller_id, name, price, stock, description, image, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $emptyUrl = '';
        $stmt->bind_param('isdisss', $seller_id, $name, $price, $stock, $desc, $imageFile, $emptyUrl);
        $stmt->execute();
        $stmt->close();
        logActivity($db, $seller_id, 'add_product', "Added product: $name");
        header('Location: seller_products.php?alert=added');
        exit;
    }
    header('Location: seller_products.php?alert=error');
    exit;
>>>>>>> Stashed changes
}

if (isset($_POST['edit_product'])) {
<<<<<<< HEAD
<<<<<<< Updated upstream
    $id = (int)$_POST['product_id'];
    $name = $db->real_escape_string($_POST['name']);
=======
    $id    = (int)$_POST['product_id'];
    $name  = $_POST['name'];
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $desc = $db->real_escape_string($_POST['description']);
    $img = $db->real_escape_string($_POST['image_url']);
    $allowed_categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];
    $category = in_array($_POST['category'], $allowed_categories) ? $_POST['category'] : 'Accessories';

    $stmt = $db->prepare("UPDATE products SET name=?, price=?, stock=?, description=?, image_url=?, category=? WHERE id=? AND seller_id=?");
    $stmt->bind_param("sdisssii", $name, $price, $stock, $desc, $img, $category, $id, $seller_id);
    $stmt->execute();
    header("Location: seller_products.php?success=updated"); exit;
=======
    $id = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $desc = trim($_POST['description'] ?? '');

    if ($id > 0 && $name !== '' && $price > 0) {
        $stmt = $db->prepare('UPDATE products SET name = ?, price = ?, description = ? WHERE id = ? AND seller_id = ?');
        $stmt->bind_param('sdsii', $name, $price, $desc, $id, $seller_id);
        $stmt->execute();
        $stmt->close();
        logActivity($db, $seller_id, 'edit_product', "Updated product #$id");
        header('Location: seller_products.php?alert=updated');
        exit;
    }
>>>>>>> Stashed changes
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid > 0) {
        $imgSt = $db->prepare('SELECT image FROM products WHERE id = ? AND seller_id = ?');
        $imgSt->bind_param('ii', $pid, $seller_id);
        $imgSt->execute();
        $row = $imgSt->get_result()->fetch_assoc();
        $imgSt->close();

        $del = $db->prepare('DELETE FROM products WHERE id = ? AND seller_id = ?');
        $del->bind_param('ii', $pid, $seller_id);
        $del->execute();
        $del->close();
        $db->query('DELETE FROM cart WHERE product_id = ' . $pid);

        if (!empty($row['image'])) {
            $path = dirname(__DIR__) . '/uploads/products/' . basename($row['image']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        logActivity($db, $seller_id, 'delete_product', "Deleted product #$pid");
        header('Location: seller_products.php?alert=deleted');
        exit;
    }
}

// FIX: seller_id already cast to int — safe in query
$products = $db->query("SELECT * FROM products WHERE seller_id = $seller_id ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Inventory | IAS Seller</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
<<<<<<< HEAD
        :root { --ias-teal: #0998a8; --ias-gold: #f5f500; --sidebar-gray: #6a969a; --bg: #f4f7f6; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); }
        .seller-header { background: var(--ias-teal); padding: 15px 30px; border-bottom: 3px solid var(--ias-gold); }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; }
=======
        :root {
            --ias-teal: #0998a8;
            --ias-gold: #f5f500;
            --sidebar-gray: #6a969a;
            --bg: #f4f7f6;
        }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); }
        .seller-header { background: var(--ias-teal); padding: 15px 30px; border-bottom: 3px solid var(--ias-gold); }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; letter-spacing: 1px; }
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
        .seller-layout { display: flex; flex: 1; overflow: hidden; }
        .seller-sidebar { background: var(--sidebar-gray); width: 260px; padding-top: 10px; display: flex; flex-direction: column; }
        .sidebar-item { background: transparent; color: white; border: none; padding: 15px 25px; width: 100%; text-align: left; font-size: 15px; font-weight: 600; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-item:hover, .sidebar-item.active { background: rgba(0,0,0,0.1); color: var(--ias-gold); }
<<<<<<< HEAD
        .logout-btn { background: #b22222 !important; margin-top: auto; }
        .seller-main { padding: 30px; flex: 1; overflow-y: auto; }
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 25px; max-width: 1400px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px dashed var(--ias-teal); }
        .card h2 { margin-top: 0; font-size: 18px; font-weight: 800; }
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th { text-align: left; padding: 12px; background: #fafafa; color: #888; font-size: 11px; text-transform: uppercase; }
        .product-table td { padding: 15px 12px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: middle; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; }
        .btn-edit { background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-delete { background: #ff4757; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: var(--ias-teal); color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal.open { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 12px; width: 450px; max-width: 92%; position: relative; border: 2px solid var(--ias-teal); }
        .modal .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; border: none; background: none; }
        input, textarea { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        label { font-size: 12px; font-weight: 800; color: #666; text-transform: uppercase; display: block; margin-top: 8px; }
        .ias-footer { background: var(--ias-teal); color: white; padding: 15px 30px; }
=======
        .logout-btn { background: #b22222 !important; margin-top: auto; border-bottom: none; }
        .seller-main { padding: 30px; flex: 1; overflow-y: auto; }
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 25px; max-width: 1400px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px dashed var(--ias-teal); }
        .card h2 { margin-top: 0; font-size: 18px; font-weight: 800; color: #333; margin-bottom: 20px; }
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th { text-align: left; padding: 12px; background: #fafafa; color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .product-table td { padding: 15px 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .btn-edit { background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-delete { background: #ff4757; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: var(--ias-teal); color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-primary:hover { opacity: 0.9; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 30px; border-radius: 12px; width: 450px; position: relative; border: 2px solid var(--ias-teal); }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; }
        input, textarea { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-family: inherit; }
        label { font-size: 12px; font-weight: 800; color: #666; text-transform: uppercase; }
        .ias-footer { background: var(--ias-teal); color: white; padding: 15px 30px; font-size: 14px; font-weight: 500; }
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
    </style>
</head>
<body>

<header class="seller-header"><div class="logo-text">IAS SELLER</div></header>

<div class="seller-layout">
    <aside class="seller-sidebar">
        <button type="button" class="sidebar-item" onclick="location.href='seller_dashboard.php'">📊 Dashboard</button>
        <button type="button" class="sidebar-item active">📦 My Products</button>
        <button type="button" class="sidebar-item" onclick="location.href='seller_orders.php'">📜 Orders</button>
        <button type="button" class="sidebar-item" onclick="location.href='seller_messages.php'">💬 Messages</button>
        <button type="button" class="sidebar-item" onclick="location.href='seller_settings.php'">⚙️ Settings</button>
        <button type="button" class="sidebar-item logout-btn" onclick="location.href='../logout.php'">🚪 Logout</button>
    </aside>

    <main class="seller-main">
        <div class="content-grid">
            <section class="card">
                <h2>Current Inventory</h2>
                <table class="product-table">
                    <thead>
<<<<<<< Updated upstream
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Action</th>
                        </tr>
=======
                        <tr><th>Image</th><th>Product</th><th>Price</th><th>Stock</th><th>Action</th></tr>
>>>>>>> Stashed changes
                    </thead>
                    <tbody>
                        <?php while ($p = $products->fetch_assoc()):
                            $imgSrc = ias_product_image_url($p);
                        ?>
                        <tr>
<<<<<<< HEAD
                            <td><?php if ($imgSrc): ?><img src="<?php echo h($imgSrc); ?>" alt="" class="thumb"><?php endif; ?></td>
=======
                            <!-- FIX: name already wrapped with h() -->
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
                            <td><strong><?php echo h($p['name']); ?></strong></td>
<<<<<<< Updated upstream
                            <td><span style="background:#e7f5f7; color:var(--ias-teal); padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700;"><?php echo h($p['category'] ?? '—'); ?></span></td>
                            <td style="color: var(--ias-teal); font-weight: 700;">₱<?php echo number_format($p['price'], 2); ?></td>
                            <!-- FIX: Cast stock to int -->
                            <td><?php echo (int)$p['stock']; ?></td>
                            <td style="display: flex; gap: 8px;">
                                <!-- json_encode handles escaping for JS context — safe -->
                                <button class="btn-edit" onclick='openEditModal(<?php echo json_encode($p); ?>)'>Edit</button>
                                <form method="post" onsubmit="return confirm('Delete this product?')">
=======
                            <td style="color:var(--ias-teal);font-weight:700;">₱<?php echo number_format((float)$p['price'], 2); ?></td>
                            <td><?php echo (int)$p['stock']; ?></td>
                            <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="btn-edit" onclick='openEditModal(<?php echo json_encode([
                                    "id" => (int)$p["id"], "name" => $p["name"], "price" => $p["price"], "description" => $p["description"],
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>Edit</button>
                                <form method="post" onsubmit="return confirm('Delete this product?');">
>>>>>>> Stashed changes
                                    <input type="hidden" name="action" value="delete">
<<<<<<< HEAD
=======
                                    <!-- FIX: Cast id to int -->
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Add New Product</h2>
                <form method="post" enctype="multipart/form-data">
                    <label>Product Name</label>
<<<<<<< Updated upstream
                    <input type="text" name="name" placeholder="e.g. Wireless Keyboard" required>

                    <label>Category</label>
                    <select name="category" required style="width:100%; padding:10px; margin:8px 0; border:1px solid #ddd; border-radius:6px; font-family:inherit; background:white;">
                        <option value="" disabled selected>Select a category...</option>
                        <option value="Laptops">Laptops</option>
                        <option value="Desktop">Desktop</option>
                        <option value="Mobile">Mobile</option>
                        <option value="Cameras">Cameras</option>
                        <option value="Accessories">Accessories</option>
                    </select>
                    
                    <div style="display: flex; gap: 10px;">
                        <div style="flex:1;">
                            <label>Price (₱)</label>
                            <input type="number" step="0.01" name="price" required>
                        </div>
                        <div style="flex:1;">
                            <label>Stock</label>
                            <input type="number" name="stock" required>
                        </div>
                    </div>
<<<<<<< HEAD
                    
=======
                    <input type="text" name="name" required>
                    <label>Price (₱)</label>
                    <input type="number" step="0.01" name="price" min="0.01" required>
                    <label>Stock Quantity (set once)</label>
                    <input type="number" name="stock" min="0" required>
>>>>>>> Stashed changes
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                    <label>Product Image (JPG, JPEG, PNG)</label>
                    <input type="file" name="product_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                    <button type="submit" name="add_product" class="btn-primary" style="margin-top:12px;">Add to Shop</button>
=======

                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Describe your product..."></textarea>

                    <label>Image URL</label>
                    <input type="text" name="image_url" placeholder="https://image-link.com/photo.jpg">

                    <button type="submit" name="add_product" class="btn-primary">Add to Shop</button>
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
                </form>
            </section>
        </div>
    </main>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <button type="button" class="close" onclick="closeEditModal()">&times;</button>
        <h2 style="margin-top:0;color:var(--ias-teal);">Edit Product</h2>
        <form method="post">
            <input type="hidden" name="product_id" id="edit_id">
<<<<<<< HEAD
=======

>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
            <label>Product Name</label>
            <input type="text" name="name" id="edit_name" required>
<<<<<<< Updated upstream

            <label>Category</label>
            <select name="category" id="edit_category" required style="width:100%; padding:10px; margin:8px 0; border:1px solid #ddd; border-radius:6px; font-family:inherit; background:white;">
                <option value="Laptops">Laptops</option>
                <option value="Desktop">Desktop</option>
                <option value="Mobile">Mobile</option>
                <option value="Cameras">Cameras</option>
                <option value="Accessories">Accessories</option>
            </select>
            
            <div style="display: flex; gap: 10px;">
                <div style="flex:1;"><label>Price</label><input type="number" step="0.01" name="price" id="edit_price" required></div>
                <div style="flex:1;"><label>Stock</label><input type="number" name="stock" id="edit_stock" required></div>
            </div>
<<<<<<< HEAD
            
=======
            <label>Price (₱)</label>
            <input type="number" step="0.01" name="price" id="edit_price" min="0.01" required>
>>>>>>> Stashed changes
            <label>Description</label>
            <textarea name="description" id="edit_desc" rows="3"></textarea>
            <p style="font-size:12px;color:#888;">Stock and image cannot be edited. Stock decreases when clients purchase.</p>
            <button type="submit" name="edit_product" class="btn-primary" style="margin-top:12px;">Update Product</button>
=======

            <label>Description</label>
            <textarea name="description" id="edit_desc" rows="3"></textarea>

            <label>Image URL</label>
            <input type="text" name="image_url" id="edit_img">

            <button type="submit" name="edit_product" class="btn-primary" style="margin-top:10px;">Update Product</button>
>>>>>>> cbf0f392eb2a935bfe3b7575419f23e51f680e68
        </form>
    </div>
</div>

<footer class="ias-footer">© 2026 IAS E-Commerce Seller Center.</footer>

<script>
<<<<<<< Updated upstream
function openEditModal(product) {
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_name').value = product.name;
    document.getElementById('edit_category').value = product.category || 'Accessories';
    document.getElementById('edit_price').value = product.price;
    document.getElementById('edit_stock').value = product.stock;
    document.getElementById('edit_desc').value  = product.description;
    document.getElementById('edit_img').value   = product.image_url;
    document.getElementById('editModal').style.display = "block";
}
function closeModal() { document.getElementById('editModal').style.display = "none"; }
window.onclick = function(event) {
    if (event.target == document.getElementById('editModal')) closeModal();
=======
function openEditModal(p) {
    document.getElementById('edit_id').value = p.id;
    document.getElementById('edit_name').value = p.name;
    document.getElementById('edit_price').value = p.price;
    document.getElementById('edit_desc').value = p.description || '';
    document.getElementById('editModal').classList.add('open');
>>>>>>> Stashed changes
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditModal(); });
</script>
<?php ias_alert_footer(); ?>
</body>
</html>

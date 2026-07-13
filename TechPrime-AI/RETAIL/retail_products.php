<?php
session_start();
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('retail_officer');

$retail_id = (int)$_SESSION['user_id'];
$allowed_categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];

function retail_product_category(array $allowed): string
{
    $category = $_POST['category'] ?? 'Accessories';
    return in_array($category, $allowed, true) ? $category : 'Accessories';
}

if (isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = retail_product_category($allowed_categories);
    $imageFile = ias_handle_product_upload($retail_id);

    if ($name !== '' && $price > 0 && $stock >= 0 && $imageFile !== null) {
        $emptyUrl = '';
        $stmt = $db->prepare(
            'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isdissss', $retail_id, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category);
        $stmt->execute();
        $stmt->close();

        logActivity($db, $retail_id, 'add_product', "Added product: $name");
        header('Location: retail_products.php?alert=added');
        exit;
    }

    header('Location: retail_products.php?alert=error');
    exit;
}

if (isset($_POST['edit_product'])) {
    $id = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = retail_product_category($allowed_categories);

    if ($id > 0 && $name !== '' && $price > 0 && $stock >= 0) {
        $newImage = ias_handle_product_upload($retail_id);
        $triedImageUpload = !empty($_FILES['product_image']['name']);

        if ($triedImageUpload && $newImage === null) {
            header('Location: retail_products.php?alert=error');
            exit;
        }

        if ($newImage !== null) {
            $oldSt = $db->prepare('SELECT image FROM products WHERE id = ? AND seller_id = ?');
            $oldSt->bind_param('ii', $id, $retail_id);
            $oldSt->execute();
            $oldRow = $oldSt->get_result()->fetch_assoc();
            $oldSt->close();

            $emptyUrl = '';
            $stmt = $db->prepare(
                'UPDATE products
                 SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?
                 WHERE id = ? AND seller_id = ?'
            );
            $stmt->bind_param('sdissssii', $name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $id, $retail_id);
            $stmt->execute();
            $stmt->close();

            if (!empty($oldRow['image'])) {
                $oldPath = dirname(__DIR__) . '/uploads/products/' . basename($oldRow['image']);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        } else {
            $stmt = $db->prepare(
                'UPDATE products
                 SET name = ?, price = ?, stock = ?, description = ?, category = ?
                 WHERE id = ? AND seller_id = ?'
            );
            $stmt->bind_param('sdissii', $name, $price, $stock, $desc, $category, $id, $retail_id);
            $stmt->execute();
            $stmt->close();
        }

        logActivity($db, $retail_id, 'edit_product', "Updated product #$id");
        header('Location: retail_products.php?alert=updated');
        exit;
    }

    header('Location: retail_products.php?alert=error');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid > 0) {
        $imgSt = $db->prepare('SELECT image FROM products WHERE id = ? AND seller_id = ?');
        $imgSt->bind_param('ii', $pid, $retail_id);
        $imgSt->execute();
        $row = $imgSt->get_result()->fetch_assoc();
        $imgSt->close();

        $del = $db->prepare('DELETE FROM products WHERE id = ? AND seller_id = ?');
        $del->bind_param('ii', $pid, $retail_id);
        $del->execute();
        $del->close();

        $cart = $db->prepare('DELETE FROM cart WHERE product_id = ?');
        $cart->bind_param('i', $pid);
        $cart->execute();
        $cart->close();

        if (!empty($row['image'])) {
            $path = dirname(__DIR__) . '/uploads/products/' . basename($row['image']);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        logActivity($db, $retail_id, 'delete_product', "Deleted product #$pid");
        header('Location: retail_products.php?alert=deleted');
        exit;
    }
}

$stmt = $db->prepare('SELECT * FROM products WHERE seller_id = ? ORDER BY id DESC');
$stmt->bind_param('i', $retail_id);
$stmt->execute();
$products = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Inventory | Easy PC Retail</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ias-teal: #0998a8; --ias-gold: #f5f500; --sidebar-gray: #6a969a; --bg: #f4f7f6; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); }
        .retail-header { background: var(--ias-teal); padding: 15px 30px; border-bottom: 3px solid var(--ias-gold); }
        .logo-text { color: var(--ias-gold); font-size: 24px; font-weight: 900; letter-spacing: 1px; }
        .retail-layout { display: flex; flex: 1; overflow: hidden; }
        .retail-sidebar { background: var(--sidebar-gray); width: 260px; padding-top: 10px; display: flex; flex-direction: column; }
        .sidebar-item { background: transparent; color: white; border: none; padding: 15px 25px; width: 100%; text-align: left; font-size: 15px; font-weight: 600; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-item:hover, .sidebar-item.active { background: rgba(0,0,0,0.1); color: var(--ias-gold); }
        .logout-btn { background: #b22222 !important; margin-top: auto; border-bottom: none; }
        .retail-main { padding: 30px; flex: 1; overflow-y: auto; }
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 25px; max-width: 1400px; }
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px dashed var(--ias-teal); }
        .card h2 { margin-top: 0; font-size: 18px; font-weight: 800; color: #333; margin-bottom: 20px; }
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th { text-align: left; padding: 12px; background: #fafafa; color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .product-table td { padding: 15px 12px; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: middle; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; background: #eef3f3; }
        .category-pill { background: #e7f5f7; color: var(--ias-teal); padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .btn-edit { background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-delete { background: #ff4757; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: var(--ias-teal); color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.3s; }
        .btn-primary:hover { opacity: 0.9; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal.open { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 12px; width: 450px; max-width: 92%; max-height: 90vh; overflow-y: auto; position: relative; border: 2px solid var(--ias-teal); }
        .modal .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; border: none; background: none; }
        input, textarea, select { width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-family: inherit; background: white; }
        label { font-size: 12px; font-weight: 800; color: #666; text-transform: uppercase; display: block; margin-top: 8px; }
        .ias-footer { background: var(--ias-teal); color: white; padding: 15px 30px; font-size: 14px; font-weight: 500; }
    </style>
</head>
<body>

<header class="retail-header"><div class="logo-text">EASY PC RETAIL</div></header>

<div class="retail-layout">
    <aside class="retail-sidebar">
        <button type="button" class="sidebar-item" onclick="location.href='retail_dashboard.php'">Dashboard</button>
        <button type="button" class="sidebar-item active">My Products</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_orders.php'">Orders</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_messages.php'">Messages</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_reviews.php'">Reviews</button>
        <button type="button" class="sidebar-item" onclick="location.href='retail_settings.php'">Settings</button>
        <button type="button" class="sidebar-item logout-btn" onclick="location.href='../logout.php'">Logout</button>
    </aside>

    <main class="retail-main">
        <div class="content-grid">
            <section class="card">
                <h2>Current Inventory</h2>
                <table class="product-table">
                    <thead>
                        <tr><th>Image</th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($products->num_rows > 0): ?>
                            <?php while ($p = $products->fetch_assoc()):
                                $imgSrc = ias_product_image_url($p);
                                $editProduct = [
                                    'id' => (int)$p['id'],
                                    'name' => $p['name'] ?? '',
                                    'price' => (float)$p['price'],
                                    'stock' => (int)$p['stock'],
                                    'category' => $p['category'] ?? 'Accessories',
                                    'description' => $p['description'] ?? '',
                                ];
                            ?>
                            <tr>
                                <td><?php if ($imgSrc !== ''): ?><img src="<?php echo h($imgSrc); ?>" alt="" class="thumb"><?php endif; ?></td>
                                <td><strong><?php echo h($p['name']); ?></strong></td>
                                <td><span class="category-pill"><?php echo h($p['category'] ?? 'Accessories'); ?></span></td>
                                <td style="color: var(--ias-teal); font-weight: 700;">PHP <?php echo number_format((float)$p['price'], 2); ?></td>
                                <td><?php echo (int)$p['stock']; ?></td>
                                <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button type="button" class="btn-edit" onclick='openEditModal(<?php echo json_encode($editProduct, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this product?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center;color:#888;">No products yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Add New Product</h2>
                <form method="post" enctype="multipart/form-data">
                    <label>Product Name</label>
                    <input type="text" name="name" placeholder="e.g. Wireless Keyboard" required>

                    <label>Category</label>
                    <select name="category" required>
                        <option value="" disabled selected>Select a category...</option>
                        <?php foreach ($allowed_categories as $category): ?>
                            <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Price</label>
                    <input type="number" step="0.01" min="0.01" name="price" required>

                    <label>Stock</label>
                    <input type="number" min="0" name="stock" required>

                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Describe your product..."></textarea>

                    <label>Product Image (JPG, JPEG, PNG)</label>
                    <input type="file" name="product_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>

                    <button type="submit" name="add_product" class="btn-primary" style="margin-top:12px;">Add to Shop</button>
                </form>
            </section>
        </div>
    </main>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <button type="button" class="close" onclick="closeEditModal()">&times;</button>
        <h2 style="margin-top:0;color:var(--ias-teal);">Edit Product</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="product_id" id="edit_id">

            <label>Product Name</label>
            <input type="text" name="name" id="edit_name" required>

            <label>Category</label>
            <select name="category" id="edit_category" required>
                <?php foreach ($allowed_categories as $category): ?>
                    <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                <?php endforeach; ?>
            </select>

            <label>Price</label>
            <input type="number" step="0.01" min="0.01" name="price" id="edit_price" required>

            <label>Stock</label>
            <input type="number" min="0" name="stock" id="edit_stock" required>

            <label>Description</label>
            <textarea name="description" id="edit_desc" rows="3"></textarea>

            <label>Replace Image (optional)</label>
            <input type="file" name="product_image" accept=".jpg,.jpeg,.png,image/jpeg,image/png">

            <button type="submit" name="edit_product" class="btn-primary" style="margin-top:12px;">Update Product</button>
        </form>
    </div>
</div>

<footer class="ias-footer">&copy; 2026 Easy PC Retail Center.</footer>

<script>
function openEditModal(product) {
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_name').value = product.name || '';
    document.getElementById('edit_category').value = product.category || 'Accessories';
    document.getElementById('edit_price').value = product.price;
    document.getElementById('edit_stock').value = product.stock;
    document.getElementById('edit_desc').value = product.description || '';
    document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>
<?php ias_alert_footer(); ?>
</body>
</html>

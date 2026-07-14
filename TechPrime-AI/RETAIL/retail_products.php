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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Inventory | TechPrime AI</title>
    <link rel="stylesheet" href="../retail.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th,
        .product-table td { padding: 16px 18px; border-bottom: 1px solid #edf2f5; }
        .product-table th { background: #f7fafb; color: var(--ias-slate); text-transform: uppercase; font-size: 12px; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 12px; background: #eef3f3; }
        .category-pill { background: rgba(9, 152, 168, 0.12); color: var(--ias-teal); padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .btn-edit { border: none; background: #3498db; color: white; padding: 10px 14px; border-radius: 12px; cursor: pointer; font-weight: 700; }
        .btn-delete { border: none; background: #ff4757; color: white; padding: 10px 14px; border-radius: 12px; cursor: pointer; font-weight: 700; }
        .modal { position: fixed; inset: 0; background: rgba(12, 27, 33, 0.35); display: none; align-items: center; justify-content: center; z-index: 100; }
        .modal.open { display: flex; }
        .modal-card { width: min(560px, 100%); background: white; border-radius: 24px; padding: 28px; box-shadow: 0 24px 70px rgba(5, 22, 31, 0.16); }
        .modal-card h3 { margin-top: 0; }
        .modal-card .form-group { margin-bottom: 18px; }
        .modal-card .form-control { width: 100%; }
        .modal-close { position: absolute; right: 22px; top: 22px; border: none; background: transparent; font-size: 24px; cursor: pointer; }
    </style>
</head>
<body>

<?php $active = 'inventory'; include __DIR__ . '/../includes/retail_shell.php'; ?>
    <main class="retail-main">
        <div class="page-header">
            <div>
                <h1 class="page-title">My Inventory</h1>
                <p class="page-subtitle">Manage products, stock, and pricing in one centralized retail control panel.</p>
            </div>
        </div>

        <div class="grid-2">
            <section class="card">
                <div class="section-title">Current Inventory</div>
                <div class="section-body" style="overflow-x:auto;">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
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
                                    <td style="display: flex; gap: 10px; flex-wrap: wrap;">
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
                                <tr><td colspan="6" style="text-align:center; color: var(--ias-slate);">No products yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="section-title">Add New Product</div>
                <div class="section-body">
                    <form method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label class="form-label">Product Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Wireless Keyboard" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-control" required>
                                <option value="" disabled selected>Select a category...</option>
                                <?php foreach ($allowed_categories as $category): ?>
                                    <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Price</label>
                            <input type="number" step="0.01" min="0.01" name="price" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Stock</label>
                            <input type="number" min="0" name="stock" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" rows="4" class="form-control" placeholder="Describe your product..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Product Image</label>
                            <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                        </div>
                        <button type="submit" name="add_product" class="btn btn-primary" style="width:100%;">Add to Shop</button>
                    </form>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="modal" id="editModal">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeEditModal()">×</button>
        <h3>Edit Product</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="edit_product" value="1">
            <input type="hidden" name="product_id" id="editProductId">
            <div class="form-group">
                <label class="form-label">Product Name</label>
                <input type="text" name="name" id="editName" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" id="editCategory" class="form-control" required>
                    <?php foreach ($allowed_categories as $category): ?>
                        <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Price</label>
                <input type="number" step="0.01" min="0.01" name="price" id="editPrice" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Stock</label>
                <input type="number" min="0" name="stock" id="editStock" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="editDescription" rows="4" class="form-control"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Image (optional)</label>
                <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Save Changes</button>
        </form>
    </div>
</div>

<script>
function openEditModal(product) {
    document.getElementById('editProductId').value = product.id;
    document.getElementById('editName').value = product.name;
    document.getElementById('editPrice').value = product.price;
    document.getElementById('editStock').value = product.stock;
    document.getElementById('editCategory').value = product.category;
    document.getElementById('editDescription').value = product.description;
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
</script>

<footer class="ias-footer">© 2026 TechPrime AI Retail Center.</footer>

<?php ias_alert_footer(); ?>
</body>
</html>


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

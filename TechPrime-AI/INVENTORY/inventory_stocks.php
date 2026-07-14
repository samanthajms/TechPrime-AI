<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../includes/staff_layout.php';

$db = getDbConnection();
checkSessionTimeout();
checkRole('inventory_custodian');

$uid = (int)$_SESSION['user_id'];
$allowed_categories = ['Laptops', 'Desktop', 'Mobile', 'Cameras', 'Accessories'];

function inventory_product_category(array $allowed): string
{
    $category = $_POST['category'] ?? 'Accessories';
    return in_array($category, $allowed, true) ? $category : 'Accessories';
}

if (isset($_POST['add_product'])) {
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = inventory_product_category($allowed_categories);
    $imageFile = ias_handle_product_upload($uid);

    if ($name !== '' && $price > 0 && $stock >= 0 && $imageFile !== null) {
        $emptyUrl = '';
        $stmt = $db->prepare(
            'INSERT INTO products (seller_id, name, price, stock, description, image, image_url, category)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isdissss', $uid, $name, $price, $stock, $desc, $imageFile, $emptyUrl, $category);
        $stmt->execute();
        $stmt->close();

        logActivity($db, $uid, 'add_product', "Added product: $name");
        header('Location: inventory_stocks.php?alert=added');
        exit;
    }

    header('Location: inventory_stocks.php?alert=error');
    exit;
}

if (isset($_POST['edit_product'])) {
    $id = (int)($_POST['product_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $category = inventory_product_category($allowed_categories);

    if ($id > 0 && $name !== '' && $price > 0 && $stock >= 0) {
        $newImage = ias_handle_product_upload($uid);
        $triedImageUpload = !empty($_FILES['product_image']['name']);

        if ($triedImageUpload && $newImage === null) {
            header('Location: inventory_stocks.php?alert=error');
            exit;
        }

        if ($newImage !== null) {
            $oldSt = $db->prepare('SELECT image FROM products WHERE id = ?');
            $oldSt->bind_param('i', $id);
            $oldSt->execute();
            $oldRow = $oldSt->get_result()->fetch_assoc();
            $oldSt->close();

            $emptyUrl = '';
            $stmt = $db->prepare(
                'UPDATE products
                 SET name = ?, price = ?, stock = ?, description = ?, category = ?, image = ?, image_url = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sdissssi', $name, $price, $stock, $desc, $category, $newImage, $emptyUrl, $id);
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
                 WHERE id = ?'
            );
            $stmt->bind_param('sdissi', $name, $price, $stock, $desc, $category, $id);
            $stmt->execute();
            $stmt->close();
        }

        logActivity($db, $uid, 'edit_product', "Updated product #$id");
        header('Location: inventory_stocks.php?alert=updated');
        exit;
    }

    header('Location: inventory_stocks.php?alert=error');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid > 0) {
        $imgSt = $db->prepare('SELECT image FROM products WHERE id = ?');
        $imgSt->bind_param('i', $pid);
        $imgSt->execute();
        $row = $imgSt->get_result()->fetch_assoc();
        $imgSt->close();

        $del = $db->prepare('DELETE FROM products WHERE id = ?');
        $del->bind_param('i', $pid);
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

        logActivity($db, $uid, 'delete_product', "Deleted product #$pid");
        header('Location: inventory_stocks.php?alert=deleted');
        exit;
    }
}

$products = $db->query('SELECT * FROM products ORDER BY id DESC');

staff_page_start([
    'role' => 'inventory_custodian',
    'title' => 'Stocks',
    'active' => 'stocks',
    'heading' => 'Stocks',
    'subtitle' => 'Manage product inventory',
    'extra_head' => <<<'EXTRA'
<style>
.products-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }
@media (max-width: 1100px) { .products-grid { grid-template-columns: 1fr; } }
.thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 8px; background: var(--ep-green-light); }
.category-pill {
    background: var(--ep-green-light); color: var(--ep-green-dark);
    padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700;
}
.price-tag { color: var(--ep-green-dark); font-weight: 800; }
.action-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.modal {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 2000; align-items: center; justify-content: center;
}
.modal.open { display: flex; }
.modal-content {
    background: #fff; padding: 28px; border-radius: 12px; width: 450px;
    max-width: 92%; max-height: 90vh; overflow-y: auto; position: relative;
    border: 2px solid var(--ep-green);
}
.modal .close {
    position: absolute; right: 16px; top: 12px; font-size: 24px;
    cursor: pointer; border: none; background: none; color: #888; line-height: 1;
}
.modal h3 { margin: 0 0 16px; color: var(--ep-green-dark); }
</style>
EXTRA
]);
?>

        <div class="products-grid">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-boxes"></i></span> Current Inventory</h3>
                        <div class="card-subtitle">All stocked products</div>
                    </div>
                </div>
                <div class="card-body" style="padding-top:0;">
                    <div class="table-wrap">
                        <table class="ias-table">
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
                                <?php if ($products && $products->num_rows > 0): ?>
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
                                        <td class="price-tag">₱<?php echo number_format((float)$p['price'], 2); ?></td>
                                        <td><?php echo (int)$p['stock']; ?></td>
                                        <td>
                                            <div class="action-row">
                                                <button type="button" class="btn btn-primary btn-sm" onclick='openEditModal(<?php echo json_encode($editProduct, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Edit</button>
                                                <form method="post" onsubmit="return confirm('Delete this product?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="empty-state">No products yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div>
                        <h3><span class="card-icon"><i class="fas fa-plus"></i></span> Add New Product</h3>
                        <div class="card-subtitle">Add stock to inventory</div>
                    </div>
                </div>
                <div class="card-body">
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
                            <textarea name="description" class="form-control" rows="3" placeholder="Describe your product..."></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Product Image (JPG, JPEG, PNG)</label>
                            <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
                        </div>
                        <button type="submit" name="add_product" class="btn btn-primary" style="width:100%;">Add Product</button>
                    </form>
                </div>
            </div>
        </div>

        <div id="editModal" class="modal">
            <div class="modal-content">
                <button type="button" class="close" onclick="closeEditModal()">&times;</button>
                <h3>Edit Product</h3>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="product_id" id="edit_id">
                    <div class="form-group">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category</label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <?php foreach ($allowed_categories as $category): ?>
                                <option value="<?php echo h($category); ?>"><?php echo h($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price</label>
                        <input type="number" step="0.01" min="0.01" name="price" id="edit_price" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Stock</label>
                        <input type="number" min="0" name="stock" id="edit_stock" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Replace Image (optional)</label>
                        <input type="file" name="product_image" class="form-control" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                    </div>
                    <button type="submit" name="edit_product" class="btn btn-primary" style="width:100%;">Update Product</button>
                </form>
            </div>
        </div>

<?php
$flashMsg = ias_alert_message_from_request();
$flashType = ((!empty($_GET['alert']) && $_GET['alert'] === 'error') || !empty($_GET['error'])) ? 'error' : 'success';
$flashScript = '';
if ($flashMsg) {
    $flashScript = '<script>document.addEventListener("DOMContentLoaded",function(){if(typeof IAS_UI!=="undefined")IAS_UI.alert('
        . json_encode($flashMsg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ','
        . json_encode($flashType) . ',0);});</script>';
}
staff_page_end(<<<'SCRIPTS'
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
SCRIPTS . $flashScript);
?>

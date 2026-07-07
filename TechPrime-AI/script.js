const STORE_KEYS = {
    products: "ias_products",
    cart: "ias_cart",
    favorites: "ias_favorites",
    orders: "ias_orders",
    recent: "ias_recent_viewed",
    users: "ias_users"
};

const CATEGORY_META = {
    Mobile: { icon: "📱", page: "category_mobile.html", image: "https://images.unsplash.com/photo-1598327105666-5b89351aff97?auto=format&fit=crop&w=900&q=80" },
    Cameras: { icon: "📷", page: "category_cameras.html", image: "https://images.unsplash.com/photo-1516035069371-29a1b244cc32?auto=format&fit=crop&w=900&q=80" },
    Accessories: { icon: "🎧", page: "category_accessories.html", image: "https://images.unsplash.com/photo-1583394838336-acd977736f90?auto=format&fit=crop&w=900&q=80" },
    Desktop: { icon: "🖥️", page: "category_desktop.html", image: "https://images.unsplash.com/photo-1593640408182-31c228c1d5c7?auto=format&fit=crop&w=900&q=80" },
    Laptops: { icon: "💻", page: "category_laptops.html", image: "https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=80" }
};

function currency(value) {
    return `₱${Number(value).toFixed(2)}`;
}

function readJson(key, fallback) {
    const raw = localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
}

function writeJson(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
}

async function logoutAndRedirect() {
    await fetch("backend/api/auth.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: new URLSearchParams({ action: "logout" }).toString()
    });
    localStorage.removeItem("ias_current_user");
    window.location.href = "login.php";
}

function defaultProducts() {
    const base = [
        { name: "Mobile Alpha X", price: 12999, category: "Mobile", description: "6.7-inch OLED 5G smartphone with fast charging." },
        { name: "Camera ProShot", price: 22500, category: "Cameras", description: "24MP mirrorless camera with 4K video support." },
        { name: "RGB Keyboard", price: 1599, category: "Accessories", description: "Mechanical keyboard with responsive switches." },
        { name: "DeskCore i5", price: 31500, category: "Desktop", description: "Complete desktop setup for work and gaming." },
        { name: "LiteBook 14", price: 28999, category: "Laptops", description: "Thin 14-inch laptop with SSD and long battery." },
        { name: "Mobile Beta 5G", price: 9999, category: "Mobile", description: "Affordable smartphone with balanced performance." },
        { name: "Lens Kit Camera", price: 6200, category: "Cameras", description: "Entry lens bundle for camera enthusiasts." },
        { name: "Fast Charger 65W", price: 1300, category: "Accessories", description: "USB-C fast charger for phones and laptops." }
    ];

    return base.map((item, index) => ({
        id: index + 1,
        ...item,
        image: CATEGORY_META[item.category].image
    }));
}

function ensureStore() {
    if (!localStorage.getItem(STORE_KEYS.products)) writeJson(STORE_KEYS.products, defaultProducts());
    if (!localStorage.getItem(STORE_KEYS.cart)) writeJson(STORE_KEYS.cart, []);
    if (!localStorage.getItem(STORE_KEYS.favorites)) writeJson(STORE_KEYS.favorites, []);
    if (!localStorage.getItem(STORE_KEYS.orders)) writeJson(STORE_KEYS.orders, []);
    if (!localStorage.getItem(STORE_KEYS.recent)) writeJson(STORE_KEYS.recent, []);
}

function getProducts() {
    return readJson(STORE_KEYS.products, []);
}

function getProductById(productId) {
    return getProducts().find((product) => product.id === Number(productId));
}

function addRecent(product) {
    const recent = readJson(STORE_KEYS.recent, []);
    const next = [product, ...recent.filter((item) => item.id !== product.id)].slice(0, 12);
    writeJson(STORE_KEYS.recent, next);
}

function toggleFavorite(productId) {
    const favorites = readJson(STORE_KEYS.favorites, []);
    if (favorites.includes(productId)) {
        writeJson(STORE_KEYS.favorites, favorites.filter((id) => id !== productId));
        return false;
    }
    writeJson(STORE_KEYS.favorites, [...favorites, productId]);
    return true;
}

function addToCart(productId, qty = 1) {
    const cart = readJson(STORE_KEYS.cart, []);
    const existing = cart.find((item) => item.productId === productId);
    if (existing) existing.quantity += qty;
    else cart.push({ productId, quantity: qty });
    writeJson(STORE_KEYS.cart, cart);
}

function renderProductModal(product) {
    let modal = document.getElementById("productModal");
    if (!modal) {
        modal = document.createElement("div");
        modal.id = "productModal";
        modal.className = "product-modal hidden";
        document.body.appendChild(modal);
    }
    const favorites = readJson(STORE_KEYS.favorites, []);
    const heart = favorites.includes(product.id) ? "❤️" : "🤍";
    modal.innerHTML = `
        <div class="modal-overlay"></div>
        <div class="modal-card">
            <button class="modal-close" id="modalCloseBtn">✕</button>
            <img src="${product.image}" alt="${product.name}">
            <h3>${product.name}</h3>
            <p class="price">${currency(product.price)}</p>
            <p>${product.description}</p>
            <div class="modal-actions">
                <button class="primary-btn" id="modalAddCart">Add to Cart</button>
                <button class="primary-btn" id="modalBuyNow">Buy Now</button>
                <button class="heart-btn" id="modalFavorite">${heart}</button>
            </div>
        </div>
    `;
    modal.classList.remove("hidden");
    addRecent(product);

    const close = () => modal.classList.add("hidden");
    document.getElementById("modalCloseBtn").addEventListener("click", close);
    modal.querySelector(".modal-overlay").addEventListener("click", close);
    document.getElementById("modalAddCart").addEventListener("click", () => {
        addToCart(product.id);
        window.location.href = "cart.php";
    });
    document.getElementById("modalBuyNow").addEventListener("click", () => {
        addToCart(product.id);
        window.location.href = "checkout.php";
    });
    document.getElementById("modalFavorite").addEventListener("click", () => {
        const isFav = toggleFavorite(product.id);
        document.getElementById("modalFavorite").textContent = isFav ? "❤️" : "🤍";
    });
}

function renderHomePage() {
    const categoriesRow = document.getElementById("categoriesRow");
    const productsGrid = document.getElementById("productsGrid");
    if (!categoriesRow || !productsGrid) return;

    const currentUser = JSON.parse(localStorage.getItem("ias_current_user") || '{"id":1}');
    const welcome = document.getElementById("welcomeText");
    if (welcome) welcome.textContent = `Welcome User${String(currentUser.id).padStart(4, "0")}`;

    categoriesRow.innerHTML = Object.entries(CATEGORY_META).map(([name, meta]) => `
        <button class="category-card" data-page="${meta.page}">
            <span class="category-icon">${meta.icon}</span>
            <span class="category-label">${name}</span>
        </button>
    `).join("");

    categoriesRow.querySelectorAll(".category-card").forEach((item) => {
        item.addEventListener("click", () => window.location.href = item.dataset.page);
    });

    const drawProducts = () => {
        const keyword = (document.getElementById("searchInput")?.value || "").trim().toLowerCase();
        const list = getProducts().filter((item) => item.name.toLowerCase().includes(keyword)).slice(0, 8);
        productsGrid.innerHTML = list.map((item) => `
            <article class="product-card hover-lift" data-product-id="${item.id}">
                <img src="${item.image}" alt="${item.name}" class="product-thumb-img">
                <h4 class="product-title">${item.name}</h4>
                <p class="price">${currency(item.price)}</p>
            </article>
        `).join("");
        productsGrid.querySelectorAll(".product-card").forEach((card) => {
            card.addEventListener("click", () => renderProductModal(getProductById(card.dataset.productId)));
        });
    };
    drawProducts();

    document.getElementById("searchInput")?.addEventListener("input", drawProducts);
    document.getElementById("profileBtn")?.addEventListener("click", () => window.location.href = "profile.php");
    document.getElementById("cartBtn")?.addEventListener("click", () => window.location.href = "cart.php");
    document.getElementById("messagesFloatBtn")?.addEventListener("click", () => window.location.href = "messages.php");
    document.getElementById("notifBtn")?.addEventListener("click", () => document.getElementById("notificationsPanel")?.classList.toggle("hidden"));
    document.getElementById("catLeftBtn")?.addEventListener("click", () => categoriesRow.scrollBy({ left: -220, behavior: "smooth" }));
    document.getElementById("catRightBtn")?.addEventListener("click", () => categoriesRow.scrollBy({ left: 220, behavior: "smooth" }));
}

function renderCategoryPage() {
    const root = document.getElementById("categoryRoot");
    if (!root) return;
    const category = root.dataset.category;
    const title = document.getElementById("categoryTitle");
    if (title) title.textContent = `${category} Products`;

    const products = getProducts().filter((item) => item.category === category);
    const filler = [];
    while (products.length + filler.length < 30) {
        const sample = products[(products.length + filler.length) % products.length] || getProducts()[0];
        filler.push({ ...sample, id: `${sample.id}-x${filler.length}` });
    }
    const merged = [...products, ...filler].slice(0, 30);

    const grid = document.getElementById("categoryProductsGrid");
    if (!grid) return;
    grid.innerHTML = merged.map((item) => `
        <article class="product-card-large hover-lift" data-product-id="${item.id}">
            <img src="${item.image}" alt="${item.name}">
            <div class="body">
                <h4 class="product-title">${item.name}</h4>
                <p class="price">${currency(item.price)}</p>
            </div>
        </article>
    `).join("");

    grid.querySelectorAll(".product-card-large").forEach((card) => {
        card.addEventListener("click", () => {
            const product = getProductById(String(card.dataset.productId).split("-")[0]);
            if (product) renderProductModal(product);
        });
    });
}

function renderCartPage() {
    const root = document.getElementById("cartItems");
    if (!root) return;
    const cart = readJson(STORE_KEYS.cart, []);
    const products = getProducts();

    const refresh = () => {
        const current = readJson(STORE_KEYS.cart, []);
        if (!current.length) {
            root.innerHTML = "<p>Your cart is empty.</p>";
            document.getElementById("cartSubtotal").textContent = currency(0);
            document.getElementById("cartTotal").textContent = currency(0);
            return;
        }

        let subtotal = 0;
        root.innerHTML = `<table class="data-table"><thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Total</th><th>Action</th></tr></thead><tbody>${current.map((item) => {
            const product = products.find((p) => p.id === item.productId);
            if (!product) return "";
            subtotal += product.price * item.quantity;
            return `
                <tr data-id="${item.productId}">
                    <td><strong>${product.name}</strong></td>
                    <td>${currency(product.price)}</td>
                    <td class="qty-controls">
                        <button data-action="minus">-</button>
                        <span>${item.quantity}</span>
                        <button data-action="plus">+</button>
                    </td>
                    <td>${currency(product.price * item.quantity)}</td>
                    <td><button class="remove-btn" data-action="remove">Remove</button></td>
                </tr>
            `;
        }).join("")}</tbody></table>`;

        document.getElementById("cartSubtotal").textContent = currency(subtotal);
        document.getElementById("cartTotal").textContent = currency(subtotal);
        root.querySelectorAll("tr button").forEach((btn) => {
            btn.addEventListener("click", () => {
                const row = btn.closest("tr");
                const id = Number(row.dataset.id);
                const items = readJson(STORE_KEYS.cart, []);
                const target = items.find((entry) => entry.productId === id);
                if (!target) return;
                if (btn.dataset.action === "plus") target.quantity += 1;
                if (btn.dataset.action === "minus") target.quantity = Math.max(1, target.quantity - 1);
                if (btn.dataset.action === "remove") writeJson(STORE_KEYS.cart, items.filter((entry) => entry.productId !== id));
                else writeJson(STORE_KEYS.cart, items);
                refresh();
            });
        });
    };
    refresh();
    document.getElementById("checkoutBtn")?.addEventListener("click", () => window.location.href = "checkout.php");
}

function renderProfilePage() {
    const favoritesRoot = document.getElementById("favoritesGrid");
    if (!favoritesRoot) return;
    const favorites = readJson(STORE_KEYS.favorites, []);
    const products = getProducts();
    const recent = readJson(STORE_KEYS.recent, []);

    const favProducts = products.filter((item) => favorites.includes(item.id));
    favoritesRoot.innerHTML = favProducts.length
        ? favProducts.map((item) => `<div class="recent-item"><img src="${item.image}" alt="${item.name}" class="tiny-img"><div>${item.name}</div><strong>${currency(item.price)}</strong></div>`).join("")
        : "<p>No favorites yet.</p>";

    const recentRoot = document.getElementById("recentViewedGrid");
    if (recentRoot) {
        recentRoot.innerHTML = recent.length
            ? recent.slice(0, 9).map((item) => `<div class="recent-item"><img src="${item.image}" alt="${item.name}" class="tiny-img"><div>${item.name}</div><strong>${currency(item.price)}</strong></div>`).join("")
            : "<p>No recent products yet.</p>";
    }

    const statuses = ["to_pay", "to_ship", "to_receive", "to_review"];
    statuses.forEach((status) => {
        document.querySelector(`[data-status="${status}"]`)?.addEventListener("click", () => {
            window.location.href = `orders.php?status=${status}`;
        });
    });

    document.getElementById("logoutBtn")?.addEventListener("click", logoutAndRedirect);
}

function renderCheckoutPage() {
    const root = document.getElementById("checkoutItems");
    if (!root) return;
    const cart = readJson(STORE_KEYS.cart, []);
    const products = getProducts();
    const shippingFee = 120;
    let subtotal = 0;
    root.innerHTML = cart.map((item) => {
        const product = products.find((entry) => entry.id === item.productId);
        if (!product) return "";
        subtotal += product.price * item.quantity;
        return `<div class="cart-row"><img src="${product.image}" alt="${product.name}"><div><strong>${product.name}</strong><div>${currency(product.price)} x ${item.quantity}</div></div></div>`;
    }).join("");
    document.getElementById("checkoutSubtotal").textContent = currency(subtotal);
    document.getElementById("checkoutTotal").textContent = currency(subtotal + shippingFee);

    document.getElementById("placeOrderBtn")?.addEventListener("click", () => {
        const fullName = document.getElementById("shipName").value.trim();
        const phone = document.getElementById("shipPhone").value.trim();
        const address = document.getElementById("shipAddress").value.trim();
        if (!fullName || !phone || !address || !cart.length) {
            alert("Complete shipping fields and ensure cart is not empty.");
            return;
        }
        const orders = readJson(STORE_KEYS.orders, []);
        const orderId = `ORD-${Date.now()}`;
        orders.unshift({
            id: orderId,
            items: cart,
            subtotal,
            shippingFee,
            total: subtotal + shippingFee,
            status: "to_pay",
            date: new Date().toISOString(),
            shipping: { fullName, phone, address }
        });
        writeJson(STORE_KEYS.orders, orders);
        writeJson(STORE_KEYS.cart, []);
        localStorage.setItem("ias_last_order_id", orderId);
        window.location.href = "order_success.php";
    });
}

function renderOrderSuccessPage() {
    const orderId = localStorage.getItem("ias_last_order_id");
    const orders = readJson(STORE_KEYS.orders, []);
    const order = orders.find((entry) => entry.id === orderId);
    if (!order) return;
    document.getElementById("orderIdLabel").textContent = order.id;
    document.getElementById("orderTotalLabel").textContent = currency(order.total);
}

function renderOrdersPage() {
    const root = document.getElementById("ordersList");
    if (!root) return;
    const products = getProducts();
    const orders = readJson(STORE_KEYS.orders, []);
    const params = new URLSearchParams(window.location.search);
    const status = params.get("status");
    const filtered = status ? orders.filter((item) => item.status === status) : orders;

    root.innerHTML = filtered.length ? `<table class="data-table"><thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th>Total</th><th>Action</th></tr></thead><tbody>${filtered.map((order) => {
        const firstItem = order.items[0];
        const product = firstItem ? products.find((entry) => entry.id === firstItem.productId) : null;
        const statusButtons = `
            <button class="order-btn" data-id="${order.id}" data-next="to_ship">To Ship</button>
            <button class="order-btn" data-id="${order.id}" data-next="to_receive">To Receive</button>
            <button class="order-btn" data-id="${order.id}" data-next="to_review">To Review</button>
        `;
        return `<tr>
            <td>${product ? product.name : order.id}</td>
            <td>${product ? currency(product.price) : "-"}</td>
            <td>${firstItem ? firstItem.quantity : "-"}</td>
            <td>${currency(order.total)}</td>
            <td>${statusButtons}</td>
        </tr>`;
    }).join("")}</tbody></table>` : "<p>No orders found.</p>";

    root.querySelectorAll("[data-next]").forEach((btn) => {
        btn.addEventListener("click", () => {
            const allOrders = readJson(STORE_KEYS.orders, []);
            const order = allOrders.find((item) => item.id === btn.dataset.id);
            if (!order) return;
            order.status = btn.dataset.next;
            writeJson(STORE_KEYS.orders, allOrders);
            renderOrdersPage();
        });
    });
}

function renderSellerProductsPage() {
    const form = document.getElementById("sellerProductForm");
    const listRoot = document.getElementById("sellerProductsList");
    if (!form || !listRoot) return;

    const renderList = () => {
        const products = getProducts();
        listRoot.innerHTML = products.map((item) => `
            <div class="cart-row seller-row" data-id="${item.id}">
                <img src="${item.image}" alt="${item.name}">
                <div><strong>${item.name}</strong><div>${item.category}</div><div class="price">${currency(item.price)}</div></div>
                <div class="action-row">
                    <button class="order-btn" data-action="edit">Edit</button>
                    <button class="remove-btn" data-action="delete">Delete</button>
                </div>
            </div>
        `).join("");

        listRoot.querySelectorAll(".seller-row button").forEach((btn) => {
            btn.addEventListener("click", () => {
                const row = btn.closest(".seller-row");
                const id = Number(row.dataset.id);
                const productsData = getProducts();
                const target = productsData.find((item) => item.id === id);
                if (!target) return;
                if (btn.dataset.action === "delete") {
                    writeJson(STORE_KEYS.products, productsData.filter((item) => item.id !== id));
                } else {
                    const name = prompt("Update product name", target.name);
                    if (!name) return;
                    target.name = name;
                    writeJson(STORE_KEYS.products, productsData);
                }
                renderList();
            });
        });
    };

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        const name = document.getElementById("sellerName").value.trim();
        const description = document.getElementById("sellerDesc").value.trim();
        const price = Number(document.getElementById("sellerPrice").value);
        const category = document.getElementById("sellerCategory").value;
        const image = document.getElementById("sellerImage").value.trim() || CATEGORY_META[category].image;
        if (!name || !description || !price || !category) return;
        const products = getProducts();
        products.push({
            id: products.length ? Math.max(...products.map((item) => item.id)) + 1 : 1,
            name,
            description,
            price,
            category,
            image
        });
        writeJson(STORE_KEYS.products, products);
        form.reset();
        renderList();
    });

    renderList();
}

ensureStore();
renderHomePage();
renderCategoryPage();
renderCartPage();
renderProfilePage();
renderCheckoutPage();
renderOrderSuccessPage();
renderOrdersPage();
renderSellerProductsPage();
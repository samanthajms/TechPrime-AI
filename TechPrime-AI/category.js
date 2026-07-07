    function makeProducts(categoryName) {
        const prices = [1999, 2499, 3199, 4299, 5499, 6299, 7499, 8399, 9299, 10499];
        const list = [];
        for (let i = 1; i <= 30; i += 1) {
            list.push({
                name: `${categoryName} Product ${String(i).padStart(2, "0")}`,
                price: prices[i % prices.length] + i * 20,
                image: `https://picsum.photos/seed/${categoryName.toLowerCase()}-${i}/400/300`
            });
        }
        return list;
    }

    function currency(value) {
        return `₱${value.toFixed(2)}`;
    }

    function renderCategoryPage() {
        const root = document.getElementById("categoryRoot");
        if (!root) return;
        const category = root.dataset.category || "Category";
        const title = document.getElementById("categoryTitle");
        title.textContent = `${category} Products`;

        const grid = document.getElementById("categoryProductsGrid");
        const products = makeProducts(category);

        grid.innerHTML = products.map((item) => `
            <article class="product-card-large hover-lift">
                <img src="${item.image}" alt="${item.name}">
                <div class="body">
                    <h4 class="product-title">${item.name}</h4>
                    <p class="price">${currency(item.price)}</p>
                </div>
            </article>
        `).join("");
    }

    renderCategoryPage();

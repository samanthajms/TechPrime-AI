document.addEventListener("DOMContentLoaded", () => {
    const sidebarItems = document.querySelectorAll(".sidebar-item");
    const shopViewsList = document.getElementById("shopViewsList");
    const recentActivitiesList = document.getElementById("recentActivitiesList");
    const canvas = document.getElementById("salesChart");

    // Dummy Data
    const shopViews = [
        { username: "Username 01", time: "3 mins ago" },
        { username: "Username 02", time: "7 mins ago" },
        { username: "Username 03", time: "10 mins ago" },
        { username: "Username 04", time: "16 mins ago" },
        { username: "Username 05", time: "22 mins ago" },
        { username: "Username 06", time: "31 mins ago" },
        { username: "Username 07", time: "1 hour ago" },
        { username: "Username 08", time: "2 hours ago" }
    ];

    const activities = [
        "Username 01 made a purchase in your shop",
        "Username 01 claims a voucher",
        "Username 01 ask for refunding",
        "Username 01 made an order return request",
        "Username 02 made a purchase in your shop",
        "Username 03 claims a voucher"
    ];

    // Navigation logic
    sidebarItems.forEach((item) => {
        item.addEventListener("click", () => {
            const target = item.dataset.target;
            if (target === "My Products") {
                window.location.href = "seller_products.php";
                return;
            }
            console.log(`Redirect to ${target}`);
        });
    });

    // Render Shop Views
    if (shopViewsList) {
        shopViewsList.innerHTML = shopViews.map((item) => `
            <div class="row-item view-row">
                <div>
                    <div><strong>${item.username}</strong> recently viewed my shop</div>
                    <div class="view-meta">${item.time}</div>
                </div>
                <a class="profile-link" href="#" data-username="${item.username}">View user profile</a>
            </div>
        `).join("");
    }

    // Render Activities
    if (recentActivitiesList) {
        recentActivitiesList.innerHTML = activities.map((activity) => `<div class="row-item">${activity}</div>`).join("");
    }

    // Chart Logic
    if (canvas && typeof Chart !== "undefined") {
        const ctx = canvas.getContext("2d");
        const gradient = ctx.createLinearGradient(0, 0, 0, 320);
        gradient.addColorStop(0, "rgba(9, 152, 168, 0.35)");
        gradient.addColorStop(1, "rgba(9, 152, 168, 0.03)");

        new Chart(canvas, {
            type: "line",
            data: {
                labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
                datasets: [{
                    data: [70, 20, 35, 25, 45, 15, 60, 20, 60, 55, 95, 45],
                    borderColor: "#0998a8",
                    borderWidth: 3,
                    fill: true,
                    backgroundColor: gradient,
                    tension: 0.3,
                    pointRadius: 3.5,
                    pointBackgroundColor: "#0998a8"
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { min: 0, max: 100, ticks: { stepSize: 10 }, grid: { color: "#e6edf0" } },
                    x: { grid: { color: "#edf2f4" } }
                }
            }
        });
    } else {
        console.error("Chart.js library not loaded or canvas not found.");
    }
});
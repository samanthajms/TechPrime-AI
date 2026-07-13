const CURRENT_USER_KEY = "ias_current_user";
const AUTH_API = "backend/api/auth.php";

async function authRequest(payload) {
    const response = await fetch(AUTH_API, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams(payload).toString()
    });
    return response.json();
}

function initRegisterPage() {
    const registerForm = document.getElementById("registerForm");
    const registerBtn  = document.getElementById("registerBtn");
    const captchaCheckbox = document.getElementById("captchaCheckbox");

    if (captchaCheckbox && registerBtn) {
        captchaCheckbox.addEventListener("change", () => {
            registerBtn.disabled = !captchaCheckbox.checked;
        });
    }

    if (!registerForm) return;

    registerForm.addEventListener("submit", (event) => {
        event.preventDefault();
        const name            = document.getElementById("regName").value.trim();
        const surname         = document.getElementById("regSurname").value.trim();
        const age             = Number(document.getElementById("regAge").value);
        const address         = document.getElementById("regAddress").value.trim();
        const email           = document.getElementById("regEmail").value.trim().toLowerCase();
        const password        = document.getElementById("regPassword").value;
        const confirmPassword = document.getElementById("regConfirmPassword").value;
        const role            = document.getElementById("regRole").value;

        if (!name || !surname || !age || !address || !email || !password || !confirmPassword) {
            IAS_UI.alert("Please complete all fields.", "error");
            return;
        }
        if (password !== confirmPassword) {
            IAS_UI.alert("Passwords do not match.", "error");
            return;
        }

        authRequest({ action: "register", name, surname, age: String(age), address, email, password, role })
            .then((data) => {
                if (!data.success) {
                    IAS_UI.alert(data.message || "Registration failed.", "error");
                    return;
                }

                // Show Gmail activation notice
                IAS_UI.alert(
                    data.message || "Registration successful! Please check your Gmail to activate your account.",
                    "success"
                );

                registerForm.reset();
                const complexity = document.getElementById('passwordComplexity');
                if (complexity) complexity.style.display = 'none';
                if (captchaCheckbox) captchaCheckbox.checked = false;
                if (registerBtn) registerBtn.disabled = true;

                // Show inline activation reminder if the container exists
                const activationNotice = document.getElementById("activationNotice");
                if (activationNotice) {
                    activationNotice.style.display = "block";
                    activationNotice.querySelector(".notice-email").textContent = email;
                }
            });
    });
}

function initLoginPage() {
    const loginForm = document.getElementById("loginForm");
    const totpForm  = document.getElementById("totpForm");
    const setupForm = document.getElementById("totpSetupForm");

    if (!loginForm) return;

    loginForm.addEventListener("submit", (event) => {
        event.preventDefault();
        const email    = document.getElementById("loginEmail").value.trim().toLowerCase();
        const password = document.getElementById("loginPassword").value;

        authRequest({ action: "login", email, password })
            .then((data) => {
                if (!data.success) {
                    IAS_UI.alert(data.message || "Invalid email or password.", "error");
                    return;
                }

                if (data.require_totp) {
                    loginForm.style.display = "none";

                    if (data.totp_setup) {
                        // First time — show QR setup screen
                        if (setupForm) {
                            setupForm.style.display = "block";
                            const qrImg = document.getElementById("totpQR");
                            if (qrImg) qrImg.src = data.qr_url;
                            const secretEl = document.getElementById("totpSecretDisplay");
                            if (secretEl) secretEl.textContent = data.totp_secret;
                        } else {
                            // Fallback: redirect to PHP setup page
                            window.location.href = "login.php?totp_setup=1";
                        }
                    } else {
                        // Returning user — show TOTP code entry
                        if (totpForm) {
                            totpForm.style.display = "block";
                        } else {
                            window.location.href = "login.php?mfa=1";
                        }
                    }
                    return;
                }
            });
    });

    // TOTP verification for returning users
    if (totpForm) {
        totpForm.addEventListener("submit", (event) => {
            event.preventDefault();
            const totpCode = document.getElementById("totpCode").value.trim();

            authRequest({ action: "verify_totp", totp_code: totpCode })
                .then((data) => {
                    if (!data.success) {
                        IAS_UI.alert(data.message || "Invalid authenticator code.", "error");
                        return;
                    }
                    IAS_UI.alert("Login successful! Redirecting...", "success");
                    localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
                    setTimeout(() => redirectByRole(data.user.role), 1500);
                });
        });
    }

    // TOTP setup confirmation
    if (setupForm) {
        setupForm.addEventListener("submit", (event) => {
            event.preventDefault();
            const totpCode = document.getElementById("setupTotpCode").value.trim();

            authRequest({ action: "confirm_totp_setup", totp_code: totpCode })
                .then((data) => {
                    if (!data.success) {
                        IAS_UI.alert(data.message || "Invalid code. Try again.", "error");
                        return;
                    }
                    IAS_UI.alert("Google Authenticator set up! Redirecting...", "success");
                    localStorage.setItem(CURRENT_USER_KEY, JSON.stringify(data.user));
                    setTimeout(() => redirectByRole(data.user.role), 1500);
                });
        });
    }
}

function redirectByRole(role) {
    if (role === 'admin')   window.location.href = "ADMIN/admin_dashboard.php";
    else if (role === 'seller' || role === 'retail_officer') window.location.href = "RETAIL/retail_dashboard.php";
    else if (role === 'courier') window.location.href = "courier/courier_dashboard.php";
    else                         window.location.href = "CLIENT/index.php";
}

// Global Logout
function logout() {
    fetch("backend/api/auth.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "action=logout"
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            IAS_UI.alert("Logout successful!", "success");
            localStorage.removeItem(CURRENT_USER_KEY);
            setTimeout(() => { window.location.href = "/login.php"; }, 1500);
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initRegisterPage();
    initLoginPage();
});

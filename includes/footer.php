<?php if ($loggedInUser || $loggedInClient): ?>
        </div><!-- /content -->
    </div><!-- /main-area -->
</div><!-- /app-shell -->
<script>
    (() => {
        const shell = document.getElementById("app-shell");
        const btn = document.getElementById("hamburger-btn");
        const overlay = document.getElementById("sidebar-overlay");
        if (!shell || !btn || !overlay) return;

        const open = () => shell.classList.add("sidebar-open");
        const close = () => shell.classList.remove("sidebar-open");

        btn.addEventListener("click", open);
        overlay.addEventListener("click", close);
        // Cerrar automaticamente al navegar (tocar un link del menu)
        shell.querySelectorAll(".sidebar a.nav-link").forEach((link) => {
            link.addEventListener("click", close);
        });

        // Submenu colapsable "OnlineCam" (los 7 modulos en vivo)
        const ocToggle = document.getElementById("onlinecam-toggle");
        const ocSubmenu = document.getElementById("onlinecam-submenu");
        const ocArrow = document.getElementById("onlinecam-arrow");
        if (ocToggle && ocSubmenu) {
            ocToggle.addEventListener("click", () => {
                const isOpen = ocSubmenu.classList.toggle("open");
                if (ocArrow) ocArrow.textContent = isOpen ? "▴" : "▾";
            });
            if (ocSubmenu.classList.contains("open") && ocArrow) {
                ocArrow.textContent = "▴";
            }
        }
    })();
</script>
<?php else: ?>
</div><!-- /auth-wrap -->
<?php endif; ?>
<script src="<?= BASE_URL ?>/assets/js/theme.js"></script>
</body>
</html>

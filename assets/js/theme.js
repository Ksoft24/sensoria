(function () {
    var KEY = "sensoria-theme";
    var root = document.documentElement;

    function labelFor(theme) {
        return theme === "dark" ? "☀️ Modo claro" : "🌙 Modo oscuro";
    }

    function apply(theme) {
        root.setAttribute("data-theme", theme);
        localStorage.setItem(KEY, theme);
        var btn = document.getElementById("theme-toggle");
        if (btn) btn.textContent = labelFor(theme);
    }

    // Aplicar cuanto antes (evita parpadeo del tema por defecto)
    apply(localStorage.getItem(KEY) || "dark");

    document.addEventListener("DOMContentLoaded", function () {
        var btn = document.getElementById("theme-toggle");
        if (!btn) return;
        btn.addEventListener("click", function () {
            var current = root.getAttribute("data-theme");
            apply(current === "dark" ? "light" : "dark");
        });
    });
})();

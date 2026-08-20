(function () {
    function bindFilter(filterId, selectId) {
        const filter = document.getElementById(filterId);
        const select = document.getElementById(selectId);
        if (!filter || !select) {
            return;
        }
        filter.addEventListener("input", function () {
            const q = filter.value.trim().toLowerCase();
            Array.prototype.forEach.call(select.options, function (opt) {
                const hay = (opt.text + " " + opt.value).toLowerCase();
                opt.hidden = q !== "" && hay.indexOf(q) === -1;
            });
        });
    }
    bindFilter("chat-filter", "chat-model");
    bindFilter("image-filter", "image-model");

    function optionLabel(model) {
        const name = model.name || model.id;
        if (model.price) {
            return name + " (" + model.price + ")";
        }
        return name + " (" + model.id + ")";
    }

    function fillSelect(select, models) {
        const current = select.value;
        select.innerHTML = "";
        (models || []).forEach(function (model) {
            const opt = document.createElement("option");
            opt.value = model.id;
            opt.textContent = optionLabel(model);
            if (model.id === current) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
        if (current && select.value !== current) {
            const opt = document.createElement("option");
            opt.value = current;
            opt.textContent = current;
            opt.selected = true;
            select.insertBefore(opt, select.firstChild);
        }
        const filter = document.getElementById(select.id === "chat-model" ? "chat-filter" : "image-filter");
        if (filter) {
            filter.dispatchEvent(new Event("input"));
        }
    }

    const refreshBtn = document.getElementById("refresh-models");
    const status = document.getElementById("models-status");
    const csrfInput = document.querySelector('input[name="_csrf"]');
    if (!refreshBtn) {
        return;
    }
    refreshBtn.addEventListener("click", async function () {
        refreshBtn.disabled = true;
        if (status) {
            status.textContent = "Loading models from OpenRouter…";
        }
        try {
            const res = await fetch("/cp/api/models/refresh", {
                method: "POST",
                headers: {
                    "X-CSRF-Token": csrfInput ? csrfInput.value : "",
                    Accept: "application/json",
                },
            });
            const data = await res.json();
            if (!data.success) {
                if (status) {
                    status.textContent = data.message || "Could not refresh models.";
                }
                return;
            }
            fillSelect(document.getElementById("chat-model"), data.data.chat || []);
            fillSelect(document.getElementById("image-model"), data.data.image || []);
            if (status) {
                const chatCount = (data.data.chat || []).length;
                const imageCount = (data.data.image || []).length;
                status.textContent = "Updated " + chatCount + " chat models and " + imageCount + " image models.";
            }
        } catch (e) {
            if (status) {
                status.textContent = "Could not refresh models.";
            }
        } finally {
            refreshBtn.disabled = false;
        }
    });
})();

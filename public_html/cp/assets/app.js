(function () {
    const csrf = window.CP.csrf;
    const history = Array.isArray(window.CP.history) ? window.CP.history : [];
    const log = document.getElementById("chat-log");
    const form = document.getElementById("chat-form");
    const input = document.getElementById("chat-input");
    const sendBtn = document.getElementById("send-btn");
    const restoreConfirmBtn = document.getElementById("restore-confirm-btn");
    const restoreModalEl = document.getElementById("restoreModal");
    const frame = document.getElementById("staging-frame");
    const drop = document.getElementById("chat-drop");
    const chipBox = document.getElementById("chat-attachments");
    const activity = document.getElementById("chat-activity");
    let runId = null;
    let abort = null;
    let attachments = [];
    let previewHighlight = null;
    let pendingRestoreId = null;
    let autoLooping = false;
    let autoStop = false;

    if (!form) {
        return;
    }

    const draft = sessionStorage.getItem("cp.composerDraft");
    if (draft !== null) {
        sessionStorage.removeItem("cp.composerDraft");
        input.value = draft;
        input.focus();
    }

    (function initSplit() {
        const split = document.querySelector(".cp-split");
        const chat = document.getElementById("chat-pane");
        const handle = document.getElementById("split-handle");
        if (!split || !chat || !handle) {
            return;
        }
        const stored = window.localStorage.getItem("cp.splitChatPct");
        if (stored) {
            const pct = parseFloat(stored);
            if (!isNaN(pct) && pct > 0) {
                chat.style.flexBasis = pct + "%";
                chat.style.width = pct + "%";
            }
        }
        let dragging = false;
        function applyFromClientX(x) {
            const rect = split.getBoundingClientRect();
            if (rect.width <= 0) {
                return;
            }
            const min = Math.min(192, rect.width * 0.2);
            const max = rect.width - min - 6;
            let width = x - rect.left;
            width = Math.max(min, Math.min(max, width));
            const pct = (width / rect.width) * 100;
            chat.style.flexBasis = pct + "%";
            chat.style.width = pct + "%";
            window.localStorage.setItem("cp.splitChatPct", String(pct));
        }
        handle.addEventListener("pointerdown", function (event) {
            dragging = true;
            split.classList.add("is-dragging");
            handle.setPointerCapture(event.pointerId);
            event.preventDefault();
        });
        handle.addEventListener("pointermove", function (event) {
            if (!dragging) {
                return;
            }
            applyFromClientX(event.clientX);
        });
        function stopDrag() {
            dragging = false;
            split.classList.remove("is-dragging");
        }
        handle.addEventListener("pointerup", stopDrag);
        handle.addEventListener("pointercancel", stopDrag);
        handle.addEventListener("keydown", function (event) {
            if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") {
                return;
            }
            event.preventDefault();
            const rect = split.getBoundingClientRect();
            const current = chat.getBoundingClientRect().width;
            const delta = event.key === "ArrowLeft" ? -24 : 24;
            applyFromClientX(rect.left + current + delta);
        });
    })();

    (function initPreviewSwitcher() {
        const pane = document.querySelector(".cp-pane-preview");
        const switcher = document.getElementById("preview-switcher");
        if (!pane || !switcher) {
            return;
        }
        const buttons = switcher.querySelectorAll("[data-preview]");
        function setMode(mode) {
            if (mode !== "desktop" && mode !== "tablet" && mode !== "phone") {
                mode = "desktop";
            }
            pane.dataset.preview = mode;
            Array.prototype.forEach.call(buttons, function (btn) {
                const on = btn.getAttribute("data-preview") === mode;
                btn.classList.toggle("btn-secondary", on);
                btn.classList.toggle("btn-outline-secondary", !on);
                btn.setAttribute("aria-pressed", on ? "true" : "false");
            });
            window.localStorage.setItem("cp.previewMode", mode);
        }
        const stored = window.localStorage.getItem("cp.previewMode");
        if (stored) {
            setMode(stored);
        }
        switcher.addEventListener("click", function (event) {
            const btn = event.target.closest("[data-preview]");
            if (!btn) {
                return;
            }
            setMode(btn.getAttribute("data-preview"));
        });
    })();

    (function initPreviewNav() {
        const backBtn = document.getElementById("preview-back");
        const reloadBtn = document.getElementById("preview-reload");
        const hardReloadBtn = document.getElementById("preview-hard-reload");
        const pathInput = document.getElementById("preview-path");
        const openTab = document.getElementById("preview-open-tab");
        const root = (window.CP.stagingUrl || "/staging/").replace(/\/?$/, "/");
        if (!frame || !pathInput) {
            return;
        }
        function frameLocation() {
            try {
                const loc = frame.contentWindow.location;
                if (loc.origin !== window.location.origin) {
                    return null;
                }
                return loc;
            } catch (e) {
                return null;
            }
        }
        function displayPath() {
            const loc = frameLocation();
            if (!loc) {
                return "/";
            }
            let path = loc.pathname || "/";
            if (path.indexOf("/staging") === 0) {
                path = path.slice("/staging".length) || "/";
            }
            if (path.indexOf("/cp/preview") === 0) {
                path = path.slice("/cp/preview".length) || "/";
            }
            if (path === "" || path === "/index.php") {
                path = "/";
            }
            const params = new URLSearchParams(loc.search);
            params.delete("_cp");
            const qs = params.toString();
            return path + (qs ? "?" + qs : "") + (loc.hash || "");
        }
        function previewTabUrl() {
            let shown = displayPath();
            const hashIndex = shown.indexOf("#");
            const hash = hashIndex >= 0 ? shown.slice(hashIndex) : "";
            if (hashIndex >= 0) {
                shown = shown.slice(0, hashIndex);
            }
            if (!shown || shown === "/") {
                return "/cp/preview/" + hash;
            }
            return "/cp/preview/" + shown.replace(/^\//, "") + hash;
        }
        function syncPath() {
            if (document.activeElement === pathInput) {
                return;
            }
            pathInput.value = displayPath();
            if (openTab) {
                openTab.href = previewTabUrl();
            }
        }
        function withCacheBust(href, bust) {
            try {
                const url = new URL(href, window.location.origin);
                url.searchParams.set("_cp", String(bust));
                return url.toString();
            } catch (e) {
                return href;
            }
        }
        function bustPreviewAssets(doc, bust) {
            if (!doc) {
                return;
            }
            const nodes = doc.querySelectorAll("link[rel~='stylesheet'][href], script[src], img[src], source[src]");
            Array.prototype.forEach.call(nodes, function (el) {
                const attr = el.hasAttribute("href") ? "href" : "src";
                const val = el.getAttribute(attr);
                if (!val || val.indexOf("data:") === 0 || val.indexOf("blob:") === 0) {
                    return;
                }
                try {
                    const url = new URL(val, doc.baseURI);
                    if (url.origin !== window.location.origin) {
                        return;
                    }
                    url.searchParams.set("_cp", String(bust));
                    el.setAttribute(attr, url.toString());
                } catch (e) {
                    /* ignore */
                }
            });
        }
        function reloadPreview() {
            const loc = frameLocation();
            if (loc) {
                loc.reload();
                return;
            }
            frame.src = frame.src;
        }
        function hardReloadPreview() {
            const bust = Date.now();
            const loc = frameLocation();
            const href = loc ? loc.href : (frame.src || root);
            const onLoad = function () {
                frame.removeEventListener("load", onLoad);
                try {
                    bustPreviewAssets(frame.contentDocument, bust);
                } catch (e) {
                    /* ignore */
                }
            };
            frame.addEventListener("load", onLoad);
            frame.src = withCacheBust(href, bust);
        }
        function goTo(value) {
            let v = (value || "").trim();
            if (v.indexOf("..") !== -1) {
                return;
            }
            if (!v || v === "/") {
                frame.src = root;
                return;
            }
            if (v.indexOf("/staging/") === 0) {
                frame.src = v;
                return;
            }
            v = v.replace(/^\//, "");
            frame.src = root + v;
        }
        window.CP.previewPath = function () {
            return displayPath();
        };
        window.CP.reloadPreview = hardReloadPreview;
        frame.addEventListener("load", syncPath);
        if (backBtn) {
            backBtn.addEventListener("click", function () {
                const loc = frameLocation();
                if (loc) {
                    frame.contentWindow.history.back();
                }
            });
        }
        if (reloadBtn) {
            reloadBtn.addEventListener("click", reloadPreview);
        }
        if (hardReloadBtn) {
            hardReloadBtn.addEventListener("click", hardReloadPreview);
        }
        pathInput.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                goTo(pathInput.value);
                pathInput.blur();
            }
        });
        pathInput.addEventListener("blur", syncPath);
        syncPath();
    })();

    function append(kind, text, options) {
        const div = document.createElement("div");
        div.className = "msg msg-" + kind;
        div.appendChild(document.createTextNode(text));
        if (kind === "error" && options && options.details) {
            div.appendChild(document.createTextNode(" "));
            div.appendChild(errorInfo(options.details));
        }
        const turn = lastTurn();
        if (turn && (kind === "assistant" || kind === "error")) {
            turn.appendChild(div);
        } else {
            log.appendChild(div);
        }
        log.scrollTop = log.scrollHeight;
        return div;
    }

    function appendTurn(text, id, activity) {
        const turn = document.createElement("div");
        turn.className = "msg-turn";
        const user = document.createElement("div");
        user.className = "msg msg-user";
        user.appendChild(document.createTextNode(text));
        if (id) {
            decorateUser(user, id);
        }
        turn.appendChild(user);
        const act = document.createElement("div");
        act.className = "msg-activity";
        act.setAttribute("aria-label", "What the assistant did");
        (activity || []).forEach(function (item) {
            addActivityLine(act, item);
        });
        turn.appendChild(act);
        log.appendChild(turn);
        log.scrollTop = log.scrollHeight;
        return { turn: turn, user: user, activity: act };
    }

    function isQuietNote(text) {
        if (
            text === "Paused. Press Continue to keep going."
            || text === "Waiting for the model…"
            || text === "Waiting for the model..."
            || text === "Stopped."
        ) {
            return true;
        }
        return text.indexOf("Paused:") === 0;
    }

    function isToolItem(item) {
        if (!item || !item.text) {
            return false;
        }
        if (item.kind === "tool") {
            return true;
        }
        return item.text.indexOf("Using ") === 0;
    }

    function toolIconClass(name) {
        const icons = {
            read_file: "bi-file-earmark-text",
            write_file: "bi-pencil-square",
            edit_file: "bi-pencil",
            list_dir: "bi-folder2-open",
            search: "bi-search",
            mkdir: "bi-folder-plus",
            copy_file: "bi-copy",
            rename: "bi-input-cursor-text",
            delete: "bi-trash",
            fetch_page: "bi-globe",
            inspect_page: "bi-eye",
            inspect_draft: "bi-eye",
            list_site: "bi-diagram-3",
            fetch_image: "bi-image",
            generate_image: "bi-image",
            list_inbox: "bi-inbox",
            read_inbox: "bi-inbox",
            import_to_staging: "bi-box-arrow-in-down",
        };
        return icons[name] || "bi-wrench";
    }

    function humanToolName(name) {
        const labels = {
            read_file: "Read file",
            write_file: "Wrote file",
            edit_file: "Edited file",
            list_dir: "Listed folder",
            search: "Searched",
            mkdir: "Created folder",
            copy_file: "Copied file",
            rename: "Renamed",
            delete: "Deleted",
            fetch_page: "Fetched page",
            inspect_page: "Inspected page",
            inspect_draft: "Inspected draft",
            list_site: "Listed site",
            fetch_image: "Fetched image",
            generate_image: "Generated image",
            list_inbox: "Listed inbox",
            read_inbox: "Read inbox",
            import_to_staging: "Imported to draft",
        };
        if (labels[name]) {
            return labels[name];
        }
        return (name || "Tool").replace(/_/g, " ");
    }

    function parseTool(item) {
        const text = item.text || "";
        const match = text.match(/^Using\s+(\S+)(?:\s+[—–-]\s+(.*))?$/);
        const name = item.name || (match ? match[1] : "");
        return {
            name: name,
            label: humanToolName(name),
            detail: match && match[2] ? match[2] : "",
        };
    }

    function actionsBox(host) {
        let box = host.querySelector(":scope > .msg-actions");
        if (box) {
            return box;
        }
        box = document.createElement("details");
        box.className = "msg-actions";
        box.open = true;
        const summary = document.createElement("summary");
        summary.className = "msg-actions-summary";
        summary.textContent = "Working";
        box.appendChild(summary);
        const list = document.createElement("div");
        list.className = "msg-actions-list";
        box.appendChild(list);
        host.appendChild(box);
        return box;
    }

    function updateActionsSummary(box) {
        const n = box.querySelectorAll(".msg-action-tool").length;
        const summary = box.querySelector("summary");
        if (!summary) {
            return;
        }
        if (n === 0) {
            summary.textContent = "Thought";
        } else if (n === 1) {
            summary.textContent = "1 tool";
        } else {
            summary.textContent = n + " tools";
        }
    }

    function addActivityLine(host, item) {
        if (!host || !item || !item.text || isQuietNote(item.text)) {
            return;
        }
        const box = actionsBox(host);
        const list = box.querySelector(".msg-actions-list");
        const last = list.lastElementChild;
        const sameText = last && last.getAttribute("data-text") === item.text;
        if (sameText) {
            return;
        }
        const row = document.createElement("div");
        row.setAttribute("data-text", item.text);
        const icon = document.createElement("i");
        icon.setAttribute("aria-hidden", "true");
        const body = document.createElement("span");
        body.className = "msg-action-body";
        const label = document.createElement("span");
        label.className = "msg-action-label";
        if (isToolItem(item)) {
            const parsed = parseTool(item);
            row.className = "msg-action msg-action-tool";
            icon.className = "bi " + toolIconClass(parsed.name);
            label.textContent = parsed.label;
            body.appendChild(label);
            if (parsed.detail) {
                const detail = document.createElement("span");
                detail.className = "msg-action-detail";
                detail.textContent = parsed.detail;
                detail.title = parsed.detail;
                body.appendChild(detail);
            }
        } else {
            row.className = "msg-action msg-action-thought";
            icon.className = "bi bi-circle";
            label.textContent = item.text;
            body.appendChild(label);
        }
        row.appendChild(icon);
        row.appendChild(body);
        list.appendChild(row);
        updateActionsSummary(box);
        log.scrollTop = log.scrollHeight;
    }

    function activityHostFor(userEl) {
        if (userEl) {
            const turn = userEl.closest(".msg-turn");
            if (turn) {
                return turn.querySelector(".msg-activity");
            }
        }
        const turns = log.querySelectorAll(".msg-turn");
        if (!turns.length) {
            return null;
        }
        return turns[turns.length - 1].querySelector(".msg-activity");
    }

    function lastTurn() {
        const turns = log.querySelectorAll(".msg-turn");
        return turns.length ? turns[turns.length - 1] : null;
    }

    function errorInfo(details) {
        const info = document.createElement("span");
        info.className = "error-info";
        info.setAttribute("tabindex", "0");
        info.setAttribute("aria-label", "Error details");
        const icon = document.createElement("i");
        icon.className = "bi bi-info-circle";
        icon.setAttribute("aria-hidden", "true");
        info.appendChild(icon);
        const tip = document.createElement("span");
        tip.className = "error-info-tip";
        tip.textContent = details;
        let hideTimer = null;
        function cancelHide() {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
        }
        function hide() {
            cancelHide();
            tip.classList.remove("is-open");
            if (tip.parentNode) {
                tip.parentNode.removeChild(tip);
            }
        }
        function scheduleHide() {
            cancelHide();
            hideTimer = setTimeout(hide, 120);
        }
        function show() {
            cancelHide();
            if (!tip.parentNode) {
                document.body.appendChild(tip);
            }
            const margin = 8;
            const r = info.getBoundingClientRect();
            const maxW = Math.min(32 * 16, window.innerWidth - margin * 2);
            tip.style.maxWidth = maxW + "px";
            tip.style.left = margin + "px";
            tip.style.top = margin + "px";
            tip.style.bottom = "auto";
            tip.classList.add("is-open");
            const spaceBelow = window.innerHeight - r.bottom - margin;
            const spaceAbove = r.top - margin;
            const preferBelow = spaceBelow >= spaceAbove;
            const available = Math.max(spaceBelow, spaceAbove);
            tip.style.maxHeight = Math.max(96, available - 6) + "px";
            const th = tip.offsetHeight;
            const tw = Math.min(tip.offsetWidth, maxW);
            let top;
            if (preferBelow) {
                top = r.bottom + 6;
                if (top + th > window.innerHeight - margin) {
                    top = Math.max(margin, window.innerHeight - th - margin);
                }
            } else {
                top = r.top - 6 - th;
                if (top < margin) {
                    top = margin;
                }
            }
            let left = r.left;
            if (left + tw > window.innerWidth - margin) {
                left = window.innerWidth - tw - margin;
            }
            if (left < margin) {
                left = margin;
            }
            tip.style.left = left + "px";
            tip.style.top = top + "px";
        }
        info.addEventListener("mouseenter", show);
        info.addEventListener("mouseleave", scheduleHide);
        info.addEventListener("focus", show);
        info.addEventListener("blur", scheduleHide);
        tip.addEventListener("mouseenter", function () {
            cancelHide();
            show();
        });
        tip.addEventListener("mouseleave", scheduleHide);
        return info;
    }

    function decorateUser(el, id) {
        el.dataset.messageId = id;
        el.classList.add("msg-restorable");
        el.setAttribute("title", "Restore the draft to this message");
        if (!el.querySelector(".msg-restore")) {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "btn btn-sm btn-outline-secondary msg-restore";
            btn.textContent = "Restore";
            el.appendChild(btn);
        }
        if (el.dataset.restoreBound === "1") {
            return;
        }
        el.dataset.restoreBound = "1";
        el.addEventListener("click", function (event) {
            const target = event.target;
            const messageId = el.dataset.messageId;
            if (!messageId) {
                return;
            }
            if (target && target.closest && target.closest("button")) {
                event.stopPropagation();
            }
            promptRestore(messageId);
        });
    }

    function promptRestore(id) {
        if (runId) {
            append("error", "Wait until the current run finishes before restoring.");
            return;
        }
        pendingRestoreId = id;
        if (window.bootstrap && restoreModalEl) {
            window.bootstrap.Modal.getOrCreateInstance(restoreModalEl).show();
        }
    }

    history.forEach(function (row) {
        if (!row || (row.role !== "user" && row.role !== "assistant")) {
            return;
        }
        if (row.role === "user") {
            appendTurn(row.content || "", row.id || "", row.activity || []);
            return;
        }
        append("assistant", row.content || "");
    });

    function revokePreview(item) {
        if (item && item.preview) {
            URL.revokeObjectURL(item.preview);
            item.preview = "";
        }
    }

    function renderChips() {
        chipBox.innerHTML = "";
        const hasHighlight = previewHighlight !== null;
        if (attachments.length === 0 && !hasHighlight) {
            chipBox.hidden = true;
            return;
        }
        chipBox.hidden = false;
        if (hasHighlight) {
            const chip = document.createElement("span");
            chip.className = "badge text-bg-light border chat-chip chat-chip-highlight";
            const path = previewHighlight.path || "/";
            chip.title = "Highlighted area on " + path;
            const icon = document.createElement("i");
            icon.className = "bi bi-bounding-box";
            icon.setAttribute("aria-hidden", "true");
            const name = document.createElement("span");
            name.className = "chat-chip-name";
            name.textContent = "Highlight";
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn-close btn-close-sm";
            remove.setAttribute("aria-label", "Remove highlight");
            remove.addEventListener("click", function () {
                if (window.CP.clearPreviewHighlight) {
                    window.CP.clearPreviewHighlight();
                } else {
                    previewHighlight = null;
                    renderChips();
                }
            });
            chip.appendChild(icon);
            chip.appendChild(name);
            chip.appendChild(remove);
            chipBox.appendChild(chip);
        }
        attachments.forEach(function (item, index) {
            const chip = document.createElement("span");
            chip.className = "badge text-bg-light border chat-chip";
            chip.title = item.filename || "Attachment";
            if (item.preview) {
                chip.classList.add("chat-chip-image");
                const img = document.createElement("img");
                img.className = "chat-chip-thumb";
                img.src = item.preview;
                img.alt = item.filename || "Attached image";
                chip.appendChild(img);
            } else {
                const name = document.createElement("span");
                name.className = "chat-chip-name";
                name.textContent = item.filename || "file";
                chip.appendChild(name);
            }
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn-close btn-close-sm";
            remove.setAttribute("aria-label", "Remove " + (item.filename || "attachment"));
            remove.addEventListener("click", function () {
                revokePreview(attachments[index]);
                attachments.splice(index, 1);
                renderChips();
            });
            chip.appendChild(remove);
            chipBox.appendChild(chip);
        });
    }

    async function attachFiles(fileList) {
        const files = Array.prototype.slice.call(fileList || []);
        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const preview = (file.type || "").indexOf("image/") === 0 ? URL.createObjectURL(file) : "";
            const draft = {
                filename: file.name || "image",
                preview: preview,
            };
            attachments.push(draft);
            renderChips();
            const body = new FormData();
            body.append("file", file, file.name || "image.png");
            try {
                const res = await fetch("/cp/api/attachments", {
                    method: "POST",
                    headers: { "X-CSRF-Token": csrf },
                    body: body,
                });
                const data = await res.json();
                const index = attachments.indexOf(draft);
                if (!data.success) {
                    if (index >= 0) {
                        attachments.splice(index, 1);
                    }
                    revokePreview(draft);
                    renderChips();
                    append("error", data.message || "Could not attach " + (file.name || "image"));
                    continue;
                }
                const row = data.data || {};
                row.preview = preview;
                if (index >= 0) {
                    attachments[index] = row;
                }
                renderChips();
            } catch (e) {
                const index = attachments.indexOf(draft);
                if (index >= 0) {
                    attachments.splice(index, 1);
                }
                revokePreview(draft);
                renderChips();
                append("error", "Could not attach " + (file.name || "image"));
            }
        }
    }

    ["dragenter", "dragover"].forEach(function (name) {
        drop.addEventListener(name, function (event) {
            event.preventDefault();
            event.stopPropagation();
            drop.classList.add("is-dragover");
        });
    });
    ["dragleave", "drop"].forEach(function (name) {
        drop.addEventListener(name, function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (name === "dragleave" && drop.contains(event.relatedTarget)) {
                return;
            }
            drop.classList.remove("is-dragover");
        });
    });
    drop.addEventListener("drop", function (event) {
        if (event.dataTransfer && event.dataTransfer.files) {
            attachFiles(event.dataTransfer.files);
        }
    });
    input.addEventListener("paste", function (event) {
        const files = event.clipboardData && event.clipboardData.files;
        if (files && files.length) {
            event.preventDefault();
            attachFiles(files);
        }
    });

    (function initPreviewHighlight() {
        const btn = document.getElementById("preview-highlight");
        const layer = document.getElementById("preview-highlight-layer");
        const boxEl = document.getElementById("preview-highlight-box");
        const stage = document.querySelector(".preview-stage");
        if (!btn || !layer || !boxEl || !stage || !frame) {
            return;
        }
        let drawing = false;
        let drag = null;
        let lastHighlightPath = "";
        const skipTags = { HTML: 1, HEAD: 1, BODY: 1, SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1, BR: 1, WBR: 1 };

        function frameWin() {
            try {
                const win = frame.contentWindow;
                if (!win || win.location.origin !== window.location.origin) {
                    return null;
                }
                return win;
            } catch (e) {
                return null;
            }
        }

        function placeLayer() {
            const stageRect = stage.getBoundingClientRect();
            const frameRect = frame.getBoundingClientRect();
            layer.style.left = (frameRect.left - stageRect.left) + "px";
            layer.style.top = (frameRect.top - stageRect.top) + "px";
            layer.style.width = Math.max(0, frameRect.width) + "px";
            layer.style.height = Math.max(0, frameRect.height) + "px";
        }

        function layerSize() {
            return {
                width: Math.max(1, layer.clientWidth || frame.clientWidth || 1),
                height: Math.max(1, layer.clientHeight || frame.clientHeight || 1),
            };
        }

        function showBox(box, win) {
            if (!box || !win) {
                boxEl.hidden = true;
                return;
            }
            const size = layerSize();
            const sx = size.width / Math.max(1, win.innerWidth);
            const sy = size.height / Math.max(1, win.innerHeight);
            boxEl.style.left = ((box.x - win.scrollX) * sx) + "px";
            boxEl.style.top = ((box.y - win.scrollY) * sy) + "px";
            boxEl.style.width = (box.width * sx) + "px";
            boxEl.style.height = (box.height * sy) + "px";
            boxEl.hidden = false;
        }

        function refreshLayer() {
            const win = frameWin();
            const show = drawing || previewHighlight !== null;
            layer.hidden = !show;
            if (show) {
                placeLayer();
            }
            layer.classList.toggle("is-drawing", drawing);
            btn.classList.toggle("btn-secondary", drawing);
            btn.classList.toggle("btn-outline-secondary", !drawing);
            btn.setAttribute("aria-pressed", drawing ? "true" : "false");
            btn.title = drawing
                ? "Drag on the preview to draw a box. Escape cancels."
                : "Highlight an area";
            if (drag && win) {
                showBox(toDocBox(drag.x1, drag.y1, drag.x2, drag.y2, win), win);
            } else if (previewHighlight && win) {
                showBox(previewHighlight.box, win);
            } else if (!drawing) {
                boxEl.hidden = true;
            }
        }

        function setHighlight(value) {
            previewHighlight = value;
            drag = null;
            drawing = false;
            if (value && value.path) {
                lastHighlightPath = value.path;
            }
            refreshLayer();
            renderChips();
        }

        window.CP.clearPreviewHighlight = function () {
            setHighlight(null);
        };

        function layerPoint(event) {
            const rect = layer.getBoundingClientRect();
            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        }

        function toDocBox(x1, y1, x2, y2, win) {
            const size = layerSize();
            const sx = win.innerWidth / size.width;
            const sy = win.innerHeight / size.height;
            return {
                x: Math.round(Math.min(x1, x2) * sx + win.scrollX),
                y: Math.round(Math.min(y1, y2) * sy + win.scrollY),
                width: Math.round(Math.abs(x2 - x1) * sx),
                height: Math.round(Math.abs(y2 - y1) * sy),
            };
        }

        function ident(value) {
            const text = String(value || "");
            if (window.CSS && CSS.escape) {
                return CSS.escape(text);
            }
            return text.replace(/[^A-Za-z0-9_-]/g, "\\$&");
        }

        function cssPath(el, doc) {
            if (!el || el.nodeType !== 1) {
                return "";
            }
            if (el.id && doc.getElementById(el.id) === el) {
                return "#" + ident(el.id);
            }
            const parts = [];
            let node = el;
            while (node && node.nodeType === 1 && node !== doc.documentElement && parts.length < 5) {
                let sel = node.tagName.toLowerCase();
                if (node.id) {
                    parts.unshift("#" + ident(node.id));
                    break;
                }
                const cls = (typeof node.className === "string" ? node.className : "").trim().split(/\s+/).filter(Boolean).slice(0, 2);
                if (cls.length) {
                    sel += cls.map(function (name) { return "." + ident(name); }).join("");
                }
                const parent = node.parentElement;
                if (parent) {
                    const same = Array.prototype.filter.call(parent.children, function (child) {
                        return child.tagName === node.tagName;
                    });
                    if (same.length > 1) {
                        sel += ":nth-of-type(" + (Array.prototype.indexOf.call(same, node) + 1) + ")";
                    }
                }
                parts.unshift(sel);
                node = parent;
            }
            return parts.join(" > ");
        }

        function relAttr(val, doc) {
            if (!val) {
                return "";
            }
            try {
                const url = new URL(val, doc.baseURI);
                if (url.protocol === "javascript:") {
                    return "";
                }
                let path = url.pathname || "";
                if (path.indexOf("/staging/") === 0) {
                    path = path.slice("/staging/".length);
                }
                path = path.replace(/^\//, "");
                url.searchParams.delete("_cp");
                const qs = url.searchParams.toString();
                return path + (qs ? "?" + qs : "") + (url.hash || "");
            } catch (e) {
                return "";
            }
        }

        function clipText(text, max) {
            text = String(text || "").replace(/\s+/g, " ").trim();
            if (text.length <= max) {
                return text;
            }
            return text.slice(0, max - 1) + "…";
        }

        function intersects(el, box, win) {
            const r = el.getBoundingClientRect();
            const left = r.left + win.scrollX;
            const top = r.top + win.scrollY;
            return left < box.x + box.width && left + r.width > box.x && top < box.y + box.height && top + r.height > box.y;
        }

        function collectElements(box, win) {
            const doc = win.document;
            const seen = {};
            const out = [];
            const points = [
                [box.x + box.width / 2, box.y + box.height / 2],
                [box.x + 2, box.y + 2],
                [box.x + box.width - 2, box.y + 2],
                [box.x + 2, box.y + box.height - 2],
                [box.x + box.width - 2, box.y + box.height - 2],
                [box.x + box.width / 2, box.y + 2],
                [box.x + box.width / 2, box.y + box.height - 2],
                [box.x + 2, box.y + box.height / 2],
                [box.x + box.width - 2, box.y + box.height / 2],
            ];
            points.forEach(function (pt) {
                const vx = pt[0] - win.scrollX;
                const vy = pt[1] - win.scrollY;
                if (vx < 0 || vy < 0 || vx > win.innerWidth || vy > win.innerHeight) {
                    return;
                }
                let stack = [];
                try {
                    stack = doc.elementsFromPoint(vx, vy) || [];
                } catch (e) {
                    const one = doc.elementFromPoint(vx, vy);
                    stack = one ? [one] : [];
                }
                stack.forEach(function (el) {
                    if (!el || el.nodeType !== 1 || skipTags[el.tagName] || !intersects(el, box, win)) {
                        return;
                    }
                    const selector = cssPath(el, doc);
                    const key = selector || (el.tagName + (el.id || ""));
                    if (seen[key]) {
                        return;
                    }
                    seen[key] = true;
                    const className = (typeof el.className === "string" ? el.className : "").trim().split(/\s+/).filter(Boolean).slice(0, 3).join(" ");
                    out.push({
                        tag: el.tagName.toLowerCase(),
                        id: el.id || "",
                        class: className,
                        selector: selector,
                        text: clipText(el.innerText || el.textContent || "", 160),
                        src: relAttr(el.getAttribute("src") || "", doc),
                        href: relAttr(el.getAttribute("href") || "", doc),
                    });
                });
            });
            return out.slice(0, 8);
        }

        function captureHighlight(box, win) {
            return {
                path: window.CP.previewPath ? window.CP.previewPath() : "/",
                viewport: { width: win.innerWidth, height: win.innerHeight },
                scroll: { x: Math.round(win.scrollX), y: Math.round(win.scrollY) },
                box: box,
                elements: collectElements(box, win),
            };
        }

        function bindFrame() {
            const win = frameWin();
            if (!win) {
                return;
            }
            win.addEventListener("scroll", refreshLayer, { passive: true });
            win.addEventListener("resize", refreshLayer);
        }

        btn.addEventListener("click", function () {
            drawing = !drawing;
            drag = null;
            refreshLayer();
        });
        layer.addEventListener("pointerdown", function (event) {
            if (!drawing) {
                return;
            }
            event.preventDefault();
            layer.setPointerCapture(event.pointerId);
            const p = layerPoint(event);
            drag = { x1: p.x, y1: p.y, x2: p.x, y2: p.y };
            refreshLayer();
        });
        layer.addEventListener("pointermove", function (event) {
            if (!drag) {
                return;
            }
            const p = layerPoint(event);
            drag.x2 = p.x;
            drag.y2 = p.y;
            refreshLayer();
        });
        function finishDrag(event) {
            if (!drag) {
                return;
            }
            const p = layerPoint(event);
            drag.x2 = p.x;
            drag.y2 = p.y;
            const win = frameWin();
            const box = win ? toDocBox(drag.x1, drag.y1, drag.x2, drag.y2, win) : null;
            drag = null;
            drawing = false;
            if (!win || !box || box.width < 8 || box.height < 8) {
                refreshLayer();
                return;
            }
            setHighlight(captureHighlight(box, win));
        }
        layer.addEventListener("pointerup", finishDrag);
        layer.addEventListener("pointercancel", function () {
            drag = null;
            drawing = false;
            refreshLayer();
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && drawing) {
                drawing = false;
                drag = null;
                refreshLayer();
            }
        });
        frame.addEventListener("load", function () {
            const path = window.CP.previewPath ? window.CP.previewPath() : "";
            if (previewHighlight && lastHighlightPath && path !== lastHighlightPath) {
                setHighlight(null);
            } else {
                refreshLayer();
            }
            lastHighlightPath = path;
            bindFrame();
        });
        if (window.ResizeObserver) {
            const ro = new ResizeObserver(refreshLayer);
            ro.observe(stage);
            ro.observe(frame);
        }
        window.addEventListener("resize", refreshLayer);
        bindFrame();
    })();

    function setActivity(text) {
        activity.textContent = text || "";
    }

    function setRunButton(running) {
        sendBtn.classList.toggle("is-stop", running);
        sendBtn.type = running ? "button" : "submit";
        sendBtn.title = running ? "Stop" : "Send";
        sendBtn.setAttribute("aria-label", running ? "Stop" : "Send");
        sendBtn.innerHTML = running
            ? '<i class="bi bi-sign-stop-fill" aria-hidden="true"></i>'
            : '<i class="bi bi-arrow-up" aria-hidden="true"></i>';
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    function clearInFlight() {
        const prev = abort;
        abort = null;
        if (prev) {
            prev.abort();
        }
    }

    async function stopRun() {
        autoStop = true;
        clearInFlight();
        await fetch("/cp/api/cancel", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
            },
            body: JSON.stringify({ run_id: runId || "", halt_auto: true }),
        });
        runId = null;
        setRunButton(false);
        setActivity("");
        showStopped();
    }

    function removeStopped() {
        Array.prototype.slice.call(log.querySelectorAll(".msg-stopped")).forEach(function (el) {
            el.remove();
        });
    }

    function showStopped() {
        removeStopped();
        const div = document.createElement("div");
        div.className = "msg msg-stopped";
        const text = document.createElement("span");
        text.textContent = "This request was stopped.";
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "btn btn-sm btn-outline-secondary";
        btn.textContent = "Continue now";
        btn.addEventListener("click", function () {
            resumeTurn();
        });
        div.appendChild(text);
        div.appendChild(document.createTextNode(" "));
        div.appendChild(btn);
        const turn = lastTurn();
        if (turn) {
            turn.appendChild(div);
        } else {
            log.appendChild(div);
        }
        log.scrollTop = log.scrollHeight;
    }

    function syncActivity(items) {
        const host = activityHostFor(null);
        if (!host) {
            return;
        }
        const filtered = (items || []).filter(function (item) {
            return item && item.text && !isQuietNote(item.text);
        });
        if (!filtered.length) {
            return;
        }
        const shown = host.querySelectorAll(".msg-action").length;
        for (let i = shown; i < filtered.length; i++) {
            addActivityLine(host, filtered[i]);
        }
    }

    function applyTurn(data) {
        const hist = Array.isArray(data.history) ? data.history : [];
        let lastUser = null;
        hist.forEach(function (row) {
            if (row && row.role === "user") {
                lastUser = row;
            }
        });
        if (lastUser) {
            syncActivity(lastUser.activity || []);
        }
        const serverAssistants = hist.filter(function (row) {
            return row && row.role === "assistant";
        });
        const shownAssistants = log.querySelectorAll(".msg-assistant");
        for (let i = shownAssistants.length; i < serverAssistants.length; i++) {
            append("assistant", serverAssistants[i].content || "");
        }
        if (data.running) {
            if (data.run_id) {
                runId = data.run_id;
            }
            setRunButton(true);
            setActivity("Thinking");
            removeStopped();
            return "running";
        }
        if (!abort) {
            runId = null;
            setRunButton(false);
            setActivity("");
        }
        if (data.can_continue) {
            if (!data.auto_continue && !abort) {
                showStopped();
            }
            return "paused";
        }
        removeStopped();
        return "done";
    }

    async function autoContinueLoop() {
        if (autoLooping) {
            return;
        }
        autoLooping = true;
        autoStop = false;
        try {
            while (!autoStop) {
                if (abort) {
                    await sleep(250);
                    continue;
                }
                let data = {};
                try {
                    const res = await fetch("/cp/api/turn");
                    const body = await res.json();
                    data = body && body.data ? body.data : {};
                } catch (e) {
                    await sleep(2000);
                    continue;
                }
                let state = "paused";
                try {
                    state = applyTurn(data);
                } catch (e) {
                    if (data.can_continue && data.auto_continue) {
                        await continueTurn();
                        continue;
                    }
                    await sleep(2000);
                    continue;
                }
                if (state === "done") {
                    if (window.CP.reloadPreview) {
                        window.CP.reloadPreview();
                    }
                    break;
                }
                if (state === "running") {
                    await sleep(1000);
                    continue;
                }
                if (state === "paused" && data.auto_continue) {
                    await continueTurn();
                    continue;
                }
                break;
            }
        } finally {
            autoLooping = false;
        }
    }

    async function continueTurn() {
        removeStopped();
        await streamChat({
            continue: true,
            preview_path: window.CP.previewPath ? window.CP.previewPath() : "",
        }, null);
    }

    async function resumeTurn() {
        autoStop = false;
        clearInFlight();
        removeStopped();
        await continueTurn();
        if (!autoStop) {
            autoContinueLoop();
        }
    }

    async function streamChat(payload, userEl) {
        setRunButton(true);
        setActivity("Thinking");
        abort = new AbortController();
        const outcome = { kind: null };
        try {
            const res = await fetch("/cp/api/chat", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrf,
                    Accept: "text/event-stream",
                },
                body: JSON.stringify(payload),
                signal: abort.signal,
            });
            if (!res.ok || !res.body) {
                if (res.status === 408 || res.status === 502 || res.status === 503 || res.status === 504) {
                    outcome.kind = "interrupted";
                    return;
                }
                let err = "Chat request failed.";
                try {
                    const data = JSON.parse(await res.text());
                    if (data && data.message) {
                        err = data.message;
                    }
                } catch (e) {
                    /* keep default */
                }
                append("error", err);
                outcome.kind = "error";
                return;
            }
            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = "";
            while (true) {
                const { done, value } = await reader.read();
                if (done) {
                    break;
                }
                buffer += decoder.decode(value, { stream: true });
                let sep;
                while ((sep = buffer.indexOf("\n\n")) !== -1) {
                    const chunk = buffer.slice(0, sep);
                    buffer = buffer.slice(sep + 2);
                    handleSse(chunk, userEl, outcome);
                }
            }
        } catch (e) {
            if (e.name !== "AbortError") {
                append("error", "Connection error.");
            }
            if (!outcome.kind) {
                outcome.kind = "interrupted";
            }
        } finally {
            const id = runId;
            const keepGoing = (outcome.kind === "interrupted" || outcome.kind === null) && !autoStop;
            if (!keepGoing || !autoLooping) {
                setRunButton(false);
                setActivity("");
            }
            if (outcome.kind !== "done" && outcome.kind !== "interrupted" && id) {
                fetch("/cp/api/cancel", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrf,
                    },
                    body: JSON.stringify({ run_id: id, halt_auto: false }),
                });
            }
            runId = null;
            abort = null;
            if (keepGoing && !autoLooping) {
                autoContinueLoop();
            }
            if (window.CP.reloadPreview) {
                window.CP.reloadPreview();
            } else {
                frame.src = frame.src;
            }
        }
    }

    sendBtn.addEventListener("click", function (event) {
        if (sendBtn.type !== "button") {
            return;
        }
        event.preventDefault();
        stopRun();
    });

    if (restoreConfirmBtn) {
        restoreConfirmBtn.addEventListener("click", async function () {
            const id = pendingRestoreId;
            if (!id) {
                return;
            }
            restoreConfirmBtn.disabled = true;
            try {
                const res = await fetch("/cp/api/restore", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrf,
                    },
                    body: JSON.stringify({ message_id: id }),
                });
                const data = await res.json();
                if (!data.success) {
                    append("error", data.message || "Could not restore.");
                    restoreConfirmBtn.disabled = false;
                    return;
                }
                const composer = data.data && typeof data.data.composer === "string" ? data.data.composer : "";
                sessionStorage.setItem("cp.composerDraft", composer);
                window.location.reload();
            } catch (e) {
                append("error", "Could not restore.");
                restoreConfirmBtn.disabled = false;
            }
        });
    }

    form.addEventListener("submit", async function (event) {
        event.preventDefault();
        const message = input.value.trim();
        const highlight = previewHighlight;
        if (!message && attachments.length === 0 && !highlight) {
            return;
        }
        const pending = attachments.slice();
        input.value = "";
        attachments.forEach(revokePreview);
        attachments = [];
        if (window.CP.clearPreviewHighlight) {
            window.CP.clearPreviewHighlight();
        } else {
            previewHighlight = null;
            renderChips();
        }
        const userLabel = message || (pending.length
            ? pending.map(function (a) { return a.filename; }).join(", ")
            : "Highlighted an area in the preview");
        const userEl = appendTurn(userLabel, "").user;
        autoStop = true;
        if (abort) {
            abort.abort();
        }
        while (autoLooping) {
            await sleep(50);
        }
        autoStop = false;
        removeStopped();
        const payload = {
            message: message,
            attachments: pending.map(function (a) { return a.path; }),
            preview_path: window.CP.previewPath ? window.CP.previewPath() : "",
        };
        if (highlight) {
            payload.highlight = highlight;
        }
        await streamChat(payload, userEl);
    });

    function handleSse(chunk, userEl, outcome) {
        let event = "message";
        let dataLine = "";
        chunk.split("\n").forEach(function (line) {
            if (line.startsWith("event:")) {
                event = line.slice(6).trim();
            } else if (line.startsWith("data:")) {
                dataLine += line.slice(5).trim();
            }
        });
        if (!dataLine) {
            return;
        }
        let data;
        try {
            data = JSON.parse(dataLine);
        } catch (e) {
            return;
        }
        if (data.run_id) {
            runId = data.run_id;
        }
        if (event === "user" && data.id && userEl) {
            decorateUser(userEl, data.id);
        } else if (event === "ping") {
            return;
        } else if (event === "status") {
            const msg = data.message || "";
            setActivity(msg === "Stopped." ? "Stopped" : "Thinking");
        } else if (event === "tool") {
            setActivity(data.name ? "Using " + data.name : "Thinking");
            addActivityLine(activityHostFor(userEl), {
                kind: "tool",
                name: data.name || "",
                text: data.text || (data.name ? "Using " + data.name : "Using tool"),
            });
        } else if (event === "activity") {
            addActivityLine(activityHostFor(userEl), data);
        } else if (event === "message") {
            append("assistant", data.text || "");
        } else if (event === "error") {
            if (data.auto_continue && data.can_continue) {
                outcome.kind = "interrupted";
                return;
            }
            if (data.can_continue && data.message === "The model did not respond in time.") {
                outcome.kind = "interrupted";
                return;
            }
            append("error", data.message || "Error", data.details ? { details: data.details } : undefined);
            outcome.kind = "error";
            autoStop = true;
            showStopped();
        } else if (event === "interrupted") {
            outcome.kind = "interrupted";
        } else if (event === "done") {
            if (data.ok) {
                outcome.kind = "done";
            } else if (outcome.kind === "error") {
                return;
            } else if (data.interrupted || data.auto_continue) {
                outcome.kind = "interrupted";
            } else if (!outcome.kind) {
                outcome.kind = "error";
                autoStop = true;
                showStopped();
            }
        }
    }

    if (window.CP.canContinue) {
        autoContinueLoop();
    }
})();

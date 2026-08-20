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
            if (path === "" || path === "/index.php") {
                path = "/";
            }
            const params = new URLSearchParams(loc.search);
            params.delete("_cp");
            const qs = params.toString();
            return path + (qs ? "?" + qs : "") + (loc.hash || "");
        }
        function syncPath() {
            if (document.activeElement === pathInput) {
                return;
            }
            pathInput.value = displayPath();
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
        log.appendChild(div);
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

    function toolCountLabel(n) {
        return n === 1 ? "1 tool call" : n + " tool calls";
    }

    function currentToolsBox(host) {
        const last = host.lastElementChild;
        if (last && last.classList.contains("msg-tools")) {
            return last;
        }
        const box = document.createElement("details");
        box.className = "msg-tools";
        const summary = document.createElement("summary");
        summary.className = "msg-tools-summary";
        summary.textContent = toolCountLabel(0);
        box.appendChild(summary);
        const list = document.createElement("div");
        list.className = "msg-tools-list";
        box.appendChild(list);
        host.appendChild(box);
        return box;
    }

    function addActivityLine(host, item) {
        if (!host || !item || !item.text || isQuietNote(item.text)) {
            return;
        }
        if (isToolItem(item)) {
            const box = currentToolsBox(host);
            const list = box.querySelector(".msg-tools-list");
            const lastTool = list ? list.querySelector(".msg-tool:last-child") : null;
            if (lastTool && lastTool.textContent === item.text) {
                return;
            }
            const line = document.createElement("div");
            line.className = "msg msg-tool";
            line.textContent = item.text;
            list.appendChild(line);
            const n = list.querySelectorAll(".msg-tool").length;
            const summary = box.querySelector("summary");
            if (summary) {
                summary.textContent = toolCountLabel(n);
            }
            log.scrollTop = log.scrollHeight;
            return;
        }
        const thoughts = host.querySelectorAll(":scope > .msg-status");
        const lastThought = thoughts.length ? thoughts[thoughts.length - 1] : null;
        if (lastThought && lastThought.textContent === item.text) {
            return;
        }
        const line = document.createElement("div");
        line.className = "msg msg-status";
        line.textContent = item.text;
        host.appendChild(line);
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

    function renderChips() {
        chipBox.innerHTML = "";
        if (attachments.length === 0) {
            chipBox.hidden = true;
            return;
        }
        chipBox.hidden = false;
        attachments.forEach(function (item, index) {
            const chip = document.createElement("span");
            chip.className = "badge text-bg-light border chat-chip";
            chip.textContent = item.filename + " ";
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn-close btn-close-sm";
            remove.setAttribute("aria-label", "Remove " + item.filename);
            remove.addEventListener("click", function () {
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
            const body = new FormData();
            body.append("file", file, file.name);
            try {
                const res = await fetch("/cp/api/attachments", {
                    method: "POST",
                    headers: { "X-CSRF-Token": csrf },
                    body: body,
                });
                const data = await res.json();
                if (!data.success) {
                    append("error", data.message || "Could not attach " + file.name);
                    continue;
                }
                attachments.push(data.data);
                renderChips();
            } catch (e) {
                append("error", "Could not attach " + file.name);
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
        Array.prototype.slice.call(host.querySelectorAll(".msg")).forEach(function (el) {
            if (isQuietNote(el.textContent || "")) {
                el.remove();
            }
        });
        const filtered = (items || []).filter(function (item) {
            return item && item.text && !isQuietNote(item.text);
        });
        if (!filtered.length) {
            return;
        }
        const shown = host.querySelectorAll(".msg").length;
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
        if (!message && attachments.length === 0) {
            return;
        }
        const pending = attachments.slice();
        input.value = "";
        attachments = [];
        renderChips();
        const userEl = appendTurn(message || pending.map(function (a) { return a.filename; }).join(", "), "").user;
        autoStop = true;
        if (abort) {
            abort.abort();
        }
        while (autoLooping) {
            await sleep(50);
        }
        autoStop = false;
        removeStopped();
        await streamChat({
            message: message,
            attachments: pending.map(function (a) { return a.path; }),
            preview_path: window.CP.previewPath ? window.CP.previewPath() : "",
        }, userEl);
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

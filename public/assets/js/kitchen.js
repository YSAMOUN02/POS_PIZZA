// Kitchen interface — standalone JS (not shared with script.js, which is tightly
// coupled to pos.blade.php's DOM). Chef/Supervisor-Chef only.

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

// Escape text for safe use in an HTML attribute (e.g. a title tooltip).
function escHtml(str) {
    return String(str ?? "")
        .replace(/&/g, "&amp;")
        .replace(/"/g, "&quot;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}

// Decimal columns come back padded ("1.0000", "1.2000"). Show the number the
// chef actually typed: 1 → "1", 1.2 → "1.2", 1.501 → "1.501".
function trimNum(value, maxDp = 6) {
    const n = Number(value);
    if (!isFinite(n)) return "";
    return String(parseFloat(n.toFixed(maxDp)));
}

// Chef toast — a stacked card in the top-right with an icon, title and message,
// sliding in and auto-dismissing. Styled to match the kitchen's amber chrome.
function _kitchenToastLayer() {
    let layer = document.getElementById("kitchenToastLayer");
    if (!layer) {
        layer = document.createElement("div");
        layer.id = "kitchenToastLayer";
        layer.style.cssText =
            "position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:380px;pointer-events:none;";
        document.body.appendChild(layer);

        const style = document.createElement("style");
        style.textContent = `
            @keyframes kt-in { from { opacity:0; transform:translateX(24px) scale(.98); } to { opacity:1; transform:none; } }
            @keyframes kt-out { to { opacity:0; transform:translateX(24px) scale(.98); } }
            .kt-toast { pointer-events:auto; display:flex; align-items:flex-start; gap:11px; padding:12px 13px; border-radius:14px;
                background:#fff; box-shadow:0 12px 32px -8px rgba(15,23,42,.28), 0 0 0 1px rgba(15,23,42,.05);
                animation:kt-in .28s cubic-bezier(.21,1.02,.73,1); overflow:hidden; }
            .kt-toast.leaving { animation:kt-out .22s ease forwards; }
            .kt-ico { flex:0 0 auto; height:30px; width:30px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:14px; }
            .kt-body { min-width:0; flex:1; }
            .kt-title { font-size:12.5px; font-weight:700; line-height:1.2; margin:1px 0 2px; }
            .kt-msg { font-size:11.5px; line-height:1.35; color:#475569; word-break:break-word; }
            .kt-close { flex:0 0 auto; margin:-2px -2px 0 0; color:#cbd5e1; background:none; border:0; cursor:pointer; font-size:13px; padding:2px; line-height:1; }
            .kt-close:hover { color:#64748b; }
        `;
        document.head.appendChild(style);
    }
    return layer;
}

function showToast({ message, type = "success", title, duration } = {}) {
    const theme = {
        success: { bg: "#ecfdf5", fg: "#059669", icon: "fa-circle-check", label: "Done" },
        error:   { bg: "#fef2f2", fg: "#e11d48", icon: "fa-circle-exclamation", label: "Problem" },
        warning: { bg: "#fffbeb", fg: "#d97706", icon: "fa-triangle-exclamation", label: "Heads up" },
        info:    { bg: "#eff6ff", fg: "#2563eb", icon: "fa-circle-info", label: "Info" },
    }[type] || null;
    const t = theme || { bg: "#eff6ff", fg: "#2563eb", icon: "fa-circle-info", label: "Info" };
    const ms = duration || (type === "error" ? 6000 : type === "warning" ? 5000 : 3200);

    const layer = _kitchenToastLayer();
    const el = document.createElement("div");
    el.className = "kt-toast";
    el.style.position = "relative";
    el.innerHTML = `
        <span class="kt-ico" style="background:${t.bg};color:${t.fg};"><i class="fa-solid ${t.icon}"></i></span>
        <div class="kt-body">
            <p class="kt-title" style="color:${t.fg};">${(title || t.label)}</p>
            <p class="kt-msg"></p>
        </div>
        <button type="button" class="kt-close" aria-label="Dismiss">&times;</button>`;
    el.querySelector(".kt-msg").textContent = message ?? "";
    // colored left accent
    const accent = document.createElement("span");
    accent.style.cssText = `position:absolute;left:0;top:0;bottom:0;width:4px;background:${t.fg};`;
    el.prepend(accent);

    layer.appendChild(el);

    let timer;
    const dismiss = () => {
        clearTimeout(timer);
        el.classList.add("leaving");
        el.addEventListener("animationend", () => el.remove(), { once: true });
    };
    el.querySelector(".kt-close").addEventListener("click", dismiss);
    // pause on hover
    el.addEventListener("mouseenter", () => clearTimeout(timer));
    el.addEventListener("mouseleave", () => { timer = setTimeout(dismiss, 1200); });
    timer = setTimeout(dismiss, ms);
}

async function apiFetch(url, options = {}) {
    const res = await fetch(url, {
        ...options,
        headers: {
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfToken(),
            ...(options.body && !(options.body instanceof FormData)
                ? { "Content-Type": "application/json" }
                : {}),
            ...(options.headers || {}),
        },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message || `Error ${res.status}`);
    return data;
}

// ============================================================
// TABS
// ============================================================
document.addEventListener("DOMContentLoaded", () => {
    document
        .querySelectorAll(".kitchen-tab-btn, .kitchen-nav-btn")
        .forEach((btn) => {
            btn.addEventListener("click", () =>
                switchKitchenTab(btn.dataset.tab),
            );
        });
    switchKitchenTab("recipe"); // Menu & Recipes is the default landing tab now
    // Orders board isn't the active tab anymore, but its sidebar badge/stats
    // still need real numbers as soon as the page loads.
    loadPendingOrders();
    loadKitchenStats();
    startKitchenClock();
});

// ============================================================
// HEADER CLOCK
// ============================================================
function startKitchenClock() {
    const clockEl = document.getElementById("kitchenClock");
    const dateEl = document.getElementById("kitchenDate");
    if (!clockEl || !dateEl) return;
    const tick = () => {
        const now = new Date();
        clockEl.textContent = now.toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
        });
        dateEl.textContent = now.toLocaleDateString([], {
            weekday: "long",
            month: "long",
            day: "numeric",
        });
    };
    tick();
    setInterval(tick, 1000);
}

const KITCHEN_PAGE_TITLES = {
    orders: [
        "Orders",
        "Live tickets for every sold item still waiting on the pass.",
    ],
    products: [
        "Material",
        "Raw & packaging materials — stock, usage and costs.",
    ],
    recipe: [
        "Menu & Recipes",
        "Define variants and the bill of materials behind every dish.",
    ],
    purchase: [
        "Purchase",
        "Buy raw & packaging material — received into stock in the base unit.",
    ],
    kitchenorder: [
        "Kitchen Order",
        "Every prepared dish (output) and the materials it consumed — exportable.",
    ],
};

function switchKitchenTab(tab) {
    document
        .querySelectorAll(".kitchen-tab-btn, .kitchen-nav-btn")
        .forEach((b) => b.classList.toggle("active", b.dataset.tab === tab));
    document
        .querySelectorAll(".kitchen-tab-panel")
        .forEach((p) => p.classList.add("hidden"));
    document.getElementById(`kitchen-tab-${tab}`)?.classList.remove("hidden");

    const [title, subtitle] = KITCHEN_PAGE_TITLES[tab] || [tab, ""];
    const titleEl = document.getElementById("kitchenPageTitle");
    const subtitleEl = document.getElementById("kitchenPageSubtitle");
    if (titleEl) titleEl.textContent = title;
    if (subtitleEl) subtitleEl.textContent = subtitle;

    if (tab === "products") {
        loadKitchenProducts();
        loadKpUsedInOptions();
    }
    if (tab === "recipe") loadCookingProductPicker();
    if (tab === "kitchenorder") loadKitchenOrders(1);
}

// ============================================================
// ORDERS — Pending / Prepared Today / stats / details panel
// ============================================================
let _pendingOrdersCache = {};
let _preparedTodayCache = {};
let _selectedOrder = null; // { ...row, _prepared: bool }

async function loadPendingOrders(page = 1) {
    const container = document.getElementById("pendingOrdersBody");
    container.innerHTML = `<div class="col-span-full py-10 text-center text-gray-400"><i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Loading...</div>`;
    try {
        const result = await apiFetch(`/kitchen/orders?page=${page}`);
        const rows = result.data || [];
        _pendingOrdersCache = {};
        rows.forEach((r) => {
            _pendingOrdersCache[r.id] = r;
        });
        renderPendingOrders(rows);
        renderPendingOrdersPagination(result);
        updateOrdersTabCount(result.total ?? rows.length);
        markKitchenSynced();
    } catch (err) {
        container.innerHTML = `<div class="col-span-full py-10 text-center text-rose-500">Failed to load orders</div>`;
    }
}

function markKitchenSynced() {
    const el = document.getElementById("kitchenLastSync");
    if (el)
        el.textContent = new Date().toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit",
        });
}

function updateOrdersTabCount(total) {
    const badge = document.getElementById("ordersTabCount");
    if (!badge) return;
    if (!total) {
        badge.classList.add("hidden");
        return;
    }
    badge.textContent = total;
    badge.classList.remove("hidden");
}

function timeAgo(dateStr) {
    if (!dateStr) return "-";
    const diffMs = Date.now() - new Date(dateStr).getTime();
    const mins = Math.max(0, Math.round(diffMs / 60000));
    if (mins < 1) return "just now";
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    return `${hrs}h ${mins % 60}m ago`;
}

function renderPendingOrders(rows) {
    const container = document.getElementById("pendingOrdersBody");
    if (!rows.length) {
        container.innerHTML = `
            <div class="col-span-full py-16 text-center text-gray-400">
                <i class="fa-solid fa-circle-check text-3xl mb-3 block text-emerald-400"></i>
                Nothing pending — all caught up.
            </div>`;
        return;
    }
    container.innerHTML = rows
        .map(
            (r) => `
        <div class="kitchen-ticket cursor-pointer ${_selectedOrder?.id === r.id && !_selectedOrder?._prepared ? "ring-2 ring-amber-400" : ""}" onclick="selectOrderForDetails(${r.id}, false)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-bold text-gray-800 leading-tight">${r.name ?? ""}</p>
                    ${r.variant ? `<span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-100">${r.variant}</span>` : ""}
                </div>
                <span class="kitchen-ticket-qty">×${parseFloat(r.quantity)}</span>
            </div>
            <div class="flex items-center justify-between text-xs text-gray-500 border-t border-dashed border-gray-200 pt-2.5">
                <span><i class="fa-solid fa-receipt mr-1 text-gray-400"></i>${r.invoice_no ?? r.document_no ?? "-"}</span>
                <span><i class="fa-regular fa-clock mr-1 text-gray-400"></i>${timeAgo(r.sold_at)}</span>
            </div>
            ${
                r.can_prepare === false
                    ? `<button onclick="event.stopPropagation(); markPrepared(${r.id})" class="w-full mt-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-md bg-rose-100 hover:bg-rose-200 text-rose-700 border border-rose-200 text-xs font-bold transition" title="A raw material is out of stock">
                        <i class="fa-solid fa-triangle-exclamation"></i> Out of Stock
                    </button>`
                    : `<button onclick="event.stopPropagation(); markPrepared(${r.id})" class="w-full mt-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-md bg-amber-400 hover:bg-amber-500 text-amber-950 text-xs font-bold transition">
                        <i class="fa-solid fa-check"></i> Consume
                    </button>`
            }
        </div>
    `,
        )
        .join("");
}

function renderPendingOrdersPagination(result) {
    const el = document.getElementById("pendingOrdersPagination");
    el.innerHTML = "";
    if (!result.last_page || result.last_page <= 1) return;
    for (let i = 1; i <= result.last_page; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        btn.className = `px-2.5 py-1 rounded-md text-xs font-medium ${i === result.current_page ? "bg-gray-900 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`;
        btn.onclick = () => loadPendingOrders(i);
        el.appendChild(btn);
    }
}

async function markPrepared(lineId) {
    // Immediate guard so the chef gets a clear reason without a round-trip.
    // The backend still re-checks on save (source of truth) in case stock
    // changed since the board was loaded.
    const row = _pendingOrdersCache[lineId];
    if (row && row.can_prepare === false) {
        const short = (row.components || [])
            .filter((c) => !c.ok && !c.unresolved)
            .map((c) => c.name);
        showToast({
            message: short.length
                ? `Raw material out of stock: ${short.join(", ")}`
                : "Raw material out of stock",
            type: "error",
            duration: 6000,
        });
        return;
    }
    try {
        const data = await apiFetch(`/kitchen/orders/${lineId}/prepare`, {
            method: "POST",
        });
        showToast({
            message: data.message || "Consumed",
            type: data.warning ? "warning" : "success",
            duration: data.warning ? 7000 : 3000,
        });
        if (_selectedOrder?.id === lineId) closeOrderDetails();
        loadPendingOrders();
        loadKitchenStats();
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

async function markPreparedFromDetails() {
    if (!_selectedOrder || _selectedOrder._prepared) return;
    await markPrepared(_selectedOrder.id);
}

// ---- Prepared Today (read-only recent activity — no fake workflow stages) ----
async function loadPreparedToday(page = 1) {
    const container = document.getElementById("preparedTodayBody");
    if (!container) return;
    container.innerHTML = `<div class="col-span-full py-8 text-center text-gray-400"><i class="fa-solid fa-circle-notch fa-spin mr-2"></i>Loading...</div>`;
    try {
        const result = await apiFetch(
            `/kitchen/orders/prepared-today?page=${page}`,
        );
        const rows = result.data || [];
        _preparedTodayCache = {};
        rows.forEach((r) => {
            _preparedTodayCache[r.id] = r;
        });
        renderPreparedToday(rows);
        renderPreparedTodayPagination(result);
    } catch (err) {
        container.innerHTML = `<div class="col-span-full py-8 text-center text-rose-500">Failed to load</div>`;
    }
}

function renderPreparedToday(rows) {
    const container = document.getElementById("preparedTodayBody");
    if (!container) return; // "Consumed Today" board removed from the UI
    if (!rows.length) {
        container.innerHTML = `<div class="col-span-full py-10 text-center text-gray-400 text-sm">Nothing prepared yet today.</div>`;
        return;
    }
    container.innerHTML = rows
        .map(
            (r) => `
        <div class="kitchen-ticket cursor-pointer opacity-90 ${_selectedOrder?.id === r.id && _selectedOrder?._prepared ? "ring-2 ring-emerald-400" : ""}" style="border-left-color:#10b981" onclick="selectOrderForDetails(${r.id}, true)">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-bold text-gray-800 leading-tight">${r.name ?? ""}</p>
                    ${r.variant ? `<span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">${r.variant}</span>` : ""}
                </div>
                <span class="kitchen-ticket-qty">×${parseFloat(r.quantity)}</span>
            </div>
            <div class="flex items-center justify-between text-xs text-gray-500 border-t border-dashed border-gray-200 pt-2.5">
                <span><i class="fa-solid fa-receipt mr-1 text-gray-400"></i>${r.invoice_no ?? r.document_no ?? "-"}</span>
                <span class="text-emerald-600"><i class="fa-solid fa-check mr-1"></i>${timeAgo(r.prepared_at)}</span>
            </div>
        </div>
    `,
        )
        .join("");
}

function renderPreparedTodayPagination(result) {
    const el = document.getElementById("preparedTodayPagination");
    if (!el) return;
    el.innerHTML = "";
    if (!result.last_page || result.last_page <= 1) return;
    for (let i = 1; i <= result.last_page; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        btn.className = `px-2.5 py-1 rounded-md text-xs font-medium ${i === result.current_page ? "bg-gray-900 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`;
        btn.onclick = () => loadPreparedToday(i);
        el.appendChild(btn);
    }
}

// ---- Stats strip ----
async function loadKitchenStats() {
    try {
        const stats = await apiFetch("/kitchen/stats");
        document.getElementById("statPending").textContent =
            stats.pending_count ?? 0;
    } catch (err) {
        /* stat strip is non-critical — fail quietly */
    }
}

// Today's menu-sold summary — loaded on demand from the Orders stat button.
async function openMenuSoldToday() {
    const modal = document.getElementById("menuSoldTodayModal");
    const body = document.getElementById("mstBody");
    modal.classList.remove("hidden");
    modal.classList.add("flex");
    body.innerHTML = `<tr><td colspan="3" class="px-3 py-6 text-center text-gray-400">Loading...</td></tr>`;
    try {
        const res = await apiFetch("/kitchen/menu-sold-today");
        document.getElementById("mstDate").textContent =
            `${res.date} · ${trimNum(res.total || 0, 2)} sold`;
        const items = res.items || [];
        body.innerHTML = items.length
            ? items
                  .map(
                      (r) => `
        <tr class="border-t border-gray-100">
            <td class="px-3 py-2 font-medium text-gray-800">${escHtml(r.name ?? "")}${r.variant ? ` <span class="text-amber-700 font-semibold">· ${escHtml(r.variant)}</span>` : ""}</td>
            <td class="px-3 py-2 text-right font-semibold tabular-nums">${trimNum(r.qty_sold || 0, 2)} <span class="text-gray-400 font-normal text-xs">${escHtml(r.unit ?? "")}</span></td>
            <td class="px-3 py-2 text-center text-gray-500">${r.prepared_count}/${r.order_count}</td>
        </tr>`,
                  )
                  .join("")
            : `<tr><td colspan="3" class="px-3 py-8 text-center text-gray-400">Nothing sold yet today</td></tr>`;
    } catch (err) {
        body.innerHTML = `<tr><td colspan="3" class="px-3 py-6 text-center text-rose-500">Failed to load</td></tr>`;
    }
}

function closeMenuSoldToday() {
    const modal = document.getElementById("menuSoldTodayModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
}

// ---- Order details panel ----
function selectOrderForDetails(id, isPrepared) {
    const row = isPrepared ? _preparedTodayCache[id] : _pendingOrdersCache[id];
    if (!row) return;
    _selectedOrder = { ...row, _prepared: isPrepared };

    const modal = document.getElementById("orderDetailsModal");
    modal.classList.remove("hidden");
    modal.classList.add("flex");

    document.getElementById("odName").textContent =
        row.name + (row.variant ? ` · ${row.variant}` : "");
    document.getElementById("odQty").textContent = parseFloat(row.quantity);
    document.getElementById("odInvoice").textContent =
        row.invoice_no ?? row.document_no ?? "-";
    document.getElementById("odSoldAt").textContent = row.sold_at
        ? new Date(row.sold_at).toLocaleString()
        : "-";

    const preparedRow = document.getElementById("odPreparedRow");
    const markBtn = document.getElementById("odMarkPreparedBtn");
    const ingredients = document.getElementById("odIngredients");
    if (isPrepared) {
        document.getElementById("odElapsed").textContent = "Prepared";
        preparedRow.classList.remove("hidden");
        document.getElementById("odPreparedBy").textContent =
            row.prepared_by ?? "-";
        markBtn.classList.add("hidden");
        if (ingredients) ingredients.classList.add("hidden"); // already consumed
    } else {
        document.getElementById("odElapsed").textContent = timeAgo(row.sold_at);
        preparedRow.classList.add("hidden");
        markBtn.classList.remove("hidden");
        if (ingredients) ingredients.classList.remove("hidden");
        renderOrderIngredients(row);

        // Recolour the action button to match stock state.
        const short = row.can_prepare === false;
        markBtn.classList.toggle("bg-amber-400", !short);
        markBtn.classList.toggle("hover:bg-amber-500", !short);
        markBtn.classList.toggle("text-amber-950", !short);
        markBtn.classList.toggle("bg-rose-100", short);
        markBtn.classList.toggle("hover:bg-rose-200", short);
        markBtn.classList.toggle("text-rose-700", short);
        markBtn.classList.toggle("border", short);
        markBtn.classList.toggle("border-rose-200", short);
        markBtn.innerHTML = short
            ? '<i class="fa-solid fa-triangle-exclamation"></i> Out of Stock'
            : '<i class="fa-solid fa-check"></i> Consume';
    }

    // re-render both boards so the ring-highlight follows the selection
    renderPendingOrders(Object.values(_pendingOrdersCache));
    renderPreparedToday(Object.values(_preparedTodayCache));
}

// Green = enough on hand, red = short, amber = unit not resolvable (prepare
// will skip it). Lets the chef see WHY an order can't be prepared before clicking.
function renderOrderIngredients(row) {
    const body = document.getElementById("odIngredientsBody");
    if (!body) return;
    const comps = row.components || [];
    if (!comps.length) {
        body.innerHTML =
            '<p class="text-xs text-gray-400">No components defined for this dish.</p>';
        return;
    }
    body.innerHTML = comps
        .map((c) => {
            const tone = c.unresolved
                ? "bg-amber-50 border-amber-200 text-amber-700"
                : c.ok
                  ? "bg-emerald-50 border-emerald-200 text-emerald-700"
                  : "bg-rose-50 border-rose-200 text-rose-700";
            const dot = c.unresolved
                ? "bg-amber-400"
                : c.ok
                  ? "bg-emerald-500"
                  : "bg-rose-500";
            const need = c.unresolved
                ? "unit not set"
                : `need ${trimNum(c.needed, 4)} · have ${trimNum(c.available, 4)} ${escHtml(c.unit ?? "")}`;
            return `
            <div class="flex items-center justify-between gap-2 rounded-md border px-2.5 py-1.5 text-xs ${tone}">
                <span class="inline-flex items-center gap-1.5 min-w-0">
                    <span class="h-1.5 w-1.5 rounded-full shrink-0 ${dot}"></span>
                    <span class="truncate font-medium">${escHtml(c.name ?? "")}</span>
                </span>
                <span class="shrink-0 tabular-nums text-2xs">${need}</span>
            </div>`;
        })
        .join("");
}

function closeOrderDetails() {
    _selectedOrder = null;
    const modal = document.getElementById("orderDetailsModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
    renderPendingOrders(Object.values(_pendingOrdersCache));
    renderPreparedToday(Object.values(_preparedTodayCache));
}

// ============================================================
// PRODUCTS (cooking / raw material / packaging material)
// ============================================================
let _kpFilterDebounce = null;

function kpFilterParams() {
    const search =
        document.getElementById("kpFilterSearch")?.value.trim() ?? "";
    const type = document.getElementById("kpFilterType")?.value ?? "";
    const stockStatus = document.getElementById("kpFilterStock")?.value ?? "";
    const usedIn = document.getElementById("kpFilterUsedInId")?.value ?? "";
    const params = new URLSearchParams();
    params.set("scope", "inventory"); // raw material + packaging only — cooking products live in the Product tab
    if (search) params.set("search", search);
    if (type) params.set("type", type);
    if (stockStatus) params.set("stock_status", stockStatus);
    if (usedIn) {
        params.set("used_in_product", usedIn);
        params.set("used_in_by", "name");
    }
    return params;
}

// Name → dish id, so the typed value in the datalist resolves to the id the
// filter needs. Deduped by name (used_in_by=name expands to all sibling variants).
let _kpUsedInMap = {};

async function loadKpUsedInOptions() {
    const dl = document.getElementById("kpUsedInList");
    if (!dl || dl.dataset.loaded) return;
    try {
        const dishes = await apiFetch("/kitchen/cooking-products");
        _kpUsedInMap = {};
        const seen = new Set();
        const opts = [];
        dishes.forEach((d) => {
            if (seen.has(d.name)) return; // one entry per dish name
            seen.add(d.name);
            _kpUsedInMap[d.name.toLowerCase()] = d.id;
            opts.push(`<option value="${escHtml(d.name)}"></option>`);
        });
        dl.innerHTML = opts.join("");
        dl.dataset.loaded = "1";
    } catch (err) {
        /* filter stays as "any dish" */
    }
}

// Resolve the typed dish name to its id, then reload the material list.
function onKpUsedInInput() {
    const input = document.getElementById("kpFilterUsedIn");
    const hidden = document.getElementById("kpFilterUsedInId");
    if (!input || !hidden) return;
    const typed = input.value.trim().toLowerCase();
    hidden.value = typed ? (_kpUsedInMap[typed] ?? "") : "";
    // Only reload once it's blank or a real match (avoids a fetch on every keypress).
    if (typed === "" || hidden.value) loadKitchenProducts(1);
}

document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("kpFilterSearch")?.addEventListener("input", () => {
        clearTimeout(_kpFilterDebounce);
        _kpFilterDebounce = setTimeout(() => loadKitchenProducts(1), 350);
    });
    document
        .getElementById("kpFilterType")
        ?.addEventListener("change", () => loadKitchenProducts(1));
    document
        .getElementById("kpFilterStock")
        ?.addEventListener("change", () => loadKitchenProducts(1));
    document
        .getElementById("kpFilterUsedIn")
        ?.addEventListener("input", onKpUsedInInput);
});

async function loadKitchenProducts(page = 1) {
    const tbody = document.getElementById("kitchenProductsBody");
    tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">Loading...</td></tr>`;
    try {
        const params = kpFilterParams();
        params.set("page", page);
        params.set("limit", 30); // 30 materials per page
        const result = await apiFetch(`/kitchen/products?${params.toString()}`);
        renderKitchenProducts(result.data || []);
        renderKitchenProductsPagination(result);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-rose-500">Failed to load products</td></tr>`;
    }
}

const TYPE_LABELS = {
    cooking_product: "Cooking Product",
    raw_material: "Raw Material",
    packaging_material: "Packaging Material",
};
const TYPE_BADGE = {
    cooking_product: "bg-orange-100 text-orange-700",
    raw_material: "bg-teal-100 text-teal-700",
    packaging_material: "bg-blue-100 text-blue-700",
};

function stockCell(p) {
    if (!p.track_stock) return '<span class="text-gray-300">—</span>';
    const stock = parseFloat(p.stock || 0);
    const min = parseFloat(p.min_stock || 0);
    let badge = "bg-emerald-100 text-emerald-700";
    let label = "In Stock";
    if (stock <= 0) {
        badge = "bg-rose-100 text-rose-600";
        label = "Out";
    } else if (min > 0 && stock <= min) {
        badge = "bg-amber-100 text-amber-700";
        label = "Low";
    }
    return `
        <div class="flex items-center gap-2">
            <span class="font-semibold text-gray-700">${stock}${p.unit ? ` <span class="text-gray-400 font-normal text-xs">${p.unit}</span>` : ""}</span>
            <span class="text-2xs px-1.5 py-0.5 rounded-full font-semibold ${badge}">${label}</span>
        </div>`;
}

// 3-state status label: 1 Active, 2 Disabled, 3 Under development.
function statusLabel(status) {
    const st = Number(status) || 0;
    if (st === 1) return '<span class="text-emerald-600">Active</span>';
    if (st === 3) return '<span class="text-amber-600">Under Dev</span>';
    return '<span class="text-rose-500">Disabled</span>';
}

// Base-unit qty still needed to prepare pending orders. Red when it exceeds
// current stock (must buy more), amber otherwise. "—" when nothing's outstanding.
function neededCell(p) {
    const need = Number(p.needed || 0);
    if (need <= 0) return '<span class="text-gray-300">—</span>';
    // Red when current stock can't cover the demand (must buy); green when it can.
    const short = need > Number(p.stock || 0);
    return `<span class="font-semibold ${short ? "text-rose-600" : "text-emerald-600"}">${trimNum(need, 4)}</span><span class="text-gray-400 text-xs"> ${escHtml(p.unit ?? "")}</span>`;
}

function whereUsedCell(p) {
    if (p.type === "cooking_product")
        return '<span class="text-gray-300">—</span>';
    const usedIn = p.used_in || [];
    if (!usedIn.length)
        return '<span class="text-gray-400 text-xs italic">Not used</span>';
    // A single clean badge instead of a wrapping row of chips — full list on hover,
    // click opens a small popover for anyone who needs to see it without hovering.
    const escaped = usedIn.join(", ").replace(/"/g, "&quot;");
    return `
        <button type="button" title="${escaped}" onclick='showWhereUsedPopover(this, ${JSON.stringify(usedIn)})'
            class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 hover:bg-gray-200 transition">
            <i class="fa-solid fa-utensils text-2xs"></i> ${usedIn.length} dish${usedIn.length === 1 ? "" : "es"}
        </button>
    `;
}

function showWhereUsedPopover(anchor, names) {
    document.getElementById("whereUsedPopover")?.remove();
    const rect = anchor.getBoundingClientRect();
    const popover = document.createElement("div");
    popover.id = "whereUsedPopover";
    popover.className =
        "fixed z-[9999] bg-white border border-gray-200 rounded-md shadow-lg p-3 text-xs text-gray-700 max-w-64";
    popover.style.top = `${rect.bottom + 6}px`;
    popover.style.left = `${Math.min(rect.left, window.innerWidth - 270)}px`;
    popover.innerHTML = `
        <p class="text-2xs uppercase tracking-wide text-gray-400 font-semibold mb-1.5">Used in</p>
        <ul class="space-y-1">${names.map((n) => `<li>• ${n}</li>`).join("")}</ul>
    `;
    document.body.appendChild(popover);
    setTimeout(() => {
        document.addEventListener("click", function closePopover(e) {
            if (!popover.contains(e.target) && e.target !== anchor) {
                popover.remove();
                document.removeEventListener("click", closePopover);
            }
        });
    }, 0);
}

function renderKitchenProducts(rows) {
    const tbody = document.getElementById("kitchenProductsBody");
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No materials match these filters</td></tr>`;
        return;
    }
    tbody.innerHTML = rows
        .map(
            (p) => `
        <tr class="border-t border-gray-100">
            <td class="px-4 py-2.5 font-medium text-gray-800">${p.name ?? ""}</td>
            <td class="px-4 py-3"><span class="text-xs px-2 py-1 rounded-full ${TYPE_BADGE[p.type] ?? "bg-gray-100 text-gray-600"}">${TYPE_LABELS[p.type] ?? p.type}</span></td>
            <td class="px-4 py-3">${stockCell(p)}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">${neededCell(p)}</td>
            <td class="px-4 py-2.5 kitchen-col-optional">${whereUsedCell(p)}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">${trimNum(p.cost || 0, 6)}<span class="text-gray-400 text-xs"> /${p.unit ?? ""}</span></td>
            <td class="px-4 py-2.5 text-center kitchen-col-optional">${statusLabel(p.status)}</td>
            <td class="px-4 py-2.5 text-center">
                <div class="inline-flex items-center gap-1.5">
                    ${
                        window.CAN_KITCHEN_PURCHASE
                            ? `<button onclick="openPurchaseModal(${p.id}, ${Number(p.needed || 0)})" class="px-2 py-1 rounded-md border border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 text-xs font-semibold"><i class="fa-solid fa-cart-plus"></i> Buy</button>`
                            : ""
                    }
                    <button onclick="openMaterialUsage(${p.id})" class="px-2 py-1 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 text-xs font-medium"><i class="fa-solid fa-list-ul"></i> Used</button>
                    <button onclick='openKitchenProductModal(${JSON.stringify(p)})' class="px-2 py-1 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 text-xs font-medium">Edit</button>
                </div>
            </td>
        </tr>
    `,
        )
        .join("");
}

function renderKitchenProductsPagination(result) {
    const el = document.getElementById("kitchenProductsPagination");
    el.innerHTML = "";
    if (!result.last_page || result.last_page <= 1) return;
    for (let i = 1; i <= result.last_page; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        btn.className = `px-2.5 py-1 rounded-md text-xs font-medium ${i === result.current_page ? "bg-gray-900 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`;
        btn.onclick = () => loadKitchenProducts(i);
        el.appendChild(btn);
    }
}

// "3 components · 2 add-ons" chips for a dish's typed BOM — an empty recipe
// shows a warning chip so unfinished dishes stand out on the menu grid.
// ---- Material usage: where this material is consumed ----
async function openMaterialUsage(id) {
    const modal = document.getElementById("materialUsageModal");
    const body = document.getElementById("materialUsageBody");
    document.getElementById("materialUsageLabel").textContent = "Loading…";
    body.innerHTML =
        '<tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Loading…</td></tr>';
    modal.classList.remove("hidden");
    modal.classList.add("flex");

    try {
        const data = await apiFetch(`/products/${id}/material-usage`);
        document.getElementById("materialUsageLabel").textContent =
            `${data.material.name} · cost ${trimNum(data.material.unit_cost, 6)} / ${data.material.base_unit} · used in ${data.used_count} recipe line${data.used_count === 1 ? "" : "s"}`;

        if (!data.usage.length) {
            body.innerHTML =
                '<tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Not used in any recipe yet.</td></tr>';
            return;
        }
        let totalCost = 0;
        body.innerHTML =
            data.usage
                .map((u) => {
                    totalCost += Number(u.cost) || 0;
                    const use =
                        u.line_type === "add_on"
                            ? `<span class="text-2xs px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 border border-teal-200">add-on: ${escHtml(u.addon_name ?? "")}</span>`
                            : '<span class="text-2xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">component</span>';
                    return `
                <tr class="border-t border-gray-100">
                    <td class="px-4 py-2.5 text-gray-800">${escHtml(u.dish ?? "")}${u.variant ? ` <span class="text-amber-700 font-semibold">· ${escHtml(u.variant)}</span>` : ""}</td>
                    <td class="px-4 py-2.5">${use}</td>
                    <td class="px-4 py-2.5 text-right tabular-nums">${trimNum(u.base_qty, 4)} <span class="text-gray-400 text-xs">${escHtml(u.base_unit ?? "")}</span></td>
                    <td class="px-4 py-2.5 text-right tabular-nums">${trimNum(u.cost, 4)}</td>
                </tr>`;
                })
                .join("") +
            `
            <tr class="border-t border-gray-200 bg-gray-50 font-semibold">
                <td class="px-4 py-2.5 text-gray-500 text-xs uppercase tracking-wider" colspan="3">Total cost per one of each dish</td>
                <td class="px-4 py-2.5 text-right tabular-nums">${trimNum(totalCost, 4)}</td>
            </tr>`;
    } catch (err) {
        body.innerHTML =
            '<tr><td colspan="4" class="px-4 py-8 text-center text-rose-500">Failed to load usage</td></tr>';
    }
}

function closeMaterialUsage() {
    const modal = document.getElementById("materialUsageModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
}

// Recipe status for a dish card: green "Recipe set" when the chef has defined
// components, red "No recipe" otherwise, with the component/add-on tally.
function bomSummary(p) {
    const comps = Number(p.components_count || 0);
    const addons = Number(p.addons_count || 0);
    if (!comps) {
        return '<span class="inline-flex items-center gap-1 text-2xs px-2 py-0.5 rounded-full bg-rose-50 text-rose-600 border border-rose-200"><i class="fa-solid fa-circle-exclamation"></i> No recipe</span>';
    }
    // Green "Recipe" always when set; a separate green "Add-on" chip only if it has any.
    let out =
        '<span class="inline-flex items-center gap-1 text-2xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"><i class="fa-solid fa-circle-check"></i> Recipe</span>';
    if (addons) {
        out +=
            ' <span class="inline-flex items-center gap-1 text-2xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200"><i class="fa-solid fa-plus"></i> Add-on</span>';
    }
    return `<div class="flex flex-wrap gap-1">${out}</div>`;
}

// Unit of Measure (Base Unit + Alternate Units/conversions) is raw_material /
// packaging_material only — cooking products (finished dishes) are always
// sold as one whole unit ("Whole", "Plate", "Glass"...), no conversions needed.
const UOM_ELIGIBLE_TYPES = ["raw_material", "packaging_material"];

function updateKitchenUnitFieldsForType() {
    const type = document.getElementById("kp-type").value;
    const isMaterial = UOM_ELIGIBLE_TYPES.includes(type);
    const productId = document.getElementById("kp-id").value;

    // Unit: materials use the structured base-unit + conversions; FG uses free text.
    document
        .getElementById("kpUnitPlainField")
        .classList.toggle("hidden", isMaterial);
    document
        .getElementById("kpUnitStructuredField")
        .classList.toggle("hidden", !isMaterial);
    document
        .getElementById("kpUnitConversions")
        .classList.toggle("hidden", !isMaterial || !productId);

    // Fields that only make sense for a finished good — a material is never
    // sold at the register, so variant / sell price / discount don't apply.
    document
        .getElementById("kpVariantField")
        .classList.toggle("hidden", isMaterial);
    document
        .getElementById("kpSellPriceField")
        .classList.toggle("hidden", isMaterial);
    document
        .getElementById("kpMinStockField")
        .classList.toggle("hidden", !isMaterial);
    // A photo is only shown to the cashier on the menu — materials never appear
    // there, so the image field is cooking-product only.
    document
        .getElementById("kpImageField")
        ?.classList.toggle("hidden", isMaterial);

    // Menu items (cooking products): the Type is fixed and the Active / discount /
    // return controls are hidden (always on), so the chef only fills in real info.
    document.getElementById("kpTypeField")?.classList.toggle("hidden", !isMaterial);
    document.getElementById("kpStatusField")?.classList.toggle("hidden", !isMaterial);

    // Materials only ever switch between Raw and Packaging.
    const typeSel = document.getElementById("kp-type");
    const wanted = isMaterial
        ? [
              ["raw_material", "Raw Material"],
              ["packaging_material", "Packaging Material"],
          ]
        : [["cooking_product", "Cooking Product (Pizza / Menu Item)"]];
    if (typeSel.options.length !== wanted.length) {
        const keep = typeSel.value;
        typeSel.innerHTML = wanted
            .map(([v, l]) => `<option value="${v}">${l}</option>`)
            .join("");
        typeSel.value = wanted.some(([v]) => v === keep) ? keep : wanted[0][0];
    }
    document.getElementById("kp-type-label").textContent = isMaterial
        ? "Material Kind *"
        : "Type *";
    document.getElementById("kp-cost-label").textContent = isMaterial
        ? "Cost / Base Unit"
        : "Cost";

    loadKitchenCategories(typeSel.value);
}

// Categories are scoped to the product kind: a raw-material category never
// appears when categorising a menu item, and vice versa.
async function loadKitchenCategories(type, selectedId = null) {
    const sel = document.getElementById("kp-category");
    if (!sel) return;
    const keep = selectedId ?? sel.value;
    try {
        const cats = await apiFetch(
            `/categories?for_type=${encodeURIComponent(type)}&active=1`,
        );
        sel.innerHTML =
            '<option value="">— None —</option>' +
            cats
                .map(
                    (c) =>
                        `<option value="${c.id}" ${String(keep) === String(c.id) ? "selected" : ""}>${c.name}</option>`,
                )
                .join("");
    } catch (err) {
        sel.innerHTML = '<option value="">— None —</option>';
    }
}

// ---- Category manager (add / rename / remove; scoped by kind) ----
const KIND_FOR_TYPE = {
    cooking_product: "fg",
    raw_material: "rm",
    packaging_material: "pm",
};

function openCategoryManager() {
    // Default to the kind of the product type currently open in the form.
    const type = document.getElementById("kp-type")?.value || "raw_material";
    const kindSel = document.getElementById("cmKind");
    if (kindSel) kindSel.value = KIND_FOR_TYPE[type] || "rm";
    const modal = document.getElementById("categoryManagerModal");
    modal.classList.remove("hidden");
    modal.classList.add("flex");
    loadCategoryManager();
}

function closeCategoryManager() {
    const modal = document.getElementById("categoryManagerModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
    // Refresh the product form's category dropdown to reflect any changes.
    const type = document.getElementById("kp-type")?.value;
    if (type) loadKitchenCategories(type, document.getElementById("kp-category")?.value);
}

async function loadCategoryManager() {
    const kind = document.getElementById("cmKind")?.value || "rm";
    const tb = document.getElementById("cmList");
    tb.innerHTML = `<tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">Loading...</td></tr>`;
    try {
        const cats = await apiFetch(`/categories/manage?kind=${encodeURIComponent(kind)}`);
        tb.innerHTML = cats.length
            ? cats
                  .map(
                      (c) => `
        <tr class="border-t border-gray-100" data-id="${c.id}">
            <td class="px-4 py-2"><input type="text" value="${escHtml(c.name ?? "")}" class="cm-name kitchen-input w-full"></td>
            <td class="px-4 py-2 text-right text-gray-500 tabular-nums">${c.pos_items_count ?? 0}</td>
            <td class="px-4 py-2 text-center whitespace-nowrap">
                <button onclick="saveCategoryRow(${c.id}, this)" class="px-2 py-1 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 text-xs" title="Save name"><i class="fa-solid fa-check"></i></button>
                <button onclick="deleteCategoryRow(${c.id})" class="px-2 py-1 rounded-md border border-rose-200 bg-rose-50 text-rose-600 hover:bg-rose-100 text-xs" title="Delete"><i class="fa-solid fa-trash"></i></button>
            </td>
        </tr>`,
                  )
                  .join("")
            : `<tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No categories yet for this kind</td></tr>`;
    } catch (err) {
        tb.innerHTML = `<tr><td colspan="3" class="px-4 py-6 text-center text-rose-500">Failed to load</td></tr>`;
    }
}

async function saveCategoryRow(id, btn) {
    const row = btn.closest("tr");
    const name = row.querySelector(".cm-name")?.value.trim();
    if (!name) return;
    try {
        await apiFetch(`/categories/${id}`, {
            method: "PUT",
            body: JSON.stringify({ name, kind: document.getElementById("cmKind").value }),
        });
        showToast({ message: "Category updated", type: "success" });
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

async function deleteCategoryRow(id) {
    // The server refuses to delete a category that still has products assigned,
    // so products are never affected.
    if (!confirm("Delete this category? (Only allowed if no products use it.)")) return;
    try {
        await apiFetch(`/categories/${id}`, { method: "DELETE" });
        showToast({ message: "Category deleted", type: "success" });
        loadCategoryManager();
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document.getElementById("cmAddForm")?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const nameEl = document.getElementById("cmNewName");
        const name = nameEl.value.trim();
        if (!name) return;
        try {
            await apiFetch("/categories", {
                method: "POST",
                body: JSON.stringify({ name, kind: document.getElementById("cmKind").value, status: 1 }),
            });
            nameEl.value = "";
            showToast({ message: "Category added", type: "success" });
            loadCategoryManager();
        } catch (err) {
            showToast({ message: err.message, type: "error" });
        }
    });
});

function openKitchenProductModal(
    product = null,
    defaultType = "cooking_product",
) {
    const form = document.getElementById("kitchenProductForm");
    form.reset();
    const type = product?.type ?? defaultType;
    const isMaterial = UOM_ELIGIBLE_TYPES.includes(type);

    document.getElementById("kp-id").value = product?.id ?? "";
    document.getElementById("kp-code").value = product?.code ?? "";
    document.getElementById("kp-name").value = product?.name ?? "";
    document.getElementById("kp-variant").value = product?.variant ?? "";
    const descEl = document.getElementById("kp-description");
    if (descEl) descEl.value = product?.description ?? "";
    // Rebuild the type list for this scope before selecting into it.
    document.getElementById("kp-type").innerHTML = isMaterial
        ? '<option value="raw_material">Raw Material</option><option value="packaging_material">Packaging Material</option>'
        : '<option value="cooking_product">Cooking Product (Pizza / Menu Item)</option>';
    document.getElementById("kp-type").value = type;
    document.getElementById("kp-min-stock").value = product?.min_stock ?? 0;
    document.getElementById("kp-unit").value = product?.unit ?? "";
    setKitchenUnitSelect(product?.base_unit_id ?? null, product?.unit ?? "");
    // Base unit is fixed once the material exists — lock the picker when editing.
    lockBaseUnit(!!product?.id);
    document.getElementById("kp-sell-price").value = product?.sell_price ?? 0;
    document.getElementById("kp-cost").value = product?.cost ?? 0;
    document.getElementById("kp-status").checked = product
        ? !!Number(product.status)
        : true;
    const noun = isMaterial
        ? type === "packaging_material"
            ? "Packaging"
            : "Raw Material"
        : "Menu Item";
    document.getElementById("kitchenProductModalTitle").textContent =
        `${product ? "Edit" : "Add"} ${noun}`;
    // Image preview: current photo when editing a menu item, else a placeholder icon.
    // form.reset() above already cleared the <input type=file>, so a fresh pick is required.
    const imgPrev = document.getElementById("kpImagePreview");
    if (imgPrev) {
        imgPrev.innerHTML =
            !isMaterial && product?.image
                ? `<img src="/thumb?f=${encodeURIComponent(product.image)}&s=300" class="h-full w-full object-cover" onerror="this.parentNode.innerHTML='&lt;i class=\\'fa-solid fa-image\\'&gt;&lt;/i&gt;'">`
                : '<i class="fa-solid fa-image"></i>';
    }
    document.getElementById("kitchenProductModal").classList.remove("hidden");
    document.getElementById("kitchenProductModal").classList.add("flex");

    updateKitchenUnitFieldsForType();
    loadKitchenCategories(
        product?.type ?? defaultType,
        product?.category_id ?? null,
    );

    // Alternate units only make sense once the product actually exists AND is RM/PM.
    if (product?.id && UOM_ELIGIBLE_TYPES.includes(product?.type)) {
        loadKitchenUnitConversions(product.id);
    } else {
        document.getElementById("kpConversionsList").innerHTML = "";
    }
}

function closeKitchenProductModal() {
    document.getElementById("kitchenProductModal").classList.add("hidden");
    document.getElementById("kitchenProductModal").classList.remove("flex");
}

document.addEventListener("DOMContentLoaded", () => {
    document
        .getElementById("kitchenProductForm")
        ?.addEventListener("submit", async (e) => {
            e.preventDefault();
            const id = document.getElementById("kp-id").value;
            const type = document.getElementById("kp-type").value;

            let unit = "";
            let base_unit_id = "";
            if (UOM_ELIGIBLE_TYPES.includes(type)) {
                const selectedUnit =
                    document.getElementById("kp-unit-select").value;
                if (selectedUnit === "__custom") {
                    unit = document.getElementById("kp-unit-custom").value;
                } else {
                    unit =
                        document.getElementById("kp-unit-select")
                            .selectedOptions[0]?.dataset.code || "";
                    base_unit_id = selectedUnit;
                }
            } else {
                unit = document.getElementById("kp-unit").value;
            }

            const catSel = document.getElementById("kp-category");
            const payload = {
                code: document.getElementById("kp-code").value,
                name: document.getElementById("kp-name").value,
                variant: document.getElementById("kp-variant").value,
                description: document.getElementById("kp-description")?.value || "",
                type,
                unit,
                base_unit_id,
                category_id: catSel?.value || "",
                category_name: catSel?.value
                    ? catSel.selectedOptions[0].textContent.trim()
                    : "",
                sell_price: document.getElementById("kp-sell-price").value || 0,
                cost: document.getElementById("kp-cost").value || 0,
                min_stock: document.getElementById("kp-min-stock").value || 0,
                track_stock: type !== "cooking_product" ? 1 : 0,
            };
            // Menu items (cooking products) are always Enabled and always allow
            // discount + return — those controls are hidden. Materials use the
            // Active checkbox and don't sell, so discount/return don't apply.
            if (type === "cooking_product") {
                payload.status = 1;
                payload.allow_discount = 1;
                payload.allow_return = 1;
            } else {
                payload.status = document.getElementById("kp-status").checked ? 1 : 0;
                payload.allow_discount = 0;
                payload.allow_return = 0;
            }

            // Menu-item photo (cooking products only; the field is hidden for materials).
            const imgFile =
                type === "cooking_product"
                    ? document.getElementById("kp-image")?.files?.[0]
                    : null;

            try {
                if (id) {
                    const form = new FormData();
                    form.append("_method", "PUT");
                    Object.entries(payload).forEach(([k, v]) =>
                        form.append(k, v),
                    );
                    if (imgFile) form.append("image", imgFile);
                    await fetch(`/product/${id}`, {
                        method: "POST",
                        headers: { "X-CSRF-TOKEN": csrfToken() },
                        body: form,
                    }).then(async (r) => {
                        const d = await r.json();
                        if (!r.ok)
                            throw new Error(d.message || "Update failed");
                    });
                } else {
                    const form = new FormData();
                    Object.entries(payload).forEach(([k, v]) =>
                        form.append(k, v),
                    );
                    if (imgFile) form.append("image", imgFile);
                    await fetch("/products/store", {
                        method: "POST",
                        headers: {
                            "X-CSRF-TOKEN": csrfToken(),
                            Accept: "application/json",
                        },
                        body: form,
                    }).then(async (r) => {
                        const d = await r.json();
                        if (!r.ok) throw new Error(d.message || "Save failed");
                    });
                }
                showToast({ message: "Product saved", type: "success" });
                closeKitchenProductModal();
                // Refresh whichever list the item belongs to.
                if (type === "cooking_product") {
                    if (typeof loadCookingProductPicker === "function") loadCookingProductPicker();
                } else {
                    loadKitchenProducts();
                }
            } catch (err) {
                showToast({ message: err.message, type: "error" });
            }
        });
});

// ============================================================
// UNITS OF MEASURE (Base Unit + Alternate Units / conversions)
// ============================================================
let _uomCache = [];

async function loadUnitOfMeasureOptions() {
    try {
        _uomCache = await apiFetch("/units-of-measure");
    } catch (err) {
        _uomCache = [];
    }
    const optionsHtml = _uomCache
        .map(
            (u) =>
                `<option value="${u.id}" data-code="${u.code}">${u.name} (${u.code})</option>`,
        )
        .join("");

    const baseSelect = document.getElementById("kp-unit-select");
    if (baseSelect)
        baseSelect.innerHTML =
            optionsHtml + '<option value="__custom">Custom unit...</option>';

    const convSelect = document.getElementById("kpConversionUnit");
    if (convSelect) convSelect.innerHTML = optionsHtml;
}

// Base Unit combo (raw_material / packaging_material only): pick a real unit
// and the custom text field stays out of the way; pick "Custom unit..." and
// it appears so a free-text label (e.g. "Sachet") can be typed instead.
function setKitchenUnitSelect(baseUnitId, unitText) {
    const select = document.getElementById("kp-unit-select");
    const customInput = document.getElementById("kp-unit-custom");
    if (baseUnitId) {
        select.value = String(baseUnitId);
        customInput.value = "";
        customInput.classList.add("hidden");
    } else {
        select.value = "__custom";
        customInput.value = unitText || "";
        customInput.classList.remove("hidden");
    }
}

// Editing an existing material → base unit is read-only (server ignores changes
// anyway); creating a new one → editable.
function lockBaseUnit(locked) {
    const select = document.getElementById("kp-unit-select");
    const customInput = document.getElementById("kp-unit-custom");
    const hint = document.getElementById("kpUnitLockedHint");
    if (select) {
        select.disabled = locked;
        select.classList.toggle("bg-gray-100", locked);
        select.classList.toggle("cursor-not-allowed", locked);
    }
    if (customInput) customInput.disabled = locked;
    if (hint) hint.classList.toggle("hidden", !locked);
}

document.addEventListener("DOMContentLoaded", () => {
    loadUnitOfMeasureOptions();

    document
        .getElementById("kp-type")
        ?.addEventListener("change", updateKitchenUnitFieldsForType);

    // Live thumbnail as soon as a menu-item photo is chosen.
    document.getElementById("kp-image")?.addEventListener("change", (e) => {
        const file = e.target.files?.[0];
        const prev = document.getElementById("kpImagePreview");
        if (!prev) return;
        if (file && file.type.startsWith("image/")) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                prev.innerHTML = `<img src="${ev.target.result}" class="h-full w-full object-cover" alt="preview">`;
            };
            reader.readAsDataURL(file);
        } else {
            prev.innerHTML = '<i class="fa-solid fa-image"></i>';
        }
    });

    document
        .getElementById("kp-unit-select")
        ?.addEventListener("change", (e) => {
            const opt = e.target.selectedOptions[0];
            const customInput = document.getElementById("kp-unit-custom");
            if (opt && opt.value === "__custom") {
                customInput.value = "";
                customInput.classList.remove("hidden");
                customInput.focus();
            } else if (opt) {
                customInput.classList.add("hidden");
            }
        });
});

async function loadKitchenUnitConversions(productId) {
    const list = document.getElementById("kpConversionsList");
    list.innerHTML = '<p class="text-xs text-gray-400">Loading...</p>';
    try {
        const data = await apiFetch(`/products/${productId}/unit-conversions`);
        renderKitchenUnitConversions(data.conversions || []);
    } catch (err) {
        list.innerHTML =
            '<p class="text-xs text-rose-500">Failed to load units</p>';
    }
}

function renderKitchenUnitConversions(conversions) {
    const list = document.getElementById("kpConversionsList");
    if (!conversions.length) {
        list.innerHTML =
            '<p class="text-xs text-gray-400 italic">No alternate units yet — add one below.</p>';
        return;
    }
    list.innerHTML = conversions
        .map(
            (c) => `
        <div class="flex items-center justify-between text-sm bg-white border border-gray-200 rounded-lg px-3 py-2">
            <span class="text-gray-700"><b class="text-gray-900">1 ${c.unit?.code ?? ""}</b> = ${parseFloat(c.factor)} base unit</span>
            <button type="button" onclick="deleteKitchenUnitConversion(${c.id})" class="h-6 w-6 inline-flex items-center justify-center rounded-full text-gray-400 hover:bg-rose-50 hover:text-rose-600 text-xs transition">✕</button>
        </div>
    `,
        )
        .join("");
}

async function addKitchenUnitConversion() {
    const productId = document.getElementById("kp-id").value;
    const unitId = document.getElementById("kpConversionUnit").value;
    const factor = document.getElementById("kpConversionFactor").value;
    if (!productId) {
        showToast({ message: "Save the product first.", type: "warning" });
        return;
    }
    if (!unitId || !factor) {
        showToast({
            message: "Pick a unit and enter a factor.",
            type: "warning",
        });
        return;
    }
    try {
        await apiFetch(`/products/${productId}/unit-conversions`, {
            method: "POST",
            body: JSON.stringify({ unit_id: unitId, factor }),
        });
        document.getElementById("kpConversionFactor").value = "";
        loadKitchenUnitConversions(productId);
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

async function deleteKitchenUnitConversion(conversionId) {
    const productId = document.getElementById("kp-id").value;
    try {
        await apiFetch(`/unit-conversions/${conversionId}`, {
            method: "DELETE",
        });
        loadKitchenUnitConversions(productId);
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

// ============================================================
// COOKING PRODUCT PICKER (for setting a recipe)
// ============================================================
let _menuFilterDebounce = null;

document.addEventListener("DOMContentLoaded", () => {
    document
        .getElementById("menuFilterSearch")
        ?.addEventListener("input", () => {
            clearTimeout(_menuFilterDebounce);
            _menuFilterDebounce = setTimeout(
                () => loadCookingProductPicker(),
                350,
            );
        });
    document
        .getElementById("menuFilterCategory")
        ?.addEventListener("change", () => loadCookingProductPicker());
    document
        .getElementById("menuFilterRecipe")
        ?.addEventListener("change", () => loadCookingProductPicker());
});

// Populate the menu category dropdown once (fg categories only).
async function loadMenuCategoryOptions() {
    const sel = document.getElementById("menuFilterCategory");
    if (!sel || sel.dataset.loaded) return;
    try {
        const cats = await apiFetch("/categories?kind=fg&active=1");
        sel.innerHTML =
            '<option value="">All Categories</option>' +
            cats
                .map(
                    (c) =>
                        `<option value="${c.id}">${escHtml(c.name)}</option>`,
                )
                .join("");
        sel.dataset.loaded = "1";
    } catch (err) {
        /* stays "All Categories" */
    }
}

// Menu grid groups a dish's variants into one card and paginates by dish.
let _menuGroups = [];
let _menuPage = 1;
const MENU_PER_PAGE = 16;

async function loadCookingProductPicker(page = 1) {
    loadMenuCategoryOptions();
    const container = document.getElementById("cookingProductPicker");
    container.innerHTML =
        '<p class="text-sm text-gray-400 p-3 col-span-full">Loading...</p>';
    try {
        const search =
            document.getElementById("menuFilterSearch")?.value.trim() ?? "";
        const category =
            document.getElementById("menuFilterCategory")?.value ?? "";
        const recipe = document.getElementById("menuFilterRecipe")?.value ?? "";
        const params = new URLSearchParams({
            type: "cooking_product",
            limit: 2000, // fetch all matching; we group by dish and paginate client-side
            page: 1,
        });
        if (search) params.set("search", search);
        if (category) params.set("category_id", category);
        if (recipe) params.set("recipe_status", recipe);
        const result = await apiFetch(`/kitchen/products?${params.toString()}`);
        const rows = result.data || [];

        // Group variants of the same dish (same name) into ONE card. Fetched with a
        // big limit so all a dish's variants land together; then paginate by dish.
        const byName = new Map();
        rows.forEach((p) => {
            const key = p.name ?? "";
            if (!byName.has(key)) byName.set(key, []);
            byName.get(key).push(p);
        });
        _menuGroups = [...byName.entries()].map(([name, variants]) => ({
            name,
            variants: variants.sort(
                (a, b) => (Number(a.sort_order) || 0) - (Number(b.sort_order) || 0) || a.id - b.id,
            ),
        }));
        _menuPage = Math.max(1, page);
        renderMenuPage();
    } catch (err) {
        container.innerHTML =
            '<p class="text-sm text-rose-500 p-3 col-span-full">Failed to load cooking products</p>';
    }
}

// Render one page (16 dishes) of the grouped menu.
function renderMenuPage() {
    const container = document.getElementById("cookingProductPicker");
    if (!_menuGroups.length) {
        container.innerHTML = `<p class="text-sm text-gray-400 p-3 col-span-full">No menu items match these filters.</p>`;
        renderMenuPagination();
        return;
    }
    const totalPages = Math.max(1, Math.ceil(_menuGroups.length / MENU_PER_PAGE));
    if (_menuPage > totalPages) _menuPage = totalPages;
    const slice = _menuGroups.slice((_menuPage - 1) * MENU_PER_PAGE, _menuPage * MENU_PER_PAGE);
    container.innerHTML = slice.map(renderMenuGroupCard).join("");
    renderMenuPagination();
}

// One card per dish: image/name/description, then a chip per variant (its own
// price + status colour) that opens that variant's recipe.
function renderMenuGroupCard(g) {
    const rep = g.variants[0] || {};
    const imageSrc = rep.image
        ? `/thumb?f=${encodeURIComponent(rep.image)}&s=300`
        : "assets/defult/placeholder.png";
    const nameEsc = (g.name ?? "").replace(/'/g, "&#39;");

    const chips = g.variants
        .map((v) => {
            // A variant with no recipe (0 components) can't be sold, so it's
            // effectively disabled (red) regardless of its saved status.
            const noRecipe = Number(v.components_count || 0) === 0;
            const st = noRecipe ? 2 : Number(v.status) || 1;
            const tone =
                st === 1
                    ? "border-emerald-300 bg-emerald-50 text-emerald-700"
                    : st === 3
                      ? "border-amber-300 bg-amber-50 text-amber-700"
                      : "border-rose-300 bg-rose-50 text-rose-600";
            const label = v.variant || "Default";
            const price = parseFloat(v.sell_price || 0).toFixed(2);
            return `<button type="button"
                onclick="event.stopPropagation(); openRecipeModal(${v.id}, '${nameEsc}', '${(v.variant ?? "").replace(/'/g, "&#39;")}')"
                title="${escHtml(label)} — $${price}${noRecipe ? " (no recipe — disabled)" : ""}"
                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-2xs font-semibold ${tone}">
                ${escHtml(label)} <span class="opacity-70">$${price}</span>
            </button>`;
        })
        .join("");

    return `
<div class="group relative overflow-hidden rounded-2xl bg-white border border-gray-200 shadow-sm hover:shadow-lg transition-all duration-200 flex flex-col">
    <div class="relative h-32 overflow-hidden bg-gray-100">
        <img src="${imageSrc}" onerror="this.src='assets/defult/placeholder.png'" alt="${escHtml(g.name ?? "")}" loading="lazy"
            class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300">
        <span class="absolute bottom-2 right-2 bg-black/70 text-white text-[10px] font-bold px-2 py-1 rounded-lg">${g.variants.length} ${g.variants.length > 1 ? "variants" : "variant"}</span>
    </div>
    <div class="p-3 flex-1 min-h-0 flex flex-col">
        <h3 class="font-bold text-gray-800 text-sm leading-tight line-clamp-2">${escHtml(g.name ?? "")}</h3>
        ${rep.description ? `<p class="text-[11px] text-gray-500 line-clamp-1 mt-0.5">${escHtml(rep.description)}</p>` : ""}
        <div class="mt-2 pt-2 border-t border-gray-100 flex flex-wrap gap-1">${chips}</div>
    </div>
</div>`;
}

// Client-side pagination over the grouped dishes.
function renderMenuPagination() {
    const el = document.getElementById("cookingProductPagination");
    if (!el) return;
    el.innerHTML = "";
    const totalPages = Math.max(1, Math.ceil(_menuGroups.length / MENU_PER_PAGE));
    if (totalPages <= 1) return;
    const from = (_menuPage - 1) * MENU_PER_PAGE + 1;
    const to = Math.min(_menuPage * MENU_PER_PAGE, _menuGroups.length);
    const info = document.createElement("span");
    info.className = "text-xs text-gray-500 mr-2";
    info.textContent = `${from}–${to} of ${_menuGroups.length} dishes`;
    el.appendChild(info);
    for (let i = 1; i <= totalPages; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        btn.className = `px-2.5 py-1 rounded-md text-xs font-medium ${i === _menuPage ? "bg-gray-900 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`;
        btn.onclick = () => { _menuPage = i; renderMenuPage(); };
        el.appendChild(btn);
    }
}

// ============================================================
// MANAGE RECIPE — typed BOM: components (always consumed when the dish is
// prepared) + add-ons (optional extras like "Add Mushroom" −5 g / "Extra
// Mushroom" −8 g). Quantities are unit-aware: entered in any unit the
// material defines, deducted in its base unit. The avg cost shown is
// computed from on-hand material costs; the chef's manual cost stays
// authoritative.
// ============================================================
let _rawMaterialsCache = [];
let _recipeRowIndex = 0;

function closeRecipeModal() {
    document.getElementById("default-modal-recipe")?.classList.add("hidden");
    document.getElementById("default-modal-recipe")?.classList.remove("flex");
}

function switchRecipeModalTab(tab) {
    document
        .querySelectorAll(".recipe-modal-tab-btn")
        .forEach((b) =>
            b.classList.toggle("active", b.dataset.recipeTab === tab),
        );
    document
        .querySelectorAll(".recipe-modal-tab-panel")
        .forEach((p) => p.classList.add("hidden"));
    document
        .getElementById(`recipeModalTab-${tab}`)
        ?.classList.remove("hidden");
}

function rmById(id) {
    return _rawMaterialsCache.find((rm) => rm.id === Number(id)) || null;
}

// ---- Cooking routine steps (per variant) ----
// Each step carries its own labour cost; the sum is the variant's routing cost,
// which is added on top of material cost to give the finished good's total.
function addRoutineStep(instruction = "", cost = "") {
    const body = document.getElementById("recipeRoutineBody");
    if (!body) return;
    const row = document.createElement("div");
    row.className = "routine-step flex items-start gap-2";
    row.innerHTML = `
        <span class="routine-step-no shrink-0 mt-2 h-6 w-6 rounded-full bg-amber-100 text-amber-700 text-xs font-bold flex items-center justify-center tabular-nums">1</span>
        <textarea rows="1" class="routine-step-text flex-1 rounded-lg border-gray-300 px-3 py-1.5 text-sm resize-y" placeholder="Describe this step...">${(instruction ?? "").replace(/</g, "&lt;")}</textarea>
        <input type="number" step="0.0001" min="0" value="${cost === "" || cost == null ? "" : trimNum(cost, 4)}"
            class="routine-step-cost shrink-0 w-24 rounded-lg border-gray-300 px-2 py-1.5 text-sm text-right"
            placeholder="0.00" title="Labour cost of this step" oninput="recalcRoutingCost()">
        <button type="button" class="shrink-0 mt-1.5 text-rose-500 hover:text-rose-700" title="Remove step"
            onclick="this.closest('.routine-step').remove(); renumberRoutineSteps(); recalcRoutingCost();">
            <i class="fa-solid fa-xmark"></i>
        </button>`;
    body.appendChild(row);
    renumberRoutineSteps();
    recalcRoutingCost();
}

function renumberRoutineSteps() {
    document
        .querySelectorAll("#recipeRoutineBody .routine-step .routine-step-no")
        .forEach((el, i) => (el.textContent = i + 1));
}

// Roll the step costs up into the routing-cost field and refresh the total.
function recalcRoutingCost() {
    let total = 0;
    document.querySelectorAll("#recipeRoutineBody .routine-step-cost").forEach((el) => {
        total += parseFloat(el.value) || 0;
    });
    const hasSteps = document.querySelectorAll("#recipeRoutineBody .routine-step").length > 0;
    const routingEl = document.getElementById("recipeRoutingCost");
    if (routingEl && hasSteps) {
        // Steps drive the figure once any exist — show it read-only so the two
        // can't disagree.
        routingEl.value = trimNum(total, 4);
        routingEl.readOnly = true;
        routingEl.classList.add("bg-gray-100", "cursor-not-allowed");
        routingEl.title = "Sum of the routine step costs";
    } else if (routingEl) {
        routingEl.readOnly = false;
        routingEl.classList.remove("bg-gray-100", "cursor-not-allowed");
        routingEl.title = "";
    }
    recalcRecipeTotals();
}

async function openRecipeModal(productId, name, variant) {
    document.getElementById("recipeProductId").value = productId;
    document.getElementById("recipeProductLabel").textContent =
        `${name || ""}${variant ? " · " + variant : ""}`;
    _recipeDishName = name || "";
    _recipeCurrentVariant = variant || "";
    switchRecipeModalTab("components");

    _recipeRowIndex = 0;
    document.getElementById("recipeComponentsBody").innerHTML = "";
    document.getElementById("recipeAddonsBody").innerHTML = "";
    document.getElementById("recipeRoutineBody").innerHTML = "";
    document.getElementById("recipeAvgCost").textContent = "—";
    const totalCostEl = document.getElementById("recipeTotalCost");
    if (totalCostEl) totalCostEl.textContent = "—";
    document.getElementById("recipeChefCost").value = "";
    document.getElementById("recipeSellPrice").value = "";
    const routingCostEl = document.getElementById("recipeRoutingCost");
    if (routingCostEl) routingCostEl.value = "";
    const routineLabel = document.getElementById("routineVariantLabel");
    if (routineLabel) routineLabel.textContent = variant || name || "this variant";

    document.getElementById("default-modal-recipe").classList.remove("hidden");
    document.getElementById("default-modal-recipe").classList.add("flex");

    showRecipeLoading(true); // shimmer placeholder over the (cleared) body while we fetch

    try {
        // The raw-material catalog rarely changes and is the same for every variant,
        // so fetch it once and reuse the cache — a variant switch then only fetches
        // the recipe itself (one call), which is what made switching feel sluggish.
        const recipePromise = apiFetch(`/products/${productId}/recipe`);
        const rawPromise =
            _rawMaterialsCache && _rawMaterialsCache.length
                ? Promise.resolve(_rawMaterialsCache)
                : apiFetch("/products/raw-materials");
        const [rawMaterials, recipeData] = await Promise.all([
            rawPromise,
            recipePromise,
        ]);

        _rawMaterialsCache = rawMaterials;
        _recipeDishName = recipeData.name ?? name ?? "";

        (recipeData.components || []).forEach((line) =>
            addRecipeRow("component", line),
        );
        (recipeData.addons || []).forEach((line) =>
            addRecipeRow("add_on", line),
        );
        if (!(recipeData.components || []).length) addRecipeRow("component");

        (recipeData.routine || []).forEach((step) =>
            addRoutineStep(step.instruction, step.cost),
        );
        if (!(recipeData.routine || []).length) addRoutineStep();

        document.getElementById("recipeChefCost").value = Number(
            recipeData.chef_cost ?? 0,
        ).toFixed(4);
        document.getElementById("recipeSellPrice").value = Number(
            recipeData.sell_price ?? 0,
        ).toFixed(2);
        const routingCostEl = document.getElementById("recipeRoutingCost");
        if (routingCostEl)
            routingCostEl.value = Number(recipeData.routing_cost ?? 0).toFixed(4);
        renderRecipeVariantBar(recipeData.variants || [], productId);
        applyVariantStatus(recipeData.status);
        recalcRoutingCost(); // also refreshes totals + locks the field when steps exist
        playRecipeFadeIn(); // ease the freshly rendered content in
    } catch (err) {
        showToast({ message: "Failed to load recipe data", type: "error" });
    } finally {
        showRecipeLoading(false);
    }
}

// Shimmer skeleton shown over the recipe body while a variant loads. Built once
// and toggled; inline styles keep it independent of the stale Tailwind build.
function showRecipeLoading(show) {
    const body = document.getElementById("recipeModalBody");
    if (!body) return;
    let ov = document.getElementById("recipeLoadingOverlay");
    if (show) {
        body.scrollTop = 0; // so the absolute overlay lines up with the top
        body.style.position = "relative";
        if (!ov) {
            ov = document.createElement("div");
            ov.id = "recipeLoadingOverlay";
            ov.style.cssText =
                "position:absolute;inset:0;z-index:6;background:rgba(255,255,255,.82);display:flex;flex-direction:column;gap:.7rem;padding:1.5rem;";
            ov.innerHTML =
                '<div class="kt-skeleton" style="height:14px;width:40%"></div>' +
                '<div class="kt-skeleton" style="height:42px;width:100%"></div>' +
                '<div class="kt-skeleton" style="height:42px;width:100%"></div>' +
                '<div class="kt-skeleton" style="height:42px;width:100%"></div>' +
                '<div class="kt-skeleton" style="height:42px;width:72%"></div>' +
                '<div style="flex:1 1 auto"></div>' +
                '<div class="kt-skeleton" style="height:48px;width:100%"></div>';
            body.appendChild(ov);
        }
        ov.style.display = "flex";
    } else if (ov) {
        ov.style.display = "none";
    }
}

// Replay the fade-in on the recipe body each time new content lands.
function playRecipeFadeIn() {
    const body = document.getElementById("recipeModalBody");
    if (!body) return;
    body.classList.remove("kt-fade-in");
    void body.offsetWidth; // force reflow so the animation restarts
    body.classList.add("kt-fade-in");
}

// ---- Variant status (1 Enable / 2 Disable / 3 Under development) ----
// Changing status never touches the recipe, costs or history — it only controls
// whether the cashier sees the variant. Enable = live (recipe locked); Disable
// and Under development are both hidden and editable.
let _recipeVariantStatus = 1;

function applyVariantStatus(statusInt) {
    _recipeVariantStatus = Number(statusInt) || 1;
    const sel = document.getElementById("recipeStatusSelect");
    if (sel) sel.value = String(_recipeVariantStatus);

    // Editing (recipe / add-ons / routing) is allowed ONLY while the variant is
    // Under development (3). Active (1) and Disabled (2) are both locked — switch
    // to Under development first. Mirrors the server guard in saveRecipe/update.
    setRecipeEditable(_recipeVariantStatus === 3);
    // Keep the status select itself usable even when the recipe is locked (it's how
    // the chef unlocks — by switching to Under development).
    if (sel) sel.disabled = false;
}

// Lock (or unlock) every editable control in the recipe modal. Navigation stays
// live (variant pills, tab switches, the Disable/Enable toggle, Cancel/Close) so
// the chef can still view everything read-only.
function setRecipeEditable(editable) {
    // HARD lock: a variant can only be edited while it is Under development. When
    // it isn't, every editable control in the recipe modal (cost/price fields,
    // components, add-ons, routing steps, Save, Rename) is disabled and a banner
    // explains how to unlock. Only the status select stays live (see below) so the
    // chef can switch the variant to Under development to edit it.
    ["recipeChefCost", "recipeSellPrice", "recipeRoutingCost", "recipeRenameBtn", "btnSaveRecipe"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) { el.disabled = !editable; el.style.pointerEvents = editable ? "" : "none"; }
    });
    ["recipeModalTab-components", "recipeModalTab-addons", "recipeModalTab-routine"].forEach((id) => {
        const panel = document.getElementById(id);
        if (panel) panel.querySelectorAll("input, select, textarea, button").forEach((el) => {
            el.disabled = !editable;
            el.style.pointerEvents = editable ? "" : "none";
        });
    });

    const banner = document.getElementById("recipeLockBanner");
    if (banner) {
        banner.classList.toggle("hidden", editable);
        banner.classList.toggle("flex", !editable);
    }

    // Routing field still follows its steps-driven read-only rule when editable.
    if (editable) recalcRoutingCost();
}

// A live (Enabled) variant is no longer hard-locked — it's fully editable and saved
// in one click, with the banner as the only reminder that changes go live. The old
// per-click "recipe locked" toast is intentionally gone (it was noise once editing
// was allowed).

async function setVariantStatus(statusInt) {
    const productId = document.getElementById("recipeProductId").value;
    if (!productId) return;
    const target = Number(statusInt) || 1;

    // Publishing (going live) is the one that exposes it to customers — confirm.
    if (target === 1 && _recipeVariantStatus !== 1 &&
        !confirm("Publish this variant?\n\nIt will go live on the Sale screen and its recipe/price will be locked while enabled.")) {
        // revert the select back to the current status
        const sel = document.getElementById("recipeStatusSelect");
        if (sel) sel.value = String(_recipeVariantStatus);
        return;
    }

    try {
        const data = await apiFetch(`/products/${productId}/toggle-status`, {
            method: "POST",
            body: JSON.stringify({ status: target }),
        });
        applyVariantStatus(data.value);
        showToast({ message: data.message, type: data.enabled ? "success" : "warning" });
        // Update the cached variant's status so the bar recolours correctly.
        const v = _recipeVariants.find((x) => Number(x.id) === Number(productId));
        if (v) v.status = data.value;
        renderRecipeVariantBar(_recipeVariants, productId);
        loadCookingProductPicker();
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

// ---- Variant switcher inside Manage Recipe ----
// Each size is its own product row with its own BOM, so switching pills reloads
// that variant's components/add-ons rather than editing one shared recipe.
let _recipeVariants = [];
let _recipeDishName = "";
let _recipeCurrentVariant = "";

// Rename just this variant's label (e.g. "M" → "Medium"). The dish name and its
// grouping on the Sale screen stay the same. Only available while the variant is
// disabled (setRecipeEditable locks the button otherwise).
async function renameVariant() {
    const productId = document.getElementById("recipeProductId").value;
    if (!productId) return;

    const current = _recipeCurrentVariant || "";
    const next = (prompt("Rename this variant (e.g. Small, Medium, Large):", current) || "").trim();
    if (next === "" || next === current) return;

    try {
        const data = await apiFetch(`/products/${productId}/rename-variant`, {
            method: "POST",
            body: JSON.stringify({ variant: next }),
        });
        showToast({ message: data.message || "Variant renamed", type: "success" });
        // Reopen so the header, variant pills and picker all reflect the new name.
        openRecipeModal(productId, _recipeDishName, next);
        loadCookingProductPicker();
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}
let _variantSortMode = false;

function renderRecipeVariantBar(variants, currentId) {
    _recipeVariants = variants;
    const bar = document.getElementById("recipeVariantBar");
    const pills = document.getElementById("recipeVariantPills");
    if (!bar || !pills) return;

    // Nothing to switch between when the dish has a single variant.
    if (variants.length < 2) {
        bar.classList.add("hidden");
        bar.classList.remove("flex");
        _variantSortMode = false;
        return;
    }
    bar.classList.remove("hidden");
    bar.classList.add("flex");

    pills.innerHTML = variants
        .map((v, i) => {
            const active = Number(v.id) === Number(currentId);
            const label = v.variant || "Base";
            if (_variantSortMode) {
                return `
                <span class="inline-flex items-center gap-1 shrink-0 rounded-full border border-gray-300 bg-white pl-2.5 pr-1 py-1 text-xs">
                    <span class="${active ? "font-bold text-gray-900" : "text-gray-600"}">${label}</span>
                    <button type="button" class="px-1 text-gray-400 hover:text-gray-800 ${i === 0 ? "opacity-30 pointer-events-none" : ""}"
                        onclick="moveVariant(${i}, -1)" title="Move earlier">&#8592;</button>
                    <button type="button" class="px-1 text-gray-400 hover:text-gray-800 ${i === variants.length - 1 ? "opacity-30 pointer-events-none" : ""}"
                        onclick="moveVariant(${i}, 1)" title="Move later">&#8594;</button>
                </span>`;
            }
            // Colour tells the chef the variant's status at a glance: green = on
            // sale (1), red = disabled (2), amber = under development (3). The one
            // being edited gets a ring so "active" stays readable.
            const st = Number(v.status) || 1;
            const tone =
                st === 1
                    ? "bg-emerald-50 border-emerald-300 text-emerald-700 hover:border-emerald-500"
                    : st === 3
                      ? "bg-amber-50 border-amber-300 text-amber-700 hover:border-amber-500"
                      : "bg-rose-50 border-rose-300 text-rose-600 hover:border-rose-500";
            const badge =
                st === 1 ? "" : st === 3
                    ? ' <i class="fa-solid fa-flask ml-0.5 text-2xs" title="Under development"></i>'
                    : ' <i class="fa-solid fa-ban ml-0.5 text-2xs" title="Disabled"></i>';
            const titleTxt = st === 1 ? "On sale" : st === 3 ? "Under development" : "Disabled";
            const ring = active ? " ring-2 ring-amber-400 ring-offset-1" : "";
            return `
            <button type="button" onclick="switchRecipeVariant(${v.id})" title="${titleTxt}"
                class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold border transition ${tone}${ring}">
                ${escHtml(label)}${badge}
            </button>`;
        })
        .join("");
}

function switchRecipeVariant(id) {
    if (Number(id) === Number(document.getElementById("recipeProductId").value))
        return;
    const v = _recipeVariants.find((x) => Number(x.id) === Number(id));
    openRecipeModal(id, _recipeDishName, v?.variant ?? "");
}

function toggleVariantSortMode() {
    _variantSortMode = !_variantSortMode;
    const btn = document.getElementById("recipeVariantSortBtn");
    btn.innerHTML = _variantSortMode
        ? '<i class="fa-solid fa-check"></i> Done'
        : '<i class="fa-solid fa-arrow-up-wide-short"></i> Reorder';
    btn.classList.toggle("bg-amber-400", _variantSortMode);
    btn.classList.toggle("border-amber-400", _variantSortMode);
    renderRecipeVariantBar(
        _recipeVariants,
        document.getElementById("recipeProductId").value,
    );
}

async function moveVariant(index, delta) {
    const target = index + delta;
    if (target < 0 || target >= _recipeVariants.length) return;
    [_recipeVariants[index], _recipeVariants[target]] = [
        _recipeVariants[target],
        _recipeVariants[index],
    ];
    renderRecipeVariantBar(
        _recipeVariants,
        document.getElementById("recipeProductId").value,
    );
    try {
        await apiFetch("/products/variants/reorder", {
            method: "POST",
            body: JSON.stringify({ ids: _recipeVariants.map((v) => v.id) }),
        });
    } catch (err) {
        showToast({ message: "Failed to save order", type: "error" });
    }
}

// Units this material accepts: its base unit (factor 1) then its alternates.
// A material with no structured units keeps its legacy free-text unit as the
// only choice (factor 1 — nothing to convert against).
function unitOptionsFor(rm, selectedUnitId) {
    if (!rm) return '<option value="">—</option>';
    const units = rm.units || [];
    if (!units.length) {
        return `<option value="" data-factor="1" data-code="${rm.unit ?? ""}">${rm.unit ?? "—"}</option>`;
    }
    return units
        .map(
            (u) =>
                `<option value="${u.unit_id}" data-factor="${u.factor}" data-code="${u.code}" ${Number(selectedUnitId) === u.unit_id ? "selected" : ""}>${u.code}</option>`,
        )
        .join("");
}

function addRecipeRow(type = "component", line = null) {
    const isAddon = type === "add_on";
    const tbody = document.getElementById(
        isAddon ? "recipeAddonsBody" : "recipeComponentsBody",
    );
    const rowId = `recipe-row-${_recipeRowIndex++}`;
    const rm = rmById(line?.raw_material_id);
    const selectedUnitId = line?.unit_id ?? rm?.base_unit_id ?? null;

    // Typeahead instead of a full dropdown: the chef types and picks from a
    // short recommended list. The hidden .recipe-raw-material carries the id
    // (kept as-is so rowBaseQtyAndCost/saveRecipe still read it); the visible
    // .recipe-search shows the chosen name.
    const materialCell = `
        <td class="py-1.5 pr-2">
            <input type="hidden" class="recipe-raw-material" value="${line?.raw_material_id ?? ""}">
            <input type="text" class="recipe-search w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm"
                value="${(rm?.name ?? "").replace(/"/g, "&quot;")}" placeholder="Type to search material…"
                autocomplete="off" oninput="onRecipeMaterialSearch(this)" onfocus="onRecipeMaterialSearch(this)">
        </td>`;
    const qtyCell = `
        <td class="py-1.5 pr-2">
            <input type="number" step="0.0001" min="0.0001" value="${line?.quantity != null ? trimNum(line.quantity, 4) : ""}" class="recipe-qty w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm" placeholder="1" oninput="onRecipeRowInput(this)">
        </td>`;
    const unitCell = `
        <td class="py-1.5 pr-2">
            <select class="recipe-unit-select w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm" onchange="onRecipeRowInput(this)">
                ${unitOptionsFor(rm, selectedUnitId)}
            </select>
        </td>`;
    const removeCell = `
        <td class="py-1.5 text-center">
            <button type="button" class="text-rose-500 hover:text-rose-700" onclick="document.getElementById('${rowId}').remove(); recalcRecipeTotals();">✕</button>
        </td>`;

    const tr = document.createElement("tr");
    tr.id = rowId;
    tr.dataset.lineType = type;

    if (isAddon) {
        tr.innerHTML = `
            <td class="py-1.5 pr-2">
                <input type="text" value="${(line?.addon_name ?? "").replace(/"/g, "&quot;")}" class="recipe-addon-name w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm" placeholder="Add Mushroom">
            </td>
            ${materialCell}
            ${qtyCell}
            ${unitCell}
            <td class="recipe-avg-cost py-1.5 pr-2 text-right text-xs text-gray-700 tabular-nums">—</td>
            <td class="py-1.5 pr-2">
                <input type="number" step="0.01" min="0" value="${line?.extra_price ?? 0}" class="recipe-extra-price w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm text-right" placeholder="0.00">
            </td>
            ${removeCell}
        `;
    } else {
        tr.innerHTML = `
            ${materialCell}
            ${qtyCell}
            ${unitCell}
            <td class="recipe-base-qty py-1.5 pr-2 text-right text-xs text-gray-500 tabular-nums">—</td>
            <td class="recipe-avg-cost py-1.5 pr-2 text-right text-xs text-gray-700 tabular-nums">—</td>
            ${removeCell}
        `;
    }

    tbody.appendChild(tr);
    updateRowComputed(tr);
}

// ---- Recipe material typeahead ----------------------------------------
// Filters the already-loaded _rawMaterialsCache client-side (no round-trip),
// rendering a short recommended list in a body-level box so it can escape the
// modal's overflow clipping.
let _recipeSearchInput = null;

function recipeResultsBox() {
    let box = document.getElementById("recipeResultsBox");
    if (!box) {
        box = document.createElement("div");
        box.id = "recipeResultsBox";
        box.className =
            "hidden fixed z-[9999] max-h-56 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg";
        document.body.appendChild(box);
    }
    return box;
}

function positionRecipeResults() {
    if (!_recipeSearchInput) return;
    const box = recipeResultsBox();
    const r = _recipeSearchInput.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    box.style.left = `${r.left}px`;
    box.style.width = `${r.width}px`;
    if (below < 180 && r.top > below) {
        box.style.top = "auto";
        box.style.bottom = `${window.innerHeight - r.top + 4}px`;
    } else {
        box.style.bottom = "auto";
        box.style.top = `${r.bottom + 4}px`;
    }
}

function closeRecipeResults() {
    recipeResultsBox().classList.add("hidden");
    _recipeSearchInput = null;
}

function onRecipeMaterialSearch(input) {
    _recipeSearchInput = input;
    // Typing a new name clears the previously-picked id until a fresh pick.
    const tr = input.closest("tr");
    const hidden = tr?.querySelector(".recipe-raw-material");
    const term = input.value.trim().toLowerCase();
    if (hidden) {
        const current = rmById(hidden.value);
        if (!current || current.name.toLowerCase() !== term) hidden.value = "";
    }

    const box = recipeResultsBox();
    const matches = _rawMaterialsCache
        .filter(
            (rm) =>
                !term ||
                rm.name.toLowerCase().includes(term) ||
                (rm.code ?? "").toLowerCase().includes(term),
        )
        .slice(0, 20);

    if (!matches.length) {
        box.innerHTML =
            '<p class="px-3 py-2 text-xs text-gray-400">No material found</p>';
    } else {
        box.innerHTML = matches
            .map(
                (rm) => `
            <button type="button" class="recipe-result w-full text-left px-3 py-2 hover:bg-amber-50 border-b border-gray-100 last:border-0"
                data-id="${rm.id}" onmousedown="event.preventDefault()" onclick="pickRecipeMaterial(this)">
                <span class="block text-sm text-gray-800">${escHtml(rm.name)}${rm.code ? ` <span class="text-2xs text-gray-400">${escHtml(rm.code)}</span>` : ""}</span>
                <span class="block text-2xs text-gray-400">on hand ${trimNum(rm.stock || 0, 4)} ${escHtml(rm.base_unit_code ?? rm.unit ?? "")} · avg ${trimNum(rm.avg_cost || 0, 4)}/${escHtml(rm.base_unit_code ?? rm.unit ?? "")}</span>
            </button>`,
            )
            .join("");
    }
    box.classList.remove("hidden");
    positionRecipeResults();
}

function pickRecipeMaterial(btn) {
    const rm = rmById(btn.dataset.id);
    const tr = _recipeSearchInput?.closest("tr");
    if (!rm || !tr) return;
    tr.querySelector(".recipe-raw-material").value = rm.id;
    tr.querySelector(".recipe-search").value = rm.name;
    const unitSelect = tr.querySelector(".recipe-unit-select");
    if (unitSelect)
        unitSelect.innerHTML = unitOptionsFor(rm, rm.base_unit_id ?? null);
    closeRecipeResults();
    updateRowComputed(tr);
    recalcRecipeTotals();
}

document.addEventListener("click", (e) => {
    if (e.target.closest(".recipe-search") || e.target.closest("#recipeResultsBox"))
        return;
    closeRecipeResults();
});
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeRecipeResults();
});

function onRecipeRowInput(el) {
    updateRowComputed(el.closest("tr"));
    recalcRecipeTotals();
}

function rowBaseQtyAndCost(tr) {
    const rm = rmById(tr.querySelector(".recipe-raw-material")?.value);
    const qty = parseFloat(tr.querySelector(".recipe-qty")?.value) || 0;
    const factor =
        parseFloat(
            tr.querySelector(".recipe-unit-select")?.selectedOptions[0]?.dataset
                .factor ?? "1",
        ) || 1;
    const baseQty = qty * factor;
    const avg = rm ? baseQty * (parseFloat(rm.avg_cost) || 0) : 0;
    return { rm, baseQty, avg };
}

function updateRowComputed(tr) {
    // Both components and add-ons show the material cost of what they consume.
    // (Add-ons show it so the chef can decide a sensible Extra Price against it.)
    const { rm, baseQty, avg } = rowBaseQtyAndCost(tr);
    const baseCell = tr.querySelector(".recipe-base-qty"); // components only
    const costCell = tr.querySelector(".recipe-avg-cost");
    if (baseCell)
        baseCell.textContent =
            rm && baseQty
                ? `${+baseQty.toFixed(4)} ${rm.base_unit_code ?? rm.unit ?? ""}`
                : "—";
    // Up to 4 dp, trailing zeros trimmed — so a tiny per-line cost like 0.0004
    // still shows instead of rounding to 0.000, while a clean 1.5 stays "1.5".
    if (costCell) costCell.textContent = rm && baseQty ? trimNum(avg, 4) : "—";
}

function recalcRecipeTotals() {
    let material = 0;
    document.querySelectorAll("#recipeComponentsBody tr").forEach((tr) => {
        material += rowBaseQtyAndCost(tr).avg;
    });
    const el = document.getElementById("recipeAvgCost");
    if (el) el.textContent = material > 0 ? trimNum(material, 4) : "—";

    // Finished-good cost = materials + routing (labour from the routine steps).
    const routing = parseFloat(document.getElementById("recipeRoutingCost")?.value) || 0;
    const totalEl = document.getElementById("recipeTotalCost");
    const total = material + routing;
    if (totalEl) totalEl.textContent = total > 0 ? trimNum(total, 4) : "—";
}

async function saveRecipe() {
    const productId = document.getElementById("recipeProductId").value;
    if (!productId) return;

    const rows = [
        ...document.querySelectorAll("#recipeComponentsBody tr"),
        ...document.querySelectorAll("#recipeAddonsBody tr"),
    ];

    const recipe = rows
        .map((tr) => {
            const rawMaterialId = tr.querySelector(
                ".recipe-raw-material",
            )?.value;
            if (!rawMaterialId) return null;
            const unitSel = tr.querySelector(".recipe-unit-select");
            const opt = unitSel?.selectedOptions[0];
            const lineData = {
                raw_material_id: Number(rawMaterialId),
                line_type: tr.dataset.lineType,
                quantity: Number(tr.querySelector(".recipe-qty")?.value),
                unit_id: unitSel?.value ? Number(unitSel.value) : null,
                unit: opt?.dataset.code || opt?.textContent.trim() || null,
            };
            if (tr.dataset.lineType === "add_on") {
                lineData.addon_name =
                    tr.querySelector(".recipe-addon-name")?.value.trim() ||
                    null;
                lineData.extra_price =
                    Number(tr.querySelector(".recipe-extra-price")?.value) || 0;
            }
            return lineData;
        })
        .filter(Boolean);

    const unnamedAddon = recipe.find(
        (l) => l.line_type === "add_on" && !l.addon_name,
    );
    if (unnamedAddon) {
        switchRecipeModalTab("addons");
        showToast({
            message: 'Every add-on needs a name (e.g. "Add Mushroom").',
            type: "warning",
        });
        return;
    }

    const sellPrice = parseFloat(
        document.getElementById("recipeSellPrice")?.value,
    );
    const chefCost = parseFloat(
        document.getElementById("recipeChefCost")?.value,
    );

    // Ordered routine steps for this variant, each with its labour cost
    // (blank rows dropped). Their sum becomes the variant's routing cost.
    const routine = Array.from(
        document.querySelectorAll("#recipeRoutineBody .routine-step"),
    )
        .map((row) => ({
            instruction: row.querySelector(".routine-step-text")?.value.trim() ?? "",
            cost: parseFloat(row.querySelector(".routine-step-cost")?.value) || 0,
        }))
        .filter((s) => s.instruction !== "");

    const routingCost = parseFloat(
        document.getElementById("recipeRoutingCost")?.value,
    );

    try {
        const data = await apiFetch(`/products/${productId}/recipe`, {
            method: "POST",
            body: JSON.stringify({
                recipe,
                sell_price: Number.isFinite(sellPrice) ? sellPrice : null,
                cost: Number.isFinite(chefCost) ? chefCost : null,
                routing_cost: Number.isFinite(routingCost) ? routingCost : null,
                routine,
            }),
        });
        showToast({ message: data.message || "Recipe saved", type: "success" });
        closeRecipeModal();
        loadCookingProductPicker(); // BOM counts on the menu cards
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

// ---- Add Variant: clone the open dish into another size ----
function openVariantModal() {
    const productId = document.getElementById("recipeProductId").value;
    if (!productId) return;

    document.getElementById("variantSourceLabel").textContent =
        "Copy of " + document.getElementById("recipeProductLabel").textContent;
    document.getElementById("variantName").value = "";
    document.getElementById("variantCopyRecipe").checked = true;
    // Prefill price/cost from the dish being copied — the chef edits what differs.
    document.getElementById("variantSellPrice").value =
        trimNum(document.getElementById("recipeSellPrice").value, 2) || 0;
    document.getElementById("variantCost").value =
        trimNum(document.getElementById("recipeChefCost").value, 6) || 0;

    const m = document.getElementById("variantModal");
    m.classList.remove("hidden");
    m.classList.add("flex");
    document.getElementById("variantName").focus();
}

function closeVariantModal() {
    const m = document.getElementById("variantModal");
    m.classList.add("hidden");
    m.classList.remove("flex");
}

async function saveVariant() {
    const productId = document.getElementById("recipeProductId").value;
    const variant = document.getElementById("variantName").value.trim();
    if (!variant) {
        showToast({
            message: "Give the variant a name (e.g. Large).",
            type: "warning",
        });
        return;
    }

    try {
        const data = await apiFetch(
            `/products/${productId}/duplicate-variant`,
            {
                method: "POST",
                body: JSON.stringify({
                    variant,
                    sell_price:
                        document.getElementById("variantSellPrice").value || 0,
                    cost: document.getElementById("variantCost").value || 0,
                    copy_recipe:
                        document.getElementById("variantCopyRecipe").checked,
                }),
            },
        );
        showToast({
            message: data.message || "Variant created",
            type: "success",
        });
        closeVariantModal();
        // Stay in Manage Recipe and jump straight to the new variant so the chef
        // can set up its components right away — the variant bar reloads with it.
        if (data.product?.id) {
            openRecipeModal(
                data.product.id,
                data.product.name,
                data.product.variant,
            );
        }
        loadCookingProductPicker(); // refresh the grid behind the modal
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}

// ============================================================
// PURCHASE (chef side) — raw + packaging material only. The chef buys in
// whatever unit the supplier sells and in whichever currency they're holding;
// the server converts BOTH (currency → USD, entered unit → base unit) before
// posting the GRN, so stock and costs stay in one system.
// ============================================================
let _purchaseRowIndex = 0;
let _purchaseCurrencies = []; // [{code, factor, is_default}]
let _purMaterialCache = {}; // id → material, for rows picked via search

function purCurrency() {
    const code = document.getElementById("purchaseCurrency")?.value || "USD";
    const cur = _purchaseCurrencies.find((c) => c.code === code);
    return { code, factor: parseFloat(cur?.factor) || 1 };
}

async function loadKitchenPurchase() {
    if (!_purchaseCurrencies.length) {
        try {
            _purchaseCurrencies = await apiFetch("/kitchen/currencies");
            const sel = document.getElementById("purchaseCurrency");
            if (sel) {
                sel.innerHTML = _purchaseCurrencies
                    .map(
                        (c) =>
                            `<option value="${c.code}" ${Number(c.is_default) ? "selected" : ""}>${c.code}</option>`,
                    )
                    .join("");
            }
        } catch (err) {
            /* selector stays empty → treated as USD */
        }
    }
    // Default the GRN date to today (chef can change it).
    const dateEl = document.getElementById("purchaseDate");
    if (dateEl && !dateEl.value) dateEl.value = new Date().toISOString().slice(0, 10);

    onPurchaseCurrencyChange();
    loadPurchaseVendors();
    if (!document.querySelectorAll("#purchaseLinesBody tr").length)
        addPurchaseRow();
}

// ---- Vendors (shared with the cashier/purchasing screen) ----
let _kitchenVendors = [];

function _vendorRows(resp) {
    // /vendors/list returns { data: <paginator> }; the rows are one level deeper.
    return resp?.data?.data ?? resp?.data ?? [];
}

async function loadPurchaseVendors() {
    try {
        const resp = await apiFetch("/vendors/list?active=1");
        _kitchenVendors = _vendorRows(resp);
        const sel = document.getElementById("purchaseVendor");
        if (sel) {
            const keep = sel.value;
            sel.innerHTML =
                '<option value="">— No vendor —</option>' +
                _kitchenVendors
                    .map((v) => `<option value="${v.id}">${v.name}${v.code ? " (" + v.code + ")" : ""}</option>`)
                    .join("");
            sel.value = keep;
        }
    } catch (err) {
        /* chef may lack vendor access — leave "No vendor" only */
    }
}

function openVendorModal() {
    document.getElementById("kitchenVendorModal").classList.remove("hidden");
    document.getElementById("kitchenVendorModal").classList.add("flex");
    renderVendorList();
}
function closeVendorModal() {
    document.getElementById("kitchenVendorModal").classList.add("hidden");
    document.getElementById("kitchenVendorModal").classList.remove("flex");
}
function renderVendorList() {
    const tb = document.getElementById("kitchenVendorList");
    if (!tb) return;
    tb.innerHTML = _kitchenVendors.length
        ? _kitchenVendors
              .map(
                  (v) =>
                      `<tr class="border-t border-gray-100"><td class="px-4 py-2 text-gray-500">${v.code ?? ""}</td><td class="px-4 py-2 font-medium text-gray-800">${v.name ?? ""}</td><td class="px-4 py-2 text-gray-500">${v.contact_person ?? ""}</td><td class="px-4 py-2 text-gray-500">${v.phone1 ?? ""}</td></tr>`,
              )
              .join("")
        : `<tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">No vendors yet</td></tr>`;
}

document.addEventListener("DOMContentLoaded", () => {
    document
        .getElementById("kitchenVendorForm")
        ?.addEventListener("submit", async (e) => {
            e.preventDefault();
            const val = (id) => document.getElementById(id)?.value.trim() || "";
            const name = val("kv-name");
            if (!name) return;
            try {
                const data = await apiFetch("/vendors", {
                    method: "POST",
                    body: JSON.stringify({
                        name,
                        contact_person: val("kv-contact"),
                        email: val("kv-email"),
                        phone1: val("kv-phone"),
                        phone2: val("kv-phone2"),
                        address1: val("kv-address1"),
                        city: val("kv-city"),
                        country: val("kv-country"),
                        website: val("kv-website"),
                        status: 1,
                    }),
                });
                showToast({ title: "Vendor added", message: `${name} saved.`, type: "success" });
                [
                    "kv-name", "kv-contact", "kv-email", "kv-phone",
                    "kv-phone2", "kv-address1", "kv-city", "kv-country", "kv-website",
                ].forEach((id) => { const el = document.getElementById(id); if (el) el.value = ""; });
                await loadPurchaseVendors();
                renderVendorList();
                // Select the vendor just created (matched by code from the response).
                const sel = document.getElementById("purchaseVendor");
                const made = _kitchenVendors.find((v) => v.code === data.code);
                if (sel && made) sel.value = made.id;
            } catch (err) {
                showToast({ message: err.message, type: "error" });
            }
        });
});

// Switching currency re-labels the cost column and rescales what's already typed,
// so the numbers on screen always mean what the header says.
function onPurchaseCurrencyChange() {
    const { code, factor } = purCurrency();
    const label = document.getElementById("purCostCurrency");
    if (label) label.textContent = `(${code})`;
    const totalLabel = document.getElementById("purTotalCurrency");
    if (totalLabel) totalLabel.textContent = `(${code})`;

    const prev =
        parseFloat(
            document.getElementById("purchaseCurrency")?.dataset.prevFactor ||
                "1",
        ) || 1;
    if (prev !== factor) {
        // Rescale BOTH the per-unit cost AND the line total into the new currency.
        // Previously only the cost was rescaled, so the Total column and Grand
        // Total stayed in the old currency — the "amount doesn't convert when
        // switching to Riel" bug.
        const ratio = factor / prev;
        document.querySelectorAll("#purchaseLinesBody tr").forEach((tr) => {
            const costInput = tr.querySelector(".pur-cost");
            const cval = parseFloat(costInput?.value);
            if (costInput && !isNaN(cval) && cval > 0) {
                costInput.value = trimNum(cval * ratio, 6);
            }
            const totalInput = tr.querySelector(".pur-total-input");
            const tval = parseFloat(totalInput?.value);
            if (totalInput && !isNaN(tval) && tval > 0) {
                totalInput.value = trimNum(tval * ratio, 2);
            }
            // Refresh the per-line "≈ USD" hint for the new currency.
            const alt = tr.querySelector(".pur-total-alt");
            const lineTotal = parseFloat(totalInput?.value) || 0;
            if (alt)
                alt.innerHTML =
                    factor !== 1 && lineTotal
                        ? `≈ ${(lineTotal / factor).toFixed(4)} USD`
                        : "&nbsp;";
        });
    }
    const sel = document.getElementById("purchaseCurrency");
    if (sel) sel.dataset.prevFactor = String(factor);
    recalcPurchaseTotal();
}

function addPurchaseRow() {
    const tbody = document.getElementById("purchaseLinesBody");
    if (!tbody) return;
    const rowId = `purchase-row-${_purchaseRowIndex++}`;
    const tr = document.createElement("tr");
    tr.id = rowId;
    tr.className = "border-t border-gray-100";
    tr.innerHTML = `
        <td class="px-4 py-2">
            <div class="relative">
                <input type="text" class="pur-search w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm"
                    placeholder="Type to search material..." autocomplete="off"
                    oninput="onPurchaseSearchInput(this)" onfocus="onPurchaseSearchInput(this)">
                <input type="hidden" class="pur-material">
            </div>
        </td>
        <td class="px-4 py-2 pur-onhand text-xs text-gray-500 tabular-nums">—</td>
        <td class="px-4 py-2">
            <input type="number" step="0.0001" min="0.0001" class="pur-qty w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm" placeholder="1" oninput="onPurchaseRowInput(this)">
        </td>
        <td class="px-4 py-2">
            <select class="pur-unit w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm" onchange="onPurchaseRowInput(this)"></select>
        </td>
        <td class="px-4 py-2">
            <input type="number" step="0.000001" min="0" class="pur-cost w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm text-right" placeholder="0.00" oninput="onPurchaseCostInput(this)">
        </td>
        <td class="px-4 py-2 pur-receives text-right text-xs text-gray-500 tabular-nums">—</td>
        <td class="px-4 py-2">
            <!-- Either field can be typed: cost/unit fills Total, Total back-fills cost/unit -->
            <input type="number" step="0.01" min="0" class="pur-total-input w-full rounded-lg border-gray-300 px-2 py-1.5 text-sm text-right font-semibold" placeholder="0.00" oninput="onPurchaseTotalInput(this)">
            <div class="pur-total-alt text-2xs text-gray-400 tabular-nums text-right mt-0.5">&nbsp;</div>
        </td>
        <td class="px-4 py-2 text-center">
            <button type="button" class="text-rose-500 hover:text-rose-700" onclick="document.getElementById('${rowId}').remove(); recalcPurchaseTotal();">✕</button>
        </td>
    `;
    tbody.appendChild(tr);
}

// ---- Material typeahead: queries the DB as you type ----
// The result list lives at BODY level with position:fixed, not inside the row.
// An absolutely-positioned child would be clipped by the table card
// (overflow-hidden) and the panel's scroll container (overflow-auto) — no
// z-index can escape an overflow clip, so it has to leave those ancestors.
let _purSearchTimer = null;
let _purActiveInput = null;

function purResultsBox() {
    let box = document.getElementById("purResultsBox");
    if (!box) {
        box = document.createElement("div");
        box.id = "purResultsBox";
        box.className =
            "hidden fixed z-[9999] max-h-56 overflow-y-auto bg-white border border-gray-200 rounded-lg shadow-lg";
        document.body.appendChild(box);
    }
    return box;
}

function positionPurResults() {
    if (!_purActiveInput) return;
    const box = purResultsBox();
    const r = _purActiveInput.getBoundingClientRect();
    // Flip above the field when there isn't room below.
    const below = window.innerHeight - r.bottom;
    box.style.left = `${r.left}px`;
    box.style.width = `${r.width}px`;
    if (below < 180 && r.top > below) {
        box.style.top = "auto";
        box.style.bottom = `${window.innerHeight - r.top + 4}px`;
    } else {
        box.style.bottom = "auto";
        box.style.top = `${r.bottom + 4}px`;
    }
}

function closePurResults() {
    purResultsBox().classList.add("hidden");
    _purActiveInput = null;
}

function onPurchaseSearchInput(input) {
    _purActiveInput = input;
    clearTimeout(_purSearchTimer);
    const term = input.value.trim();
    _purSearchTimer = setTimeout(() => runPurchaseSearch(input, term), 250);
}

async function runPurchaseSearch(input, term) {
    _purActiveInput = input;
    const box = purResultsBox();
    box.innerHTML = '<p class="px-3 py-2 text-xs text-gray-400">Searching…</p>';
    box.classList.remove("hidden");
    positionPurResults();
    try {
        const params = new URLSearchParams({ limit: 15 });
        if (term) params.set("search", term);
        const rows = await apiFetch(
            `/products/raw-materials?${params.toString()}`,
        );
        rows.forEach((rm) => {
            _purMaterialCache[rm.id] = rm;
        });

        if (!rows.length) {
            box.innerHTML =
                '<p class="px-3 py-2 text-xs text-gray-400">No material found</p>';
            positionPurResults();
            return;
        }
        box.innerHTML = rows
            .map(
                (rm) => `
            <button type="button" class="pur-result w-full text-left px-3 py-2 hover:bg-amber-50 border-b border-gray-100 last:border-0"
                data-id="${rm.id}" onclick="pickPurchaseMaterial(this)">
                <span class="block text-sm text-gray-800">${rm.name}</span>
                <span class="block text-2xs text-gray-400">${rm.code ?? ""} · on hand ${trimNum(rm.stock || 0, 4)} ${rm.base_unit_code ?? rm.unit ?? ""}</span>
            </button>
        `,
            )
            .join("");
        positionPurResults();
    } catch (err) {
        box.innerHTML =
            '<p class="px-3 py-2 text-xs text-rose-500">Search failed</p>';
        positionPurResults();
    }
}

function pickPurchaseMaterial(btn) {
    const rm = _purMaterialCache[btn.dataset.id];
    // The list is no longer inside the row, so resolve the row from the input
    // that opened it rather than from the clicked button.
    const tr = _purActiveInput?.closest("tr");
    if (!rm || !tr) return;
    closePurResults();
    fillPurchaseRow(tr, rm);
}

// Populate a purchase row from a material object — shared by the click-to-pick
// typeahead and the "Buy from the Material list" flow.
function fillPurchaseRow(tr, rm) {
    if (!tr || !rm) return;
    tr.querySelector(".pur-material").value = rm.id;
    tr.querySelector(".pur-search").value = rm.name;
    tr.querySelector(".pur-unit").innerHTML = unitOptionsFor(
        rm,
        rm.base_unit_id ?? null,
    );
    tr.querySelector(".pur-onhand").textContent =
        `${trimNum(rm.stock || 0, 4)} ${rm.base_unit_code ?? rm.unit ?? ""}`;

    // Seed cost from the material's average (base-unit USD) → chosen unit + currency.
    const costInput = tr.querySelector(".pur-cost");
    if (!costInput.value) {
        const unitFactor =
            parseFloat(
                tr.querySelector(".pur-unit")?.selectedOptions[0]?.dataset
                    .factor ?? "1",
            ) || 1;
        const { factor } = purCurrency();
        costInput.value = trimNum(
            Number(rm.avg_cost || 0) * unitFactor * factor,
            6,
        );
        tr.dataset.lastEdited = "cost";
    }
    onPurchaseRowInput(costInput);
}

// Show, under On Hand, how much this material is still needed (a red hint) so the
// chef can see the target while typing the purchase Qty. `need` is in the base
// unit. Shared by the per-row "Buy" flow and the bulk "Get Needed" pull.
function setPurchaseNeedHint(tr, rm, need) {
    const onhand = tr?.querySelector(".pur-onhand");
    if (!onhand) return;
    const baseCode = rm.base_unit_code ?? rm.unit ?? "";
    const stockTxt = `${trimNum(rm.stock || 0, 4)} ${escHtml(baseCode)}`;
    onhand.innerHTML =
        Number(need) > 0
            ? `${stockTxt} <span class="block text-rose-600 font-semibold">need ${trimNum(Number(need), 4)} ${escHtml(baseCode)}</span>`
            : stockTxt;
}

// ---- Purchase modal (opened from the Material list; no more Purchase tab) ----
async function openPurchaseModal(materialId = null, needed = 0) {
    const modal = document.getElementById("purchaseModal");
    if (!modal) return;
    modal.classList.remove("hidden");
    modal.classList.add("flex");

    // Fresh form each open: load currencies/vendors/date, then start with one row.
    document.getElementById("purchaseLinesBody").innerHTML = "";
    _purMaterialCache = {};
    const remark = document.getElementById("purchaseRemark");
    if (remark) remark.value = "";
    await loadKitchenPurchase(); // seeds currency, vendors, date; adds a blank row if empty

    if (materialId) {
        try {
            const rows = await apiFetch(
                `/products/raw-materials?search=&limit=200`,
            );
            const rm = (rows || []).find((r) => Number(r.id) === Number(materialId));
            if (rm) {
                _purMaterialCache[rm.id] = rm;
                // Use the blank row loadKitchenPurchase added, else make one.
                let tr = document.querySelector("#purchaseLinesBody tr:last-child");
                if (!tr || tr.querySelector(".pur-material")?.value) {
                    addPurchaseRow();
                    tr = document.querySelector("#purchaseLinesBody tr:last-child");
                }
                fillPurchaseRow(tr, rm);

                // Show what the pending orders still need for this material — as a
                // hint only. The actual purchase Qty stays empty for the chef to type.
                setPurchaseNeedHint(tr, rm, Number(needed) || 0);
                tr.querySelector(".pur-qty")?.focus();
            }
        } catch (e) {
            /* fall back to a blank row the chef can search in */
        }
    }
}

function closePurchaseModal() {
    const modal = document.getElementById("purchaseModal");
    if (!modal) return;
    modal.classList.add("hidden");
    modal.classList.remove("flex");
    closePurResults();
}

// Lightweight yes/no dialog — self-contained (builds its own DOM, so no blade
// markup is needed). Layout + z-index are INLINE styles on purpose: the kitchen
// page ships a stale Tailwind build, so new arbitrary utilities (e.g. z-[10000])
// aren't compiled and the dialog would otherwise render behind the z-50 modal.
// Only the guaranteed custom .kitchen-* classes are used. Sits above the purchase
// modal (z-50) and the material-search box (z-9999).
//
// Without `checkbox`: resolves true on confirm, false on cancel/backdrop/Escape.
// With `checkbox` ({ label, checked }): resolves { confirmed, checked } instead,
// so the caller can read an optional toggle chosen in the dialog.
function kitchenConfirm(
    message,
    {
        title = "Please confirm",
        confirmLabel = "Yes",
        cancelLabel = "No",
        icon = "fa-circle-question",
        checkbox = null,
    } = {},
) {
    return new Promise((resolve) => {
        const overlay = document.createElement("div");
        overlay.style.cssText =
            "position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);padding:1rem;";
        const checkboxHtml = checkbox
            ? `<label style="display:flex;align-items:center;gap:.55rem;margin:1rem 0 0;padding-left:3rem;cursor:pointer;font-size:.8125rem;color:#374151;">
                    <input type="checkbox" data-check ${checkbox.checked ? "checked" : ""} style="width:1rem;height:1rem;accent-color:#f59e0b;cursor:pointer;">
                    <span>${escHtml(checkbox.label)}</span>
                </label>`
            : "";
        overlay.innerHTML = `
            <div class="kitchen-modal-card" style="width:100%;max-width:28rem;">
                <div style="padding:1.25rem;">
                    <div style="display:flex;align-items:flex-start;gap:.75rem;">
                        <span style="height:2.25rem;width:2.25rem;flex:0 0 auto;border-radius:.5rem;background:#fef3c7;border:1px solid #fde68a;display:flex;align-items:center;justify-content:center;color:#b45309;"><i class="fa-solid ${icon}"></i></span>
                        <div>
                            <h3 style="font-size:15px;font-weight:600;color:#111827;line-height:1.25;margin:0;">${escHtml(title)}</h3>
                            <p style="font-size:.875rem;color:#4b5563;margin:.25rem 0 0;white-space:pre-line;">${escHtml(message)}</p>
                        </div>
                    </div>
                    ${checkboxHtml}
                </div>
                <div class="kitchen-modal-footer">
                    <button type="button" data-no class="kitchen-btn-outline">${escHtml(cancelLabel)}</button>
                    <button type="button" data-yes class="kitchen-btn-dark">${escHtml(confirmLabel)}</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        const result = (confirmed) => {
            const checked = !!overlay.querySelector("[data-check]")?.checked;
            return checkbox ? { confirmed, checked } : confirmed;
        };
        const done = (confirmed) => {
            document.removeEventListener("keydown", onKey);
            const out = result(confirmed);
            overlay.remove();
            resolve(out);
        };
        const onKey = (e) => {
            if (e.key === "Escape") done(false);
        };
        overlay
            .querySelector("[data-yes]")
            .addEventListener("click", () => done(true));
        overlay
            .querySelector("[data-no]")
            .addEventListener("click", () => done(false));
        overlay.addEventListener("click", (e) => {
            if (e.target === overlay) done(false);
        });
        document.addEventListener("keydown", onKey);
    });
}

// "Get Needed": asks yes/no, then adds one purchase line per material the pending
// orders still need, pre-filled at the needed (base-unit) quantity. Unlike the
// per-row Buy hint, this button DOES fill the Qty — it's an explicit bulk action.
async function purchaseGetNeeded() {
    let list;
    try {
        list = await apiFetch("/kitchen/needed-materials");
    } catch (e) {
        showToast({ message: "Could not load needed materials.", type: "error" });
        return;
    }
    if (!Array.isArray(list) || !list.length) {
        showToast({
            message: "Nothing needed — no pending orders require materials.",
            type: "info",
        });
        return;
    }

    const n = list.length;
    // The endpoint already drops anything current stock covers, so every item here
    // genuinely needs buying. Checkbox is unchecked by default: by default we add
    // only the items and leave the Qty blank; ticking it also fills the shortfall qty.
    const { confirmed, checked: pullQty } = await kitchenConfirm(
        `${n} material${n === 1 ? "" : "s"} still need${n === 1 ? "s" : ""} buying for the pending orders (stock won't cover ${n === 1 ? "it" : "them"}).\n\nAdd ${n === 1 ? "it" : "them"} to this purchase?`,
        {
            title: "Get needed materials",
            confirmLabel: `Yes, add ${n === 1 ? "it" : "all"}`,
            cancelLabel: "No",
            icon: "fa-cart-plus",
            checkbox: { label: "Also fill each line's quantity with the amount to buy", checked: false },
        },
    );
    if (!confirmed) return;

    const tbody = document.getElementById("purchaseLinesBody");
    if (!tbody) return;

    // Drop empty starter rows so the needed lines aren't preceded by a blank one.
    tbody.querySelectorAll("tr").forEach((tr) => {
        if (!tr.querySelector(".pur-material")?.value) tr.remove();
    });

    let added = 0;
    list.forEach((rm) => {
        // Skip anything already on a line so re-clicking doesn't duplicate rows.
        const dup = Array.from(tbody.querySelectorAll(".pur-material")).some(
            (h) => Number(h.value) === Number(rm.id),
        );
        if (dup) return;

        _purMaterialCache[rm.id] = rm;
        addPurchaseRow();
        const tr = tbody.querySelector("tr:last-child");
        fillPurchaseRow(tr, rm); // picks base unit (factor 1) + seeds cost from avg
        const buyQty = Number(rm.shortfall ?? rm.needed ?? 0);
        // Only fill the Qty when the chef ticked the box; otherwise leave it blank.
        // Fill the shortfall (what's left to buy after stock), not the full demand.
        if (pullQty) {
            const qtyInput = tr.querySelector(".pur-qty");
            if (qtyInput && buyQty > 0) {
                qtyInput.value = trimNum(buyQty, 4);
                onPurchaseRowInput(qtyInput); // recompute the line total
            }
        }
        // Always show how much is still needed to buy on the line (red hint under
        // On Hand), whether or not the Qty was auto-filled.
        setPurchaseNeedHint(tr, rm, buyQty);
        added++;
    });

    recalcPurchaseTotal();
    showToast({
        message: added
            ? `Added ${added} needed material${added === 1 ? "" : "s"}${pullQty ? " with quantities" : ""}.`
            : "All needed materials are already on the purchase.",
        type: added ? "success" : "info",
    });
}

document.addEventListener("click", (e) => {
    if (e.target.closest(".pur-search") || e.target.closest("#purResultsBox"))
        return;
    closePurResults();
});
document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closePurResults();
});
// Fixed positioning doesn't follow the page — re-anchor on scroll/resize.
window.addEventListener("resize", () => {
    positionPurResults();
    positionRecipeResults();
});
document.addEventListener(
    "scroll",
    () => {
        if (_purActiveInput) positionPurResults();
        if (_recipeSearchInput) positionRecipeResults();
    },
    true,
);

function purRowMaterial(tr) {
    return _purMaterialCache[tr.querySelector(".pur-material")?.value] || null;
}

// Two ways to price a line: type Cost/Unit → Total is computed, or type Total →
// Cost/Unit is back-computed. Qty changes recompute whichever the chef last typed.
function onPurchaseCostInput(el) {
    el.closest("tr").dataset.lastEdited = "cost";
    onPurchaseRowInput(el);
}
function onPurchaseTotalInput(el) {
    el.closest("tr").dataset.lastEdited = "total";
    onPurchaseRowInput(el);
}

function onPurchaseRowInput(el) {
    const tr = el.closest("tr");
    const rm = purRowMaterial(tr);
    const qty = parseFloat(tr.querySelector(".pur-qty")?.value) || 0;
    const unitFactor =
        parseFloat(
            tr.querySelector(".pur-unit")?.selectedOptions[0]?.dataset.factor ??
                "1",
        ) || 1;
    const { code, factor } = purCurrency();
    const costInput = tr.querySelector(".pur-cost");
    const totalInput = tr.querySelector(".pur-total-input");

    // Reconcile cost-each vs total based on which the chef last touched.
    if (tr.dataset.lastEdited === "total") {
        const total = parseFloat(totalInput.value) || 0;
        costInput.value = qty > 0 ? trimNum(total / qty, 6) : "";
    } else {
        const cost = parseFloat(costInput.value) || 0;
        totalInput.value = qty * cost ? trimNum(qty * cost, 2) : "";
    }

    const baseQty = qty * unitFactor;
    tr.querySelector(".pur-receives").textContent =
        rm && baseQty
            ? `${trimNum(baseQty, 4)} ${rm.base_unit_code ?? rm.unit ?? ""}`
            : "—";

    const lineTotal = parseFloat(totalInput.value) || 0;
    // Show the USD equivalent alongside when entering in a non-base currency.
    const alt = tr.querySelector(".pur-total-alt");
    if (alt)
        alt.innerHTML =
            factor !== 1 && lineTotal
                ? `≈ ${(lineTotal / factor).toFixed(4)} USD`
                : "&nbsp;";

    recalcPurchaseTotal();
}

function recalcPurchaseTotal() {
    let total = 0;
    document.querySelectorAll("#purchaseLinesBody tr").forEach((tr) => {
        total += parseFloat(tr.querySelector(".pur-total-input")?.value) || 0;
    });
    const { code, factor } = purCurrency();
    const el = document.getElementById("purchaseGrandTotal");
    const alt = document.getElementById("purchaseGrandTotalAlt");
    if (el) el.textContent = `${total.toFixed(2)} ${code}`;
    if (alt)
        alt.innerHTML =
            factor !== 1 && total
                ? `≈ ${(total / factor).toFixed(4)} USD`
                : "&nbsp;";
}

async function postKitchenPurchase() {
    const warehouseId = document.getElementById("purchaseWarehouse")?.value;
    if (!warehouseId) {
        showToast({ message: "Select a warehouse first.", type: "warning" });
        return;
    }

    const lines = Array.from(document.querySelectorAll("#purchaseLinesBody tr"))
        .map((tr) => {
            const productId = tr.querySelector(".pur-material")?.value;
            const qty = parseFloat(tr.querySelector(".pur-qty")?.value);
            if (!productId || !qty || qty <= 0) return null;
            const unitSel = tr.querySelector(".pur-unit");
            return {
                product_id: Number(productId),
                qty,
                cost: parseFloat(tr.querySelector(".pur-cost")?.value) || 0,
                unit_id: unitSel?.value ? Number(unitSel.value) : null,
            };
        })
        .filter(Boolean);

    if (!lines.length) {
        showToast({
            message: "Add at least one line with a material and quantity.",
            type: "warning",
        });
        return;
    }

    try {
        const data = await apiFetch("/kitchen/purchase", {
            method: "POST",
            body: JSON.stringify({
                warehouse_id: Number(warehouseId),
                vendor_id:
                    Number(document.getElementById("purchaseVendor")?.value) || null,
                posting_date:
                    document.getElementById("purchaseDate")?.value || null,
                currency_code: purCurrency().code,
                remark:
                    document.getElementById("purchaseRemark")?.value || null,
                lines,
            }),
        });
        showToast({
            message: data.message || "Purchase posted",
            type: "success",
        });
        document.getElementById("purchaseLinesBody").innerHTML = "";
        document.getElementById("purchaseRemark").value = "";
        _purMaterialCache = {};
        recalcPurchaseTotal();
        closePurchaseModal();
        // Reflect the new stock in the Material list behind the modal.
        if (typeof loadKitchenProducts === "function") loadKitchenProducts();
    } catch (err) {
        showToast({ message: err.message, type: "error" });
    }
}


// ============================================================
// KITCHEN ORDER — prepared dishes (output) + materials consumed
// ============================================================
let _kitchenOrdersCache = {}; // id → order (with lines) for the detail modal

document.addEventListener("DOMContentLoaded", () => {
    const from = document.getElementById("koFrom");
    const to = document.getElementById("koTo");
    const today = new Date().toISOString().slice(0, 10);
    if (from && !from.value)
        from.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
            .toISOString()
            .slice(0, 10);
    if (to && !to.value) to.value = today;
});

function kitchenOrderRange() {
    return {
        from: document.getElementById("koFrom")?.value || "",
        to: document.getElementById("koTo")?.value || "",
    };
}

async function loadKitchenOrders(page = 1) {
    const tbody = document.getElementById("kitchenOrdersBody");
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">Loading...</td></tr>`;
    const { from, to } = kitchenOrderRange();
    try {
        const params = new URLSearchParams({ page });
        if (from) params.set("from", from);
        if (to) params.set("to", to);
        const result = await apiFetch(`/kitchen/kitchen-orders?${params.toString()}`);
        const t = result.totals || {};
        document.getElementById("koTotOrders").textContent = t.orders ?? 0;
        document.getElementById("koTotQty").textContent = trimNum(t.qty || 0, 2);
        document.getElementById("koTotMaterial").textContent = Number(t.material_cost || 0).toFixed(2);
        document.getElementById("koTotFg").textContent = Number(t.fg_cost || 0).toFixed(2);
        renderKitchenOrders(result.orders?.data || []);
        renderKitchenOrdersPagination(result.orders || {});
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-rose-500">Failed to load kitchen orders</td></tr>`;
    }
}

function renderKitchenOrders(rows) {
    const tbody = document.getElementById("kitchenOrdersBody");
    _kitchenOrdersCache = {};
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No prepared dishes in this range</td></tr>`;
        return;
    }
    tbody.innerHTML = rows
        .map((o) => {
            _kitchenOrdersCache[o.id] = o;
            const dish = `${escHtml(o.name ?? "")}${o.variant ? ` <span class="text-amber-700 font-semibold">· ${escHtml(o.variant)}</span>` : ""}`;
            const n = (o.lines || []).length;
            return `
        <tr class="border-t border-gray-100">
            <td class="px-4 py-2.5 text-gray-500">${(o.posting_date || "").slice(0, 10)}</td>
            <td class="px-4 py-2.5 text-gray-500">${escHtml(o.document_no ?? "")}</td>
            <td class="px-4 py-2.5 font-medium text-gray-800">${dish}<span class="block text-2xs text-gray-400">${escHtml(o.item_code ?? "")}</span></td>
            <td class="px-4 py-2.5 text-right tabular-nums">${trimNum(o.qty || 0, 2)}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">${Number(o.material_cost || 0).toFixed(4)}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">${Number(o.routing_cost || 0).toFixed(4)}</td>
            <td class="px-4 py-2.5 text-right tabular-nums font-semibold">${Number(o.fg_cost || 0).toFixed(4)}</td>
            <td class="px-4 py-2.5 text-center">
                <button onclick="openKitchenOrderDetail(${o.id})" class="px-2 py-1 rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 text-xs font-medium"><i class="fa-solid fa-list-ul"></i> ${n}</button>
            </td>
        </tr>`;
        })
        .join("");
}

function renderKitchenOrdersPagination(result) {
    const el = document.getElementById("kitchenOrdersPagination");
    if (!el) return;
    el.innerHTML = "";
    if (!result.last_page || result.last_page <= 1) return;
    for (let i = 1; i <= result.last_page; i++) {
        const btn = document.createElement("button");
        btn.textContent = i;
        btn.className = `px-2.5 py-1 rounded-md text-xs font-medium ${i === result.current_page ? "bg-gray-900 text-white" : "bg-white border border-gray-200 text-gray-600 hover:bg-gray-50"}`;
        btn.onclick = () => loadKitchenOrders(i);
        el.appendChild(btn);
    }
}

function openKitchenOrderDetail(id) {
    const o = _kitchenOrdersCache[id];
    if (!o) return;
    document.getElementById("koDetailTitle").textContent =
        `${o.name ?? ""}${o.variant ? " · " + o.variant : ""}`;
    document.getElementById("koDetailSub").textContent =
        `${o.document_no ?? ""} · ${(o.posting_date || "").slice(0, 10)} · FG cost ${Number(o.fg_cost || 0).toFixed(4)}`;
    const body = document.getElementById("koDetailBody");
    const lines = o.lines || [];
    body.innerHTML = lines.length
        ? lines
              .map(
                  (l) => `
        <tr class="border-t border-gray-100">
            <td class="px-3 py-2"><span class="text-2xs px-2 py-0.5 rounded-full ${l.line_type === "add_on" ? "bg-sky-50 text-sky-600" : "bg-gray-100 text-gray-600"}">${l.line_type === "add_on" ? "Add-on" : "Component"}</span></td>
            <td class="px-3 py-2 text-gray-800">${escHtml(l.name ?? "")}</td>
            <td class="px-3 py-2 text-right tabular-nums">${trimNum(l.qty || 0, 4)} <span class="text-gray-400 text-xs">${escHtml(l.unit ?? "")}</span></td>
            <td class="px-3 py-2 text-right tabular-nums">${Number(l.cost_amount || 0).toFixed(4)}</td>
        </tr>`,
              )
              .join("")
        : `<tr><td colspan="4" class="px-3 py-8 text-center text-gray-400">No consumption recorded</td></tr>`;
    const modal = document.getElementById("kitchenOrderDetailModal");
    modal.classList.remove("hidden");
    modal.classList.add("flex");
}

function closeKitchenOrderDetail() {
    const modal = document.getElementById("kitchenOrderDetailModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
}

// Period summary: finished goods produced + all material consumed.
async function openKitchenSummary() {
    const modal = document.getElementById("kitchenSummaryModal");
    modal.classList.remove("hidden");
    modal.classList.add("flex");
    const fgBody = document.getElementById("ksFgBody");
    const matBody = document.getElementById("ksMatBody");
    fgBody.innerHTML = matBody.innerHTML =
        `<tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Loading...</td></tr>`;
    const { from, to } = kitchenOrderRange();
    try {
        const params = new URLSearchParams();
        if (from) params.set("from", from);
        if (to) params.set("to", to);
        const r = await apiFetch(`/kitchen/kitchen-orders/summary?${params.toString()}`);
        const t = r.totals || {};
        document.getElementById("ksRange").textContent = `${r.from} → ${r.to}`;
        document.getElementById("ksDishes").textContent = trimNum(t.dishes_qty || 0, 2);
        document.getElementById("ksMaterials").textContent = t.materials ?? 0;
        document.getElementById("ksMaterialCost").textContent = Number(t.material_cost || 0).toFixed(2);
        document.getElementById("ksFgCost").textContent = Number(t.fg_cost || 0).toFixed(2);

        const fg = r.fg || [];
        fgBody.innerHTML = fg.length
            ? fg.map((d) => `
        <tr class="border-t border-gray-100">
            <td class="px-4 py-2 font-medium text-gray-800">${escHtml(d.name ?? "")}${d.variant ? ` <span class="text-amber-700 font-semibold">· ${escHtml(d.variant)}</span>` : ""}</td>
            <td class="px-4 py-2 text-right tabular-nums">${trimNum(d.qty || 0, 2)}</td>
            <td class="px-4 py-2 text-right tabular-nums text-gray-500">${Number(d.material_cost || 0).toFixed(4)}</td>
            <td class="px-4 py-2 text-right tabular-nums text-gray-500">${Number(d.routing_cost || 0).toFixed(4)}</td>
            <td class="px-4 py-2 text-right tabular-nums font-semibold">${Number(d.fg_cost || 0).toFixed(4)}</td>
        </tr>`).join("")
            : `<tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No dishes produced in this period</td></tr>`;

        const mats = r.materials || [];
        matBody.innerHTML = mats.length
            ? mats.map((m) => `
        <tr class="border-t border-gray-100">
            <td class="px-4 py-2 font-medium text-gray-800">${escHtml(m.name ?? "")}</td>
            <td class="px-4 py-2 text-right tabular-nums">${trimNum(m.qty || 0, 4)} <span class="text-gray-400 text-xs">${escHtml(m.unit ?? "")}</span></td>
            <td class="px-4 py-2 text-right tabular-nums font-semibold">${Number(m.cost || 0).toFixed(4)}</td>
        </tr>`).join("")
            : `<tr><td colspan="3" class="px-4 py-8 text-center text-gray-400">No material consumed in this period</td></tr>`;
    } catch (err) {
        fgBody.innerHTML = matBody.innerHTML =
            `<tr><td colspan="5" class="px-4 py-6 text-center text-rose-500">Failed to load summary</td></tr>`;
    }
}

function closeKitchenSummary() {
    const modal = document.getElementById("kitchenSummaryModal");
    modal.classList.add("hidden");
    modal.classList.remove("flex");
}

function exportKitchenOrders() {
    const { from, to } = kitchenOrderRange();
    const params = new URLSearchParams();
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    window.location.href = `/kitchen/kitchen-orders/export?${params.toString()}`;
}

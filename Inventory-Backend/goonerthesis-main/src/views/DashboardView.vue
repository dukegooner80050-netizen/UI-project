<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import { getItems } from "../services/items";
import StockBadge from "../components/StockBadge.vue";
import DueBadge from "../components/DueBadge.vue";
import { getRequests, getMyOverdue } from "../services/requests";
import { getLogs } from "../services/logs";
import { getCurrentUser } from "../services/storage";
import LowStockRestockPanel from "../components/LowStockRestockPanel.vue";
import ReturnReportsPanel from "../components/ReturnReportsPanel.vue";
import { getLowStock } from "../services/restock";

// Chart.js
import Chart from "chart.js/auto";

import FullCalendar from "@fullcalendar/vue3";
import dayGridPlugin from "@fullcalendar/daygrid";
import interactionPlugin from "@fullcalendar/interaction";

/*  ROUTER  */
const router = useRouter();

/*  DASHBOARD FEATURES FOR REG.USER  */
const user = computed(() => getCurrentUser());
const isAdmin = computed(
  () => (user.value?.role || "").toLowerCase() === "amo",
);

/*  STATE  */
const items = ref([]);
const requests = ref([]);
const logs = ref([]);
const lastUpdated = ref("");
const selectedDayRequests = ref([]);
const selectedDay = ref("");
const showDayModal = ref(false);
// Request statuses (review workflow, roadmap #14). "In flight" = still
// waiting on a decision; "actionable" = Inventory Management can act now.
const IN_FLIGHT = ["pending", "with coordinator", "with inventory"];
const ACTIONABLE = ["pending", "with inventory"];
const isInFlight = (s) => IN_FLIGHT.includes((s || "").toLowerCase());
const isActionable = (s) => ACTIONABLE.includes((s || "").toLowerCase());

// One colour per status, matching the calendar badges. (Returned used to
// fall through to red, so it looked like a rejection.)
function statusBadgeClass(status) {
  const s = (status || "").toLowerCase();
  if (isInFlight(s)) return "bg-warning text-dark";
  if (s === "approved") return "bg-success";
  if (s === "rejected") return "bg-danger";
  if (s === "returned") return "bg-info text-dark";
  return "bg-secondary";
}

const lowStockModal = ref(false);
const overdueModal = ref(false);

// Items at Critical stock (or out), straight from the restock API so we know
// whether a restock request already exists for each one.
const criticalStock = ref([]);

// The urgent card can be closed. It stays closed for this browser session and
// comes back if a different set of items reaches Critical.
function urgentKey() {
  return `urgentCardDismissed:${getCurrentUser()?.idUsers ?? "anon"}`;
}
function readUrgentDismissed() {
  try {
    return sessionStorage.getItem(urgentKey()) || "";
  } catch {
    return "";
  }
}
const urgentDismissed = ref(readUrgentDismissed());

// The signed-in user's own overdue lines (borrowers). Admins use the list
// built from every request instead.
const myOverdue = ref([]);
const showPendingRequestsModal = ref(false);
/*  CLOCK  */
const now = ref(null); // Date object (PH time from API)
const clockStatus = ref("syncing"); // "syncing" | "synced" | "offline"
let clockTimer = null;
let resyncTimer = null;

async function fetchPHTime() {
  const urls = [
    "https://worldtimeapi.org/api/timezone/Asia/Manila",
    "https://timeapi.io/api/Time/current/zone?timeZone=Asia/Manila",
  ];

  for (const url of urls) {
    try {
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) continue;

      const data = await res.json();
      const iso = data.datetime || data.dateTime;
      if (!iso) continue;

      now.value = new Date(iso);
      clockStatus.value = "synced";
      return true;
    } catch (e) {
      // try next URL
    }
  }

  // If both APIs fail, mark offline and fall back (optional)
  clockStatus.value = "offline";
  if (!now.value) now.value = new Date();
  return false;
}

function tickPH() {
  if (!now.value) return;
  now.value = new Date(now.value.getTime() + 1000);
}
/* LOAD */
async function loadAll() {
  items.value = await getItems();
  requests.value = await getRequests();
  if (isAdmin.value) {
    logs.value = await getLogs({ limit: 5 });
    myOverdue.value = [];
    try {
      const low = await getLowStock();
      criticalStock.value = low
        .filter((r) => r.level === "Critical")
        .sort((a, b) => a.quantity - b.quantity);
    } catch (e) {
      criticalStock.value = [];
    }
  } else {
    logs.value = [];
    try {
      myOverdue.value = await getMyOverdue();
    } catch (e) {
      console.error("Failed to load overdue items:", e);
      myOverdue.value = [];
    }
  }

  lastUpdated.value = new Date().toLocaleString();
}

async function refresh() {
  await loadAll();
  renderCharts();
}

onMounted(async () => {
  await loadAll();

  renderCharts();

  await fetchPHTime();

  clockTimer = setInterval(tickPH, 1000);

  // The clock ticks locally every second; re-checking the time service every
  // 10 minutes is plenty.
  resyncTimer = setInterval(fetchPHTime, 600000);
});

/*  COMPACT CLOCK  */
const clockTime = computed(() =>
  now.value
    ? now.value.toLocaleTimeString("en-PH", {
        hour: "numeric",
        minute: "2-digit",
        second: "2-digit",
      })
    : "--:--:--",
);
const clockDate = computed(() =>
  now.value
    ? now.value.toLocaleDateString("en-PH", {
        weekday: "short",
        month: "short",
        day: "numeric",
        year: "numeric",
      })
    : "",
);
const clockTitle = computed(() =>
  clockStatus.value === "synced"
    ? "Synced with Philippine time"
    : clockStatus.value === "syncing"
      ? "Syncing with the time service..."
      : "Offline: showing this device's clock",
);

/*  CARD MODALS  */
function openLowStockModal() {
  lowStockModal.value = true;
}

/*  SUMMARY  */
const urgentItems = computed(() => criticalStock.value.slice(0, 5));
const urgentSignature = computed(() =>
  criticalStock.value
    .map((r) => r.iditems)
    .sort((a, b) => a - b)
    .join(","),
);
const urgentVisible = computed(
  () =>
    isAdmin.value &&
    criticalStock.value.length > 0 &&
    urgentDismissed.value !== urgentSignature.value,
);

function dismissUrgent() {
  urgentDismissed.value = urgentSignature.value;
  try {
    sessionStorage.setItem(urgentKey(), urgentSignature.value);
  } catch {
    /* ignore: the card just comes back on the next load */
  }
}
const urgentWithoutRequest = computed(
  () => criticalStock.value.filter((r) => !r.active_request_id).length,
);
const outOfStockCount = computed(
  () => items.value.filter((i) => i.stockLevel === "Out of Stock").length,
);

function scrollToRestock() {
  document
    .getElementById("restock-panel")
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}

const totalItems = computed(() =>
  items.value.reduce((a, i) => a + (Number(i.qty) || 0), 0),
);

// Low stock = at or below the item's own Minimum level (Critical, Minimum
// or Out of Stock) -- see roadmap #6.
const LOW_LEVELS = ["Out of Stock", "Critical", "Minimum"];
const lowStockCount = computed(
  () => items.value.filter((i) => LOW_LEVELS.includes(i.stockLevel)).length,
);

// Borrowed lines still out past their due date (roadmap #8). The server
// works out due/overdue per line; this just flattens them for the list.
const overdueLines = computed(() => {
  // Borrowers see only their own overdue items.
  if (!isAdmin.value) {
    return myOverdue.value.map((l) => ({
      key: l.key,
      requestId: l.requestId,
      requester: user.value?.username || "You",
      itemName: l.itemName,
      borrowedQty: l.borrowedQty,
      dueDate: l.dueDate,
      daysOverdue: l.daysOverdue,
    }));
  }

  const lines = [];
  requests.value.forEach((r) => {
    if ((r.status || "").toLowerCase() !== "approved") return;
    (r.items || []).forEach((it) => {
      if (!it.overdue) return;
      lines.push({
        key: `${r.id}-${it.requestItemId}`,
        requestId: r.id,
        requester: r.requester,
        itemName: it.itemName,
        borrowedQty: it.borrowedQty,
        dueDate: it.dueDate,
        daysOverdue: it.daysOverdue,
      });
    });
  });
  return lines.sort((a, b) => b.daysOverdue - a.daysOverdue);
});

const pendingRequests = computed(
  () =>
    requests.value.filter((r) => isActionable(r.status))
      .length,
);

/*  USER TRACKING (MY REQUESTS)  */
const myRequests = computed(() => {
  const key = (user.value?.username || user.value?.name || "").toLowerCase();
  if (!key) return [];
  return requests.value.filter(
    (r) => (r.requester || "").toLowerCase() === key,
  );
});

const myPendingCount = computed(
  () =>
    myRequests.value.filter((r) => isInFlight(r.status))
      .length,
);

const myApprovedCount = computed(
  () =>
    myRequests.value.filter(
      (r) => (r.status || "").toLowerCase() === "approved",
    ).length,
);

const myRejectedCount = computed(
  () =>
    myRequests.value.filter(
      (r) => (r.status || "").toLowerCase() === "rejected",
    ).length,
);

/*  KPI CARDS  */
// Ordered by what needs attention first. Each card says what the number
// counts, and clickable cards open the list behind the number.
const overdueBorrowers = computed(
  () => new Set(overdueLines.value.map((l) => l.requester)).size,
);

const kpiCards = computed(() => {
  if (isAdmin.value) {
    return [
      {
        key: "pending",
        label: "Pending Requests",
        value: pendingRequests.value,
        sub: pendingRequests.value ? "waiting for a decision" : "nothing waiting",
        icon: "bi-list-check",
        tone: pendingRequests.value ? "text-warning" : "text-success",
        onClick: () => (showPendingRequestsModal.value = true),
      },
      {
        key: "overdue",
        label: "Overdue Returns",
        value: overdueLines.value.length,
        sub: overdueLines.value.length
          ? `from ${overdueBorrowers.value} borrower${overdueBorrowers.value === 1 ? "" : "s"}`
          : "all returns on time",
        icon: "bi-clock-history",
        tone: overdueLines.value.length ? "text-danger" : "text-success",
        onClick: () => (overdueModal.value = true),
      },
      {
        key: "low",
        label: "Low Stock Items",
        value: lowStockCount.value,
        sub: outOfStockCount.value
          ? `${outOfStockCount.value} out of stock`
          : lowStockCount.value
            ? "at or below minimum"
            : "stock levels are fine",
        icon: "bi-exclamation-triangle",
        tone: criticalStock.value.length
          ? "text-danger"
          : lowStockCount.value
            ? "text-warning"
            : "text-success",
        onClick: openLowStockModal,
      },
      {
        key: "stock",
        label: "Units in Stock",
        value: totalItems.value,
        sub: `across ${items.value.length} item types`,
        icon: "bi-box-seam",
        tone: "text-primary",
        onClick: null,
      },
    ];
  }

  return [
    {
      key: "my-pending",
      label: "My Pending Requests",
      value: myPendingCount.value,
      sub: "still being reviewed",
      icon: "bi-hourglass-split",
      tone: myPendingCount.value ? "text-warning" : "text-muted",
      onClick: null,
    },
    {
      key: "my-approved",
      label: "My Approved Requests",
      value: myApprovedCount.value,
      sub: "approved so far",
      icon: "bi-check-circle",
      tone: "text-success",
      onClick: null,
    },
    {
      key: "my-rejected",
      label: "My Rejected Requests",
      value: myRejectedCount.value,
      sub: "not approved",
      icon: "bi-x-circle",
      tone: myRejectedCount.value ? "text-danger" : "text-muted",
      onClick: null,
    },
    {
      key: "my-overdue",
      label: "My Overdue Returns",
      value: overdueLines.value.length,
      sub: overdueLines.value.length ? "please return them" : "nothing overdue",
      icon: "bi-clock-history",
      tone: overdueLines.value.length ? "text-danger" : "text-success",
      onClick: () => (overdueModal.value = true),
    },
  ];
});

/*  LISTS (CARDS)  */
const lowStockItems = computed(() =>
  items.value.filter((i) => LOW_LEVELS.includes(i.stockLevel)).slice(0, 20),
);

// A request can hold items, uniforms, or both. Uniform-only requests (the
// cashier's) have no items, so these helpers cover both lists.
function uniformLabel(u) {
  const name = u.uniformName || "Uniform";
  return u.size ? `${name} (${u.size})` : name;
}
function requestTitle(r) {
  const names = [
    ...(r.items || []).map((i) => i.itemName),
    ...(r.uniforms || []).map(uniformLabel),
  ].filter(Boolean);
  return names.length ? names.join(", ") : "—";
}
function requestCategories(r) {
  const cats = (r.items || []).map((i) => i.category);
  if ((r.uniforms || []).length) cats.push("Uniforms");
  return [...new Set(cats.filter(Boolean))].join(", ") || "—";
}

const recentRequestsList = computed(() => {
  const base = isAdmin.value ? requests.value : myRequests.value;
  return base
    .filter((r) => (isAdmin.value ? isActionable(r.status) : isInFlight(r.status)))
    .slice(-5)
    .reverse();
});

const recentLogsList = computed(() =>
  (logs.value || []).slice(0, 5), // newest first
);

/*  CHART DATA  */
const statusCounts = computed(() => {
  const map = {};
  for (const i of items.value) {
    const s = i.status || "Unknown";
    map[s] = (map[s] || 0) + 1;
  }
  return map;
});

const categoryCounts = computed(() => {
  const map = {};
  for (const i of items.value) {
    const cat = i.category || "Uncategorized";
    map[cat] = (map[cat] || 0) + (Number(i.qty) || 0);
  }
  return map;
});

/*  CHARTS  */
const statusCanvas = ref(null);
const categoryCanvas = ref(null);

let statusChart = null;
let categoryChart = null;

function destroyCharts() {
  if (statusChart) {
    statusChart.destroy();
    statusChart = null;
  }
  if (categoryChart) {
    categoryChart.destroy();
    categoryChart = null;
  }
}

// Colours that mean something: green = fine, amber = running low, red = out.
const STATUS_COLORS = {
  Available: "#198754",
  "Low Stock": "#ffc107",
  "Out of Stock": "#dc3545",
  Borrowed: "#0d6efd",
  Unknown: "#6c757d",
};
const FALLBACK_COLORS = ["#0d6efd", "#6f42c1", "#20c997", "#fd7e14", "#d63384"];

function renderCharts() {
  // Follow the page's light/dark text colour so labels stay readable.
  const textColor = getComputedStyle(document.body).color;
  Chart.defaults.color = textColor;

  // ITEMS BY STATUS (doughnut: a handful of parts of one whole)
  if (statusCanvas.value) {
    const data = statusCounts.value;
    if (statusChart) statusChart.destroy();

    const names = Object.keys(data);

    statusChart = new Chart(statusCanvas.value, {
      type: "doughnut",
      data: {
        labels: names.map((n) => `${n} (${data[n]})`),
        datasets: [
          {
            data: Object.values(data),
            backgroundColor: names.map(
              (n, i) =>
                STATUS_COLORS[n] || FALLBACK_COLORS[i % FALLBACK_COLORS.length],
            ),
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: "62%",
        plugins: {
          legend: {
            position: window.innerWidth < 768 ? "bottom" : "right",
            labels: { boxWidth: 12, padding: 14, usePointStyle: true },
          },
        },
      },
    });
  }

  // UNITS BY CATEGORY (horizontal bars: comparing amounts, not shares)
  if (categoryCanvas.value) {
    const entries = Object.entries(categoryCounts.value).sort(
      (a, b) => b[1] - a[1],
    );
    if (categoryChart) categoryChart.destroy();

    categoryChart = new Chart(categoryCanvas.value, {
      type: "bar",
      data: {
        labels: entries.map(([name]) => name),
        datasets: [
          {
            label: "Units",
            data: entries.map(([, qty]) => qty),
            backgroundColor: entries.map(
              (_, i) => FALLBACK_COLORS[i % FALLBACK_COLORS.length],
            ),
            borderRadius: 6,
            maxBarThickness: 34,
          },
        ],
      },
      options: {
        indexAxis: "y",
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: {
            beginAtZero: true,
            ticks: { precision: 0 },
            grid: { color: "rgba(128, 128, 128, 0.18)" },
          },
          y: { grid: { display: false } },
        },
      },
    });
  }
}

watch([items, requests], () => {
  renderCharts();
});

onBeforeUnmount(() => {
  destroyCharts();
  if (clockTimer) clearInterval(clockTimer);
  if (resyncTimer) clearInterval(resyncTimer);
});

/*  CALENDAR  */
function toISODate(dateLike) {
  if (!dateLike) return null;

  if (/^\d{4}-\d{2}-\d{2}/.test(dateLike)) {
    return dateLike.slice(0, 10);
  }

  const d = new Date(dateLike);
  if (Number.isNaN(d.getTime())) return null;

  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

const calendarEvents = computed(() => {
  const source = isAdmin.value ? requests.value : myRequests.value;

  const map = {};

  for (const r of source) {
    const iso = toISODate(r.request_date);
    if (!iso) continue;

    if (!map[iso]) {
      map[iso] = {
        pending: [],
        approved: [],
        rejected: [],
        returned: [],
      };
    }

    const status = (r.status || "").toLowerCase();

if (isInFlight(status)) {
  map[iso].pending.push(r);
} else if (status === "approved") {
  map[iso].approved.push(r);
} else if (status === "rejected") {
  map[iso].rejected.push(r);
} else if (status === "returned") {
  map[iso].returned.push(r);
}
  }

  return Object.entries(map).map(([date, data]) => ({
    start: date,
    allDay: true,
    display: "block",
    title: "",
    backgroundColor: "transparent",
    borderColor: "transparent",

    extendedProps: {
      pending: data.pending,
      approved: data.approved,
      rejected: data.rejected,
      returned: data.returned,
    },
  }));
});

function openDay(iso) {
  const ev = calendarEvents.value.find((e) => e.start === iso);
  if (!ev) return;

  const p = ev.extendedProps;
  selectedDayRequests.value = [
    ...(p.pending || []),
    ...(p.approved || []),
    ...(p.rejected || []),
    ...(p.returned || []),
  ];
  selectedDay.value = iso;
  showDayModal.value = true;
}

const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, interactionPlugin],
  initialView: "dayGridMonth",
  height: "auto",
  contentHeight: "auto",
  headerToolbar: {
    left: "prev,next today",
    center: "title",
    right: "dayGridMonth",
  },

  events: calendarEvents.value,

  fixedWeekCount: false,
  dayMaxEvents: 2,

  // Click the badges or anywhere in the day to see that day's requests.
  eventClick(info) {
    openDay(info.event.startStr);
  },
  dateClick(info) {
    openDay(info.dateStr);
  },

eventContent(arg) {
  const p = arg.event.extendedProps;

  const pending = p.pending?.length || 0;
  const approved = p.approved?.length || 0;
  const rejected = p.rejected?.length || 0;
  const returned = p.returned?.length || 0;

  const container = document.createElement("div");

  const tip = [
    pending ? `${pending} in review` : "",
    approved ? `${approved} approved` : "",
    rejected ? `${rejected} rejected` : "",
    returned ? `${returned} returned` : "",
  ]
    .filter(Boolean)
    .join(", ");

  container.title = tip;
  container.innerHTML = `
    <div style="display:flex; gap:4px; flex-wrap:wrap; font-size:12px; cursor:pointer;">
      ${pending ? `<span class="badge bg-warning text-dark">${pending}</span>` : ""}
      ${approved ? `<span class="badge bg-success">${approved}</span>` : ""}
      ${rejected ? `<span class="badge bg-danger">${rejected}</span>` : ""}
      ${returned ? `<span class="badge bg-info text-dark">${returned}</span>` : ""}
    </div>
  `;

  return { domNodes: [container] };
},
}));
</script>

<template>
  <div>
    <!-- HEADER: title, compact clock, refresh -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h3 class="mb-0">Dashboard</h3>
        <div class="text-muted small">Last updated {{ lastUpdated || "—" }}</div>
      </div>

      <div class="d-flex align-items-center gap-2">
        <div
          class="clock-pill d-flex align-items-center gap-2 border rounded-pill px-3 py-1 bg-body-tertiary"
          :title="clockTitle"
        >
          <span class="clock-dot" :class="clockStatus"></span>
          <span class="fw-semibold clock-time">{{ clockTime }}</span>
          <span class="text-muted small d-none d-sm-inline">{{ clockDate }}</span>
        </div>

        <button
          class="btn btn-outline-secondary btn-sm"
          title="Refresh"
          aria-label="Refresh dashboard"
          @click="refresh"
        >
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>
    </div>

    <!-- URGENT STOCK (admin, only when something is at Critical or out) -->
    <div v-if="urgentVisible" class="card shadow-sm border-danger mb-3 urgent-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
          <div>
            <h6 class="text-danger mb-1">
              <i class="bi bi-exclamation-octagon-fill me-1"></i>
              Urgent: {{ criticalStock.length }}
              item{{ criticalStock.length === 1 ? "" : "s" }} at Critical stock
            </h6>
            <div class="text-muted small">
              <template v-if="urgentWithoutRequest">
                {{ urgentWithoutRequest }} still
                {{ urgentWithoutRequest === 1 ? "has" : "have" }} no restock request.
              </template>
              <template v-else>A restock request is open for every one of them.</template>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-danger btn-sm" @click="scrollToRestock">
              Go to restocking
            </button>
            <button class="btn btn-outline-secondary btn-sm" @click="openLowStockModal">
              All low stock
            </button>
            <button
              type="button"
              class="btn-close ms-1 align-self-center"
              aria-label="Close"
              title="Close"
              @click="dismissUrgent"
            ></button>
          </div>
        </div>

        <ul class="list-group list-group-flush">
          <li
            v-for="r in urgentItems"
            :key="r.iditems"
            class="list-group-item d-flex justify-content-between align-items-center gap-2 px-0"
          >
            <div class="min-w-0">
              <div class="fw-semibold text-truncate">{{ r.item_name }}</div>
              <div class="small text-muted">{{ r.category }}</div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-shrink-0">
              <span v-if="r.quantity <= 0" class="badge bg-dark">Out of stock</span>
              <span v-else class="badge bg-danger">{{ r.quantity }} left</span>
              <span
                class="badge"
                :class="r.active_request_id ? 'bg-success' : 'bg-warning text-dark'"
              >
                {{ r.active_request_id ? "Restock requested" : "No request yet" }}
              </span>
            </div>
          </li>
        </ul>

        <div v-if="criticalStock.length > urgentItems.length" class="small text-muted mt-2">
          and {{ criticalStock.length - urgentItems.length }} more
        </div>
      </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-3">
      <div v-for="c in kpiCards" :key="c.key" class="col-6 col-lg-3">
        <div
          class="card shadow-sm h-100 kpi-card"
          :class="{ 'kpi-click': c.onClick }"
          :role="c.onClick ? 'button' : undefined"
          :tabindex="c.onClick ? 0 : undefined"
          @click="c.onClick && c.onClick()"
          @keyup.enter="c.onClick && c.onClick()"
        >
          <div class="card-body d-flex justify-content-between align-items-center gap-2">
            <div>
              <div class="text-muted small">{{ c.label }}</div>
              <div class="fs-2 fw-bold lh-1 my-1" :class="c.tone">{{ c.value }}</div>
              <div class="text-muted small">{{ c.sub }}</div>
            </div>
            <i class="bi fs-1 opacity-75" :class="[c.icon, c.tone]"></i>
          </div>
        </div>
      </div>
    </div>

    <!-- CHARTS (admin) -->
    <div v-if="isAdmin" class="row g-3 mb-3">
      <div class="col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-1">Items by Status</h6>
            <div class="text-muted small mb-2">Number of item types in each status</div>
            <div class="chart-wrap">
              <canvas ref="statusCanvas"></canvas>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-1">Units by Category</h6>
            <div class="text-muted small mb-2">Total units currently in stock</div>
            <div class="chart-wrap">
              <canvas ref="categoryCanvas"></canvas>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MAIN ROW (admin): calendar + action panels -->
    <div v-if="isAdmin" class="row g-3 mb-3">
      <div class="col-lg-8 col-md-12">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
              <h6 class="mb-0">Requests Calendar</h6>
              <small class="text-muted">Click a day to see its requests</small>
            </div>

            <!-- Legend: what each badge on a day is -->
            <div class="calendar-legend d-flex flex-wrap align-items-center mb-1 small">
              <span class="d-inline-flex align-items-center gap-1">
                <span class="legend-dot bg-warning"></span> In review
              </span>
              <span class="d-inline-flex align-items-center gap-1">
                <span class="legend-dot bg-success"></span> Approved
              </span>
              <span class="d-inline-flex align-items-center gap-1">
                <span class="legend-dot bg-danger"></span> Rejected
              </span>
              <span class="d-inline-flex align-items-center gap-1">
                <span class="legend-dot bg-info"></span> Returned
              </span>
            </div>
            <div class="text-muted small mb-3">
              Each badge is the number of requests made that day with that status.
            </div>

            <FullCalendar :options="calendarOptions" />
          </div>
        </div>
      </div>

      <!-- Restock panels + Recent Activity Logs (stacked in the narrow column) -->
      <div class="col-lg-4 col-md-12">
        <ReturnReportsPanel v-if="isAdmin" />
        <div id="restock-panel">
          <LowStockRestockPanel v-if="isAdmin" />
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="mb-3">Recent Activity Logs</h6>
            <ul class="list-group list-group-flush">
              <li v-if="recentLogsList.length === 0" class="list-group-item text-muted">
                No activity logs yet
              </li>
              <li v-for="l in recentLogsList" :key="l.id" class="list-group-item">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="fw-semibold">
                      {{ l.action }} — {{ l.item }}
                      <span class="text-muted">× {{ l.quantity }}</span>
                    </div>
                    <div class="text-muted small">
                      {{ l.performedBy }} ({{ l.role }}) • {{ l.date }}
                      {{ l.time }}
                    </div>
                  </div>
                </div>
              </li>
            </ul>
            <div class="mt-3">
              <button class="btn btn-sm btn-outline-secondary" @click="router.push('/logs')">
                View All Logs
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MY PENDING REQUESTS (non-admin) -->
    <div v-if="!isAdmin" class="card p-3 shadow-sm">
      <h6 class="mb-3">Pending Requests</h6>

      <ul class="list-group list-group-flush">
        <li v-if="recentRequestsList.length === 0" class="list-group-item text-center text-muted">
          No pending requests
        </li>

        <li v-for="r in recentRequestsList" :key="r.id"
          class="list-group-item d-flex justify-content-between align-items-start">
          <div>
            <strong>{{ requestTitle(r) }}</strong>
            <br />
            <small class="text-muted">{{ requestCategories(r) }} •
              {{ r.request_date }}</small>
          </div>

          <span class="badge flex-shrink-0" :class="statusBadgeClass(r.status)">
            {{ r.status }}
          </span>
        </li>
      </ul>
    </div>
  </div>
  <!-- DAY REQUEST MODAL -->
  <div v-if="showDayModal" class="modal fade show" style="display: block; background: rgba(0, 0, 0, 0.4)">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Requests on {{ selectedDay }}</h5>

          <button class="btn-close" @click="showDayModal = false"></button>
        </div>

        <div class="modal-body modal-scroll">
          <ul class="list-group">
            <li v-for="r in selectedDayRequests" :key="r.id" class="list-group-item d-flex justify-content-between align-items-start gap-3">
              <div>
                <strong>
                  {{ requestTitle(r) }}
                </strong>

                <ul class="small mb-1 mt-1">
                  <li v-for="item in r.items" :key="item.itemId">
                    {{ item.itemName }} × {{ item.qty }}
                  </li>
                  <li v-for="(u, ui) in r.uniforms || []" :key="'u' + ui">
                    {{ uniformLabel(u) }} × {{ u.quantity }}
                  </li>
                </ul>

                <small class="text-muted">
                  {{ r.requester }}
                </small>
              </div>

              <span class="badge flex-shrink-0" :class="statusBadgeClass(r.status)">
                {{ r.status }}
              </span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- LOW STOCK MODAL -->
  <div v-if="lowStockModal" class="modal-backdrop-custom">
    <div class="modal-custom">
      <!-- Header -->
      <div class="modal-header">
        <h5 class="mb-0">Low Stock Items</h5>
        <button class="btn-close" @click="lowStockModal = false"></button>
      </div>

      <!-- Body -->
      <div class="modal-body modal-scroll">
        <ul class="list-group">
          <!-- Item rows -->
          <li v-for="item in lowStockItems" :key="item.id"
            class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <strong>{{ item.name }}</strong><br />
              <small class="text-muted">{{ item.category }}</small>
            </div>
            <span class="d-flex align-items-center gap-2">
              <StockBadge :level="item.stockLevel" />
              <span class="badge bg-secondary rounded-pill">{{ item.qty }}</span>
            </span>
          </li>

          <!-- Empty state -->
          <li v-if="!lowStockItems.length" class="list-group-item text-center text-muted">
            No low stock items 🎉
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- OVERDUE RETURNS MODAL -->
  <div v-if="overdueModal" class="modal-backdrop-custom">
    <div class="modal-custom">
      <div class="modal-header">
        <h5 class="mb-0">Overdue Returns</h5>
        <button class="btn-close" @click="overdueModal = false"></button>
      </div>

      <div class="modal-body modal-scroll">
        <ul class="list-group">
          <li
            v-for="line in overdueLines"
            :key="line.key"
            class="list-group-item d-flex justify-content-between align-items-start"
          >
            <div>
              <strong>{{ line.itemName }}</strong> × {{ line.borrowedQty }}<br />
              <small class="text-muted">
                {{ line.requester }} · Request #{{ line.requestId }}
              </small>
            </div>
            <DueBadge
              :due-date="line.dueDate"
              :overdue="true"
              :days-overdue="line.daysOverdue"
            />
          </li>

          <li
            v-if="!overdueLines.length"
            class="list-group-item text-center text-muted"
          >
            Nothing is overdue 🎉
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- PENDING REQUESTS MODAL FOR ADMIN -->
  <div v-if="showPendingRequestsModal" class="modal-backdrop-custom">
    <div class="modal-custom">
      <div class="modal-header">
        <h5 class="mb-0">Pending Requests</h5>
        <button class="btn-close" @click="showPendingRequestsModal = false"></button>
      </div>

      <div class="modal-body modal-scroll">
        <ul class="list-group">
          <li v-if="recentRequestsList.length === 0" class="list-group-item text-center text-muted">
            No pending requests
          </li>

          <li v-for="r in recentRequestsList" :key="r.id"
            class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <strong>{{ requestTitle(r) }}</strong><br />
              <small class="text-muted">
                {{ requestCategories(r) }} •
                {{ r.request_date }}</small>
            </div>
            <span class="badge flex-shrink-0" :class="statusBadgeClass(r.status)">
              {{ r.status }}
            </span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* keeps cards from causing sideways scroll */
* {
  max-width: 100%;
}

.chart-wrap {
  position: relative;
  width: 100%;
  max-width: 520px;
  margin: 0 auto;
  height: 320px;
}

@media (max-width: 767.98px) {
  .chart-wrap {
    height: 260px;
    max-width: 100%;
  }
}

/* Calendar legend: allow wrapping on small screens */
.calendar-legend {
  gap: 12px;
}

.calendar-legend span {
  white-space: normal;
}

.calendar-container {
  max-height: 420px;
  overflow-y: auto;
}

/* Compact clock */
.clock-pill {
  line-height: 1.2;
}
.clock-time {
  font-variant-numeric: tabular-nums;
}
.clock-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #6c757d;
  flex-shrink: 0;
}
.clock-dot.synced {
  background: #198754;
}
.clock-dot.syncing {
  background: #ffc107;
}
.clock-dot.offline {
  background: #dc3545;
}

/* KPI cards */
.kpi-click {
  cursor: pointer;
  transition: transform 0.12s ease, box-shadow 0.12s ease;
}
.kpi-click:hover,
.kpi-click:focus-visible {
  transform: translateY(-2px);
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
  outline: none;
}

.urgent-card {
  border-width: 1px 1px 1px 5px;
}
.min-w-0 {
  min-width: 0;
}

/* Legend colour dots (no numbers) */
.legend-dot {
  display: inline-block;
  width: 12px;
  height: 12px;
  border-radius: 50%;
}

/* The event itself is only a carrier for the count badges: no blue bar */
:deep(.fc .fc-event) {
  background: transparent !important;
  border-color: transparent !important;
  box-shadow: none !important;
}
:deep(.fc .fc-event:hover::after),
:deep(.fc .fc-event:focus::after) {
  display: none;
}

/* FullCalendar tweaks (it renders its own DOM, so :deep is needed) */
:deep(.fc .fc-toolbar-title) {
  font-size: 1.05rem;
}
:deep(.fc .fc-button) {
  padding: 0.25rem 0.6rem;
  font-size: 0.85rem;
}
:deep(.fc .fc-daygrid-day) {
  cursor: pointer;
}
:deep(.fc .fc-daygrid-day-frame) {
  min-height: 64px;
}
:deep(.fc .fc-more-link) {
  font-size: 0.75rem;
}

.modal-scroll {
  max-height: 400px;
  overflow-y: auto;
}

.table-scroll {
  max-height: 400px;
  overflow-y: auto;
}

.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
  box-shadow: 0 1px 0 #dee2e6;
}

.card-body ul {
  max-height: 400px;
  overflow-y: auto;
}

/* the dashboard's list pop-ups are wider than the default */
.modal-custom {
  max-width: 700px;
}
</style>

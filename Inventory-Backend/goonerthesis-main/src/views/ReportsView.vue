<script setup>
import { ref, computed, onMounted } from "vue";
import { listInventory } from "../services/inventory";
import { getRequests } from "../services/requests";
import { getLogs } from "../services/logs";
import { requireAdmin } from "../services/session";

const DAYS_WEEKLY = 7;
const DAYS_MONTHLY = 30;

const rangeDays = ref(DAYS_WEEKLY);

const items = ref([]);
const requests = ref([]);
const logs = ref([]);

onMounted(async () => {
  requireAdmin();
  await refresh();
});

async function refresh() {
  items.value = await listInventory();
  requests.value = await getRequests();
  logs.value = await getLogs();
}

function setRange(days) {
  rangeDays.value = days;
}

const reportRangeLabel = computed(() => {
  const end = new Date();
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(end.getDate() - (rangeDays.value - 1));
  const fmt = (d) =>
    d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
  return `${fmt(start)} \u2013 ${fmt(end)}`;
});

/* ===== DATE HELPERS ===== */
function parseDateLike(d) {
  if (!d) return null;
  const dt = new Date(d);
  return isNaN(dt) ? null : dt;
}

function withinDays(dateObj, days) {
  if (!dateObj) return true;
  const end = new Date();
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(end.getDate() - (days - 1));
  return dateObj >= start && dateObj <= end;
}

/* ===== INVENTORY SUMMARY ===== */
const totalQty = computed(() =>
  items.value.reduce((a, i) => a + (Number(i.qty) || 0), 0),
);

const lowStockCount = computed(
  () => items.value.filter((i) => (Number(i.qty) || 0) <= 5).length,
);

const damagedItems = computed(() =>
  items.value
    .filter((i) => Number(i.damaged_quantity) > 0)
    .sort((a, b) => Number(b.damaged_quantity) - Number(a.damaged_quantity)),
);

const totalDamagedQty = computed(() =>
  damagedItems.value.reduce((a, i) => a + (Number(i.damaged_quantity) || 0), 0),
);

const maintenanceItems = computed(() =>
  items.value
    .filter((i) => Number(i.maintenance_quantity) > 0)
    .sort((a, b) => Number(b.maintenance_quantity) - Number(a.maintenance_quantity)),
);

const totalMaintenanceQty = computed(() =>
  maintenanceItems.value.reduce((a, i) => a + (Number(i.maintenance_quantity) || 0), 0),
);

const inventoryList = computed(() =>
  items.value
    .slice()
    .sort((a, b) => (Number(a.qty) || 0) - (Number(b.qty) || 0)),
);

const approvedRequests = computed(
  () =>
    recentRequests.value.filter(
      (r) => (r.status || "").toLowerCase() === "approved",
    ).length,
);

const rejectedRequests = computed(
  () =>
    recentRequests.value.filter(
      (r) => (r.status || "").toLowerCase() === "rejected",
    ).length,
);

/* ===== REQUEST SUMMARY ===== */
const recentRequests = computed(() =>
  requests.value.filter((r) =>
    withinDays(parseDateLike(r.request_date), rangeDays.value),
  ),
);

const pendingRequests = computed(
  () =>
    recentRequests.value.filter(
      (r) => (r.status || "").toLowerCase() === "pending",
    ).length,
);

/* ===== LOG SUMMARY ===== */
const recentLogs = computed(() =>
  logs.value.filter((l) => {
    const d =
      parseDateLike(l.timestamp) ||
      parseDateLike(`${l.date || ""} ${l.time || ""}`) ||
      parseDateLike(l.date);
    return withinDays(d, rangeDays.value);
  }),
);

const requestsById = computed(() => {
  const map = new Map();
  requests.value.forEach((r) => map.set(r.id, r));
  return map;
});

function describeRequestItems(req) {
  if (!req) return "-";
  const parts = [
    ...req.items.map((i) => `${i.itemName} x${i.qty}`),
    ...req.uniforms.map(
      (u) => `${u.uniformName} (${u.department}) x${u.quantity}`,
    ),
  ];
  return parts.length ? parts.join(", ") : "-";
}

// Turns the raw free-text log description into a proper row: what
// action happened, which item(s) it involved, and who the original
// requester was (not just whoever clicked the button).
const activityRows = computed(() =>
  recentLogs.value.map((l) => {
    const desc = l.action || "";
    let actionLabel = desc || "Activity";
    let itemText = "-";
    let requesterText = l.performedBy || "Unknown";
    let m;

    if ((m = desc.match(/^(Created|Approved|Rejected) Request #(\d+)$/))) {
      actionLabel = `${m[1]} Request #${m[2]}`;
      const req = requestsById.value.get(Number(m[2]));
      itemText = describeRequestItems(req);
      // Show the ORIGINAL requester, not whoever approved/rejected it.
      requesterText = req?.requester || l.performedBy;
    } else if ((m = desc.match(/^Returned (\d+) (.+)$/))) {
      actionLabel = "Returned Item";
      itemText = `${m[2]} x${m[1]}`;
      // Not traceable back to the original requester from this log alone.
      requesterText = l.performedBy;
    } else {
      // Item / User / Department / Uniform Type / Uniform Variant
      // management actions -- these are admin actions, not requests,
      // so "requester" doesn't apply; show who performed it instead.
      requesterText = l.performedBy;
    }

    return {
      id: l.id,
      action: actionLabel,
      item: itemText,
      requester: requesterText,
      date: l.timestamp,
    };
  }),
);

function formatLogDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

/* ===== PRINT BUTTON ===== */
function printReport() {
  if (typeof window === "undefined") return;

  const originalTitle = document.title;
  document.title = "CIMS Report";

  const restore = () => {
    document.title = originalTitle;
    window.removeEventListener("afterprint", restore);
  };

  window.addEventListener("afterprint", restore);

  requestAnimationFrame(() => window.print());
}
</script>

<template>
  <div>
    <div
      class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"
    >
      <div>
        <h3 class="mb-0">Reports</h3>
        <div class="text-muted small">Showing: last {{ rangeDays }} days</div>
      </div>

      <div class="d-flex gap-2 no-print">
        <button
          class="btn"
          :class="rangeDays === DAYS_WEEKLY ? 'btn-primary' : 'btn-outline-primary'"
          @click="setRange(DAYS_WEEKLY)"
        >
          Weekly Report
        </button>

        <button
          class="btn"
          :class="rangeDays === DAYS_MONTHLY ? 'btn-success' : 'btn-outline-success'"
          @click="setRange(DAYS_MONTHLY)"
        >
          Monthly Report
        </button>

        <button type="button" class="btn btn-dark" @click="printReport">
          Print Report
        </button>
      </div>
    </div>

    <!-- PRINT-ONLY HEADER -->
    <div class="d-none d-print-block print-header mb-3">
      <h2 class="mb-0">Celtech Inventory Management System</h2>
      <div class="text-muted">
        Inventory &amp; Activity Report
        &mdash; {{ rangeDays === DAYS_WEEKLY ? "Weekly" : "Monthly" }}
        ({{ reportRangeLabel }})
      </div>
      <div class="text-muted small">
        Generated on {{ formatLogDate(new Date()) }}
      </div>
      <hr />
    </div>

    <!-- INVENTORY -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Inventory Summary</h5>
            <div class="text-muted small">Remaining inventory items</div>
          </div>

          <span class="badge bg-primary"> {{ rangeDays }} Days </span>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Total Quantity</div>
              <div class="fs-3 fw-bold">
                {{ totalQty }}
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Low Stock Items</div>
              <div class="fs-3 fw-bold text-warning">
                {{ lowStockCount }}
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Categories</div>
              <div class="fs-3 fw-bold">
                {{
                  new Set(items.map((i) => i.category || "Uncategorized")).size
                }}
              </div>
            </div>
          </div>
        </div>

        <div class="table-responsive table-scroll">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Remaining Qty</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="item in inventoryList" :key="item.id">
                <td>{{ item.name }}</td>

                <td>{{ item.category }}</td>

                <td>
                  {{ item.qty }}
                </td>

                <td>
                  <span
                    class="badge"
                    :class="{
                      'bg-success': item.status === 'Available',
                      'bg-warning text-dark': item.status === 'Low Stock',
                      'bg-danger': item.status === 'Out of Stock',
                      'bg-primary': item.status === 'Borrowed',
                    }"
                  >
                    {{ item.status }}
                  </span>
                </td>
              </tr>

              <tr v-if="inventoryList.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  No inventory data
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MAINTENANCE ITEMS -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Maintenance Items</h5>
            <div class="text-muted small">
              Items held for repair after failing return inspection
            </div>
          </div>
          <span class="badge bg-warning text-dark">{{ totalMaintenanceQty }} total</span>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Under Maintenance</th>
                <th>Still Available</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in maintenanceItems" :key="item.id">
                <td>{{ item.name }}</td>
                <td>{{ item.category }}</td>
                <td>
                  <span class="badge bg-warning text-dark">{{ item.maintenance_quantity }}</span>
                </td>
                <td>{{ item.qty }}</td>
              </tr>
              <tr v-if="maintenanceItems.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  No items currently under maintenance.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- DAMAGED ITEMS -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Damaged Items</h5>
            <div class="text-muted small">
              Items written off after failing return inspection
            </div>
          </div>
          <span class="badge bg-danger">{{ totalDamagedQty }} total</span>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Damaged Qty</th>
                <th>Still Available</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in damagedItems" :key="item.id">
                <td>{{ item.name }}</td>
                <td>{{ item.category }}</td>
                <td>
                  <span class="badge bg-danger">{{ item.damaged_quantity }}</span>
                </td>
                <td>{{ item.qty }}</td>
              </tr>
              <tr v-if="damagedItems.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  No damaged items on record.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- REQUESTS -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Request Summary</h5>
            <div class="text-muted small">Request activity overview</div>
          </div>

          <span class="badge bg-success"> {{ rangeDays }} Days </span>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Total Requests</div>
              <div class="fs-3 fw-bold">
                {{ recentRequests.length }}
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Pending Requests</div>
              <div class="fs-3 fw-bold text-warning">
                {{ pendingRequests }}
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Approved Requests</div>
              <div class="fs-3 fw-bold text-success">
                {{ approvedRequests }}
              </div>
            </div>
          </div>

          <div class="col-md-4">
            <div class="border rounded p-3 stat-box">
              <div class="text-muted small">Rejected Requests</div>
              <div class="fs-3 fw-bold text-danger">
                {{ rejectedRequests }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- LOGS -->
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h5 class="mb-0">Activity Logs</h5>
            <div class="text-muted small">
              Recent admin and inventory activities
            </div>
          </div>

          <span class="badge bg-dark"> {{ rangeDays }} Days </span>
        </div>

        <div class="mb-3">
          <strong>Total Logs:</strong>
          {{ recentLogs.length }}
        </div>

        <div class="table-responsive logs-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Action</th>
                <th>Item(s)</th>
                <th>Requester</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in activityRows" :key="row.id">
                <td>{{ row.action }}</td>
                <td>{{ row.item }}</td>
                <td>{{ row.requester }}</td>
                <td>{{ formatLogDate(row.date) }}</td>
              </tr>
              <tr v-if="activityRows.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  No logs found in selected range.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Hides the Buttons */
@media print {
  .no-print {
    display: none !important;
  }

  /* Previously every .card had break-inside:avoid, which forced the
     ENTIRE card to jump to the next page if it didn't fully fit in the
     remaining space on the current page -- even short cards like Request
     Summary. That's what was leaving huge blank gaps and burning whole
     pages. Cards now flow naturally instead. */
  .card {
    box-shadow: none !important;
    border: none !important;
    margin-bottom: 6px !important;
  }

  .card-body {
    padding: 6px 0 !important;
  }

  /* Keep a section's heading glued to at least its first row of content,
     without forcing the whole (possibly long) table to stay together. */
  .card-body > div:first-child {
    break-after: avoid;
  }

  /* IMPORTANT FIX: these previously had max-height + overflow-y:auto,
     which does nothing useful when printing (print can't scroll) -- it
     just silently CLIPPED any rows past ~420px tall, so long inventory
     or activity lists were getting cut off in the printed report. */
  .table-scroll,
  .logs-scroll {
    max-height: none !important;
    overflow: visible !important;
  }

  /* Repeat table headers on every printed page instead of only showing
     column names on the first page a table appears on. */
  table thead {
    display: table-header-group;
  }

  tr {
    break-inside: avoid;
  }

  /* Compact the stat boxes (Total Quantity, Low Stock, etc.) so they
     take a fraction of the space they do on screen, instead of each
     eating a large chunk of a page. */
  .stat-box {
    padding: 4px 8px !important;
    border: 1px solid #ccc !important;
  }

  .stat-box .fs-3 {
    font-size: 1.1rem !important;
  }

  .stat-box .small {
    font-size: 0.7rem !important;
  }

  .print-header h2 {
    font-size: 1.3rem;
  }
}
@page {
  size: A4;
  margin: 12mm;
}
/* Inventory table */
.table-scroll {
  max-height: 420px;
  overflow-y: auto;
}

.table-scroll thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f8f9fa;
}

/* Activity logs */
.logs-scroll {
  max-height: 420px;
  overflow-y: auto;
  padding-right: 4px;
}

.logs-scroll::-webkit-scrollbar,
.table-scroll::-webkit-scrollbar {
  width: 8px;
}

.logs-scroll::-webkit-scrollbar-thumb,
.table-scroll::-webkit-scrollbar-thumb {
  background: #c4c4c4;
  border-radius: 10px;
}

.logs-scroll::-webkit-scrollbar-thumb:hover,
.table-scroll::-webkit-scrollbar-thumb:hover {
  background: #9e9e9e;
}
</style>

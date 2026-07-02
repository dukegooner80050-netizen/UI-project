<script setup>
import { ref, computed, onMounted } from "vue"
import { listInventory } from "../services/inventory"
import { listRequests } from "../services/requests"
import { getLogs } from "../services/storage"
import { requireAdmin } from "../services/session"

const DAYS_WEEKLY = 7
const DAYS_MONTHLY = 30

const rangeDays = ref(DAYS_WEEKLY)

const items = ref([])
const requests = ref([])
const logs = ref([])

onMounted(() => {
  requireAdmin()
  refresh()
})

function refresh() {
  items.value = listInventory()
  requests.value = listRequests()
  logs.value = getLogs()
}

function setRange(days) {
  rangeDays.value = days
}

/* ===== DATE HELPERS ===== */
function parseDateLike(d) {
  if (!d) return null
  const dt = new Date(d)
  return isNaN(dt) ? null : dt
}

function withinDays(dateObj, days) {
  if (!dateObj) return true
  const end = new Date()
  const start = new Date()
  start.setHours(0, 0, 0, 0)
  start.setDate(end.getDate() - (days - 1))
  return dateObj >= start && dateObj <= end
}

/* ===== INVENTORY SUMMARY ===== */
const totalQty = computed(() =>
  items.value.reduce((a, i) => a + (Number(i.qty) || 0), 0)
)

const lowStockCount = computed(() =>
  items.value.filter(i => (Number(i.qty) || 0) <= 5).length
)

const inventoryList = computed(() =>
  items.value
    .slice()
    .sort((a, b) => (Number(a.qty) || 0) - (Number(b.qty) || 0))
)

const approvedRequests = computed(() =>
  recentRequests.value.filter(
    r => (r.status || "").toLowerCase() === "approved"
  ).length
)

const rejectedRequests = computed(() =>
  recentRequests.value.filter(
    r => (r.status || "").toLowerCase() === "rejected"
  ).length
)

/* ===== REQUEST SUMMARY ===== */
const recentRequests = computed(() =>
  requests.value.filter(r =>
    withinDays(parseDateLike(r.date), rangeDays.value)
  )
)

const pendingRequests = computed(() =>
  recentRequests.value.filter(
    r => (r.status || "").toLowerCase() === "pending"
  ).length
)

/* ===== LOG SUMMARY ===== */
const recentLogs = computed(() =>
  logs.value.filter(l => {
    const d =
      parseDateLike(l.timestamp) ||
      parseDateLike(`${l.date || ""} ${l.time || ""}`) ||
      parseDateLike(l.date)
    return withinDays(d, rangeDays.value)
  })
)

const logsByAction = computed(() => {
  const map = {}
  recentLogs.value.forEach(l => {
    const a = l.action || "ACTION"
    map[a] = (map[a] || 0) + (Number(l.quantity) || 1)
  })
  return map
})

/* ===== PRINT BUTTON ===== */
function printReport() {
  if (typeof window === "undefined") return

  const originalTitle = document.title
  document.title = "DEV. PHASE" // <=== TITLE "CIMS Report"

  const restore = () => {
    document.title = originalTitle
    window.removeEventListener("afterprint", restore)
  }

  window.addEventListener("afterprint", restore)

  requestAnimationFrame(() => window.print())
}
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h3 class="mb-0">Reports</h3>
        <div class="text-muted small">
          Showing: last {{ rangeDays }} days
        </div>
      </div>

      <div class="d-flex gap-2 no-print">
        <button
          class="btn"
          :class="rangeDays === DAYS_WEEKLY ? 'btn-primary' : 'btn-primary'"
          @click="setRange(DAYS_WEEKLY)"
        >
          Weekly Report
        </button>

        <button
          class="btn"
          :class="rangeDays === DAYS_MONTHLY ? 'btn-success' : 'btn-success'"
          @click="setRange(DAYS_MONTHLY)"
        >
          Monthly Report
        </button>

        <button type="button" class="btn btn-dark" @click="printReport">
          Print Report
        </button>
      </div>
    </div>

<!-- INVENTORY -->
<div class="card shadow-sm mb-3">
  <div class="card-body">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="mb-0">Inventory Summary</h5>
        <div class="text-muted small">
          Remaining inventory items
        </div>
      </div>

      <span class="badge bg-primary">
        {{ rangeDays }} Days
      </span>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Total Quantity</div>
          <div class="fs-3 fw-bold">
            {{ totalQty }}
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Low Stock Items</div>
          <div class="fs-3 fw-bold text-warning">
            {{ lowStockCount }}
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Categories</div>
          <div class="fs-3 fw-bold">
            {{ new Set(items.map(i => i.category || 'Uncategorized')).size }}
          </div>
        </div>
      </div>
    </div>

    <div class="table-responsive">
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
                  'bg-primary': item.status === 'Borrowed'
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

<!-- REQUESTS -->
<div class="card shadow-sm mb-3">
  <div class="card-body">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h5 class="mb-0">Request Summary</h5>
        <div class="text-muted small">
          Request activity overview
        </div>
      </div>

      <span class="badge bg-success">
        {{ rangeDays }} Days
      </span>
    </div>

    <div class="row g-3">

      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Total Requests</div>
          <div class="fs-3 fw-bold">
            {{ recentRequests.length }}
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Pending Requests</div>
          <div class="fs-3 fw-bold text-warning">
            {{ pendingRequests }}
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3">
          <div class="text-muted small">Approved Requests</div>
          <div class="fs-3 fw-bold text-success">
            {{ approvedRequests }}
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="border rounded p-3">
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

      <span class="badge bg-dark">
        {{ rangeDays }} Days
      </span>
    </div>

    <div class="mb-3">
      <strong>Total Logs:</strong>
      {{ recentLogs.length }}
    </div>

    <div
      v-for="(count, action) in logsByAction"
      :key="action"
      class="border rounded p-3 mb-2"
    >
      <div class="fw-semibold">
        {{ action }}
      </div>

      <div class="text-muted small">
        Total Actions: {{ count }}
      </div>
    </div>

    <div
      v-if="Object.keys(logsByAction).length === 0"
      class="text-muted"
    >
      No logs found in selected range.
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

  .card {
    break-inside: avoid;
    page-break-inside: avoid;
    margin-bottom: 12px !important;
  }

  .card-body {
    padding: 10px !important;
  }
}
@page {
  size: A4;
  margin: 12mm;
}
</style>
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

      <div class="d-flex gap-2">
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

        <button class="btn btn-dark" @click="window.print()">
          Print Report
        </button>
      </div>
    </div>

    <!-- INVENTORY -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>Inventory Summary</h5>
        <div><strong>Total Qty:</strong> {{ totalQty }}</div>
        <div><strong>Low Stock Items:</strong> {{ lowStockCount }}</div>
        <div class="small text-muted mt-2">
          Categories tracked:
          {{ new Set(items.map(i => i.category || 'Uncategorized')).size }}
        </div>
      </div>
    </div>

    <!-- REQUESTS -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h5>Request Summary</h5>
        <div>
          <strong>{{ rangeDays }}-day Requests:</strong>
          {{ recentRequests.length }}
        </div>
        <div>
          <strong>Pending (in range):</strong>
          {{ pendingRequests }}
        </div>
      </div>
    </div>

    <!-- LOGS -->
    <div class="card shadow-sm">
      <div class="card-body">
        <h5>Activity Logs</h5>
        <div>
          <strong>{{ rangeDays }}-day Log Entries:</strong>
          {{ recentLogs.length }}
        </div>

        <div class="mt-2">
          <div
            v-for="(count, action) in logsByAction"
            :key="action"
            class="small text-muted"
          >
            • {{ action }}: {{ count }}
          </div>

          <div
            v-if="Object.keys(logsByAction).length === 0"
            class="small text-muted"
          >
            No logs in range
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

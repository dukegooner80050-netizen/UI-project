<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from "vue"
import { useRouter } from "vue-router"
import { listInventory } from "../services/inventory"
import { listRequests } from "../services/requests"
import { getLogs } from "../services/storage"
import { getCurrentUser } from "../services/storage"

// Chart.js
import Chart from "chart.js/auto"

import FullCalendar from "@fullcalendar/vue3"
import dayGridPlugin from "@fullcalendar/daygrid"
import interactionPlugin from "@fullcalendar/interaction"

/* ===================== ROUTER ===================== */
const router = useRouter()

/* ===================== DASHBOARD FEATURES FOR REG. USER ===================== */
const user = computed(() => getCurrentUser())
const isAdmin = computed(() => (user.value?.role || "").toLowerCase() === "admin")

/* ===================== STATE ===================== */
const items = ref([])
const requests = ref([])
const logs = ref([])
const lastUpdated = ref("")
/* ===================== CLOCK ===================== */
const now = ref(new Date())
let clockTimer = null

function tick() {
  now.value = new Date()
}
/* ===================== LOAD ===================== */
function loadAll() {
  items.value = listInventory()
  requests.value = listRequests()
  logs.value = getLogs()
  lastUpdated.value = new Date().toLocaleString()
}

function refresh() {
  loadAll()
  renderCharts()
}

onMounted(() => {
  loadAll()
  renderCharts()
  tick()
  clockTimer = setInterval(tick, 1000)
})

/* ===================== SUMMARY ===================== */
const totalItems = computed(() =>
  items.value.reduce((a, i) => a + (Number(i.qty) || 0), 0)
)

const lowStockCount = computed(() =>
  items.value.filter(i => (Number(i.qty) || 0) <= 5).length
)

const pendingRequests = computed(() =>
  requests.value.filter(r => (r.status || "").toLowerCase() === "pending").length
)

/* ===================== USER TRACKING (MY REQUESTS) ===================== */
const myRequests = computed(() => {
  const key = (user.value?.username || user.value?.name || "").toLowerCase()
  if (!key) return []
  return requests.value.filter(r => (r.requester || "").toLowerCase() === key)
})

const myPendingCount = computed(() =>
  myRequests.value.filter(r => (r.status || "").toLowerCase() === "pending").length
)

const myApprovedCount = computed(() =>
  myRequests.value.filter(r => (r.status || "").toLowerCase() === "approved").length
)

const myRejectedCount = computed(() =>
  myRequests.value.filter(r => (r.status || "").toLowerCase() === "rejected").length
)

/* ===================== LISTS (CARDS) ===================== */
const lowStockItems = computed(() =>
  items.value
    .filter(i => (Number(i.qty) || 0) <= 5)
    .slice(0, 20)
)

const recentRequestsList = computed(() => {
  const base = isAdmin.value ? requests.value : myRequests.value
  return [...base].slice(-5).reverse()
})

const recentLogsList = computed(() => [...logs.value].slice(0, 5)) // logs.js unshift() so newest first

/* ===================== CHART DATA ===================== */
const statusCounts = computed(() => {
  const map = {}
  for (const i of items.value) {
    const s = i.status || "Unknown"
    map[s] = (map[s] || 0) + 1
  }
  return map
})

const categoryCounts = computed(() => {
  const map = {}
  for (const i of items.value) {
    const cat = i.category || "Uncategorized"
    map[cat] = (map[cat] || 0) + (Number(i.qty) || 0)
  }
  return map
})

/* ===================== CHARTS ===================== */
const statusCanvas = ref(null)
const categoryCanvas = ref(null)

let statusChart = null
let categoryChart = null

function destroyCharts() {
  if (statusChart) {
    statusChart.destroy()
    statusChart = null
  }
  if (categoryChart) {
    categoryChart.destroy()
    categoryChart = null
  }
}

function renderCharts() {
  // STATUS PIE
  if (statusCanvas.value) {
    const data = statusCounts.value
    if (statusChart) statusChart.destroy()

    statusChart = new Chart(statusCanvas.value, {
      type: "pie",
      data: {
        labels: Object.keys(data),
        datasets: [{ data: Object.values(data) }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: "right", labels: { boxWidth: 14, padding: 16, usePointStyle: true } } }
      }
    })
  }

  // CATEGORY DOUGHNUT
  if (categoryCanvas.value) {
    const data = categoryCounts.value
    if (categoryChart) categoryChart.destroy()

    categoryChart = new Chart(categoryCanvas.value, {
      type: "doughnut",
      data: {
        labels: Object.keys(data),
        datasets: [{ data: Object.values(data) }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: "right", labels: { boxWidth: 14, padding: 16, usePointStyle: true } } }
      }
    })
  }
}

watch([items, requests], () => {
  renderCharts()
})

onBeforeUnmount(() => {
  destroyCharts()
  if (clockTimer) clearInterval(clockTimer)
})

/* ===================== CALENDAR ===================== */
function toISODate(dateLike) {
  if (!dateLike) return null
  if (/^\d{4}-\d{2}-\d{2}/.test(dateLike)) return dateLike.slice(0, 10)

  const d = new Date(dateLike)
  if (Number.isNaN(d.getTime())) return null
  return d.toISOString().slice(0, 10)
}

const calendarEvents = computed(() => {
  const source = isAdmin.value ? requests.value : myRequests.value

  return source
    .map(r => {
      const iso = toISODate(r.date)
      if (!iso) return null

      const status = (r.status || "").toLowerCase()
      const title = `${r.itemName || "Request"} x${r.qty || 0}`

      return {
        id: String(r.id),
        title,
        start: iso,
        allDay: true,
        extendedProps: {
          status: r.status || "Unknown",
          category: r.category || "",
          purpose: r.purpose || "",
          requester: r.requester || ""
        },
        color:
          status === "pending" ? "#f59e0b" :
          status === "approved" ? "#16a34a" :
          status === "rejected" ? "#dc2626" :
          "#6b7280"
      }
    })
    .filter(Boolean)
})


const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, interactionPlugin],
  initialView: "dayGridMonth",
  height: 360,
  headerToolbar: {
    left: "prev,next today",
    center: "title",
    right: "dayGridMonth"
  },
  events: calendarEvents.value,
  eventClick(info) {
    const p = info.event.extendedProps || {}
    alert(
      `Request Details\n\n` +
      `Item: ${info.event.title}\n` +
      `Status: ${p.status}\n` +
      `Category: ${p.category}\n` +
      `Requester: ${p.requester}\n` +
      `Purpose: ${p.purpose}`
    )
  }
}))
</script>

<template>
  <div>
    <!-- Header + last updated + refresh -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
      <div>
        <h3 class="mb-1">Dashboard</h3>
        <div class="text-muted small">
          Last updated: {{ lastUpdated || "—" }}
        </div>
      </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="row mb-4">
      <!-- Admin cards -->
      <template v-if="isAdmin">
        <div class="col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="text-muted">Total Inventory Quantity</h6>
            <h3 class="mb-0">{{ totalItems }}</h3>
          </div>
        </div>
        </div>

        <div class="col-md-4 mb-3 mb-md-0">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="text-muted">Low Stock Items</h6>
              <h3 class="mb-0 text-danger">{{ lowStockCount }}</h3>
            </div>
          </div>
      </div>

      <div class="col-md-4">
        <div class="card shadow-sm">
          <div class="card-body">
              <h6 class="text-muted">Pending Requests</h6>
              <h3 class="mb-0 text-warning">{{ pendingRequests }}</h3>
            </div>
          </div>
        </div>
      </template>

      <!-- User cards -->
      <template v-else>
        <div class="col-md-4 mb-3 mb-md-0">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="text-muted">My Pending Requests</h6>
              <h3 class="mb-0 text-warning">{{ myPendingCount }}</h3>
          </div>
        </div>
        </div>

        <div class="col-md-4 mb-3 mb-md-0">
          <div class="card shadow-sm">
            <div class="card-body">
              <h6 class="text-muted">My Approved Requests</h6>
              <h3 class="mb-0 text-success">{{ myApprovedCount }}</h3>
            </div>
          </div>
      </div>

      <div class="col-md-4">
        <div class="card shadow-sm">
          <div class="card-body">
              <h6 class="text-muted">My Rejected Requests</h6>
              <h3 class="mb-0 text-danger">{{ myRejectedCount }}</h3>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- CHARTS + CALENDAR -->
    <div class="row">
      <!-- LEFT: 2 CHARTS (admin only) -->
      <div class="col-md-6 mb-4" v-if="isAdmin">
        <div class="card shadow-sm mb-4">
          <div class="card-body">
            <h6 class="mb-3">Inventory Status Distribution</h6>
            <div class="d-flex align-items-center" style="height: 320px;">
              <div style="width: 320px; height: 320px;">
                <canvas ref="statusCanvas"></canvas>
              </div>
              <div class="flex-grow-1"></div>
            </div>
          </div>
        </div>
        
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="mb-3">Inventory by Category</h6>
            <div class="d-flex align-items-center" style="height: 320px;">
              <div style="width: 370px; height: 370px;">
                <canvas ref="categoryCanvas"></canvas>
              </div>
              <div class="flex-grow-1"></div>
            </div>
          </div>
        </div>
        </div>

      <!-- RIGHT: CALENDAR (admin + user) -->
      <div :class="isAdmin ? 'col-md-6 mb-4' : 'col-12 mb-4'">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="mb-0">Requests Calendar</h6>
              <div class="small d-flex flex-wrap gap-3 align-items-center">
  <span class="d-flex align-items-center gap-1">
    <span
      class="rounded-circle"
      style="width:10px;height:10px;background:#f59e0b;display:inline-block"
    ></span>
    <span class="text-muted">Pending — awaiting approval</span>
  </span>

  <span class="d-flex align-items-center gap-1">
    <span
      class="rounded-circle"
      style="width:10px;height:10px;background:#16a34a;display:inline-block"
    ></span>
    <span class="text-muted">Approved — inventory updated</span>
  </span>

  <span class="d-flex align-items-center gap-1">
    <span
      class="rounded-circle"
      style="width:10px;height:10px;background:#dc2626;display:inline-block"
    ></span>
    <span class="text-muted">Rejected — request denied</span>
  </span>
</div>
          </div>
            <FullCalendar :options="calendarOptions" />
            <!-- CLOCK (under calendar) -->
              <div class="mt-3 p-3 rounded bg-light border">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                  <div>
                    <div class="text-muted small">Current time</div>
                      <div class="fw-bold" style="font-size: 2rem; line-height: 1;">
                      {{ now.toLocaleTimeString() }}
                      </div>
                    <div class="text-muted">
                   {{ now.toLocaleDateString() }}
                  </div>
                </div>
              <div class="text-muted small text-end">
            <div>Timezone: Local</div>
          <div>Updates every second</div>
    </div>
  </div>
</div>
          </div>
        </div>
      </div>
    </div>

    <!-- LOW STOCK + RECENT REQUESTS -->
    <div class="row mt-2">
      <!-- Low stock (admin only) -->
      <div class="col-md-6 mb-4" v-if="isAdmin">
        <div class="card p-3 shadow-sm h-100">
          <h6 class="text-danger mb-3">⚠ Low Stock Alerts</h6>

          <ul class="list-group list-group-flush">
            <li v-if="lowStockItems.length === 0" class="list-group-item text-muted">
              No low stock items
            </li>

            <li
              v-for="i in lowStockItems"
              :key="i.id"
              class="list-group-item d-flex justify-content-between align-items-center"
            >
              <div>
                <div class="fw-semibold">{{ i.name }}</div>
                <small class="text-muted">{{ i.category || "Uncategorized" }}</small>
              </div>

              <span class="badge bg-danger rounded-pill">
                {{ Number(i.qty) || 0 }}
              </span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Recent requests (everyone) -->
      <div :class="isAdmin ? 'col-md-6 mb-4' : 'col-12 mb-4'">
        <div class="card p-3 shadow-sm h-100">
          <h6 class="mb-3">🕘 Recent Requests</h6>

          <ul class="list-group list-group-flush">
            <li v-if="recentRequestsList.length === 0" class="list-group-item text-muted">
              No recent requests
            </li>

            <li
              v-for="r in recentRequestsList"
              :key="r.id"
              class="list-group-item"
            >
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <div class="fw-semibold">
                    {{ r.itemName }} <span class="text-muted">× {{ r.qty }}</span>
                  </div>
                  <small class="text-muted">
                    {{ r.category }} • {{ r.date || "—" }}
                  </small>
                </div>

                <span
                  class="badge"
                  :class="(r.status || '').toLowerCase() === 'pending'
                    ? 'bg-warning text-dark'
                    : (r.status || '').toLowerCase() === 'approved'
                      ? 'bg-success'
                      : (r.status || '').toLowerCase() === 'rejected'
                        ? 'bg-danger'
                      : 'bg-secondary'"
                >
                  {{ r.status || "Unknown" }}
                </span>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Recent logs (admin only) -->
    <div v-if="isAdmin" class="row">
      <div class="col-12 mb-4">
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
                      {{ l.performedBy }} ({{ l.role }}) • {{ l.date }} {{ l.time }}
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

  </div>
</template>

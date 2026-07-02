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
const selectedDayRequests = ref([])
const selectedDay = ref("")
const showDayModal = ref(false)
const lowStockModal = ref(false)
const showPendingRequestsModal = ref(false)
/* ===================== CLOCK ===================== */
const now = ref(null)                 // Date object (PH time from API)
const clockStatus = ref("syncing")    // "syncing" | "synced" | "offline"
let clockTimer = null
let resyncTimer = null

async function fetchPHTime() {
  const urls = [
    "https://worldtimeapi.org/api/timezone/Asia/Manila",
    "https://timeapi.io/api/Time/current/zone?timeZone=Asia/Manila"
  ]

  for (const url of urls) {
    try {
      const res = await fetch(url, { cache: "no-store" })
      if (!res.ok) continue

      const data = await res.json()
      const iso = data.datetime || data.dateTime
      if (!iso) continue

      now.value = new Date(iso)
      clockStatus.value = "synced"
      return true
    } catch (e) {
      // try next URL
    }
  }

  // If both APIs fail, mark offline and fall back (optional)
  clockStatus.value = "offline"
  if (!now.value) now.value = new Date()
  return false
}

function tickPH() {
  if (!now.value) return
  now.value = new Date(now.value.getTime() + 1000)
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

onMounted(async () => {
  loadAll()
  renderCharts()

  await fetchPHTime()
  clockTimer = setInterval(tickPH, 1000)

  // resync every minute to keep accurate
  resyncTimer = setInterval(fetchPHTime, 60_000)
})

/* ===================== CARD MODALS ===================== */
function openLowStockModal() {
  lowStockModal.value = true
}

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
  return base
    .filter(r => (r.status || "").toLowerCase() === "pending")
    .slice(-5)
    .reverse()
})

const recentLogsList = computed(() =>
  [...(logs.value || [])].slice(-5).reverse()
)

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
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: window.innerWidth < 768 ? "bottom" : "right",
            labels: { boxWidth: 14, padding: 14, usePointStyle: true }
          }
        }
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
        maintainAspectRatio: false,
        plugins: {
        legend: {
          position: window.innerWidth < 768 ? "bottom" : "right",
          labels: { boxWidth: 14, padding: 14, usePointStyle: true }
        }
       }
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
  if (resyncTimer) clearInterval(resyncTimer)
})

/* ===================== CALENDAR ===================== */
function toISODate(dateLike) {
  if (!dateLike) return null

  if (/^\d{4}-\d{2}-\d{2}/.test(dateLike)) {
    return dateLike.slice(0, 10)
  }

  const d = new Date(dateLike)
  if (Number.isNaN(d.getTime())) return null

  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, "0")
  const day = String(d.getDate()).padStart(2, "0")

  return `${year}-${month}-${day}`
}

const calendarEvents = computed(() => {
  const source = isAdmin.value ? requests.value : myRequests.value

  const map = {}

  for (const r of source) {
    const iso = toISODate(r.date)
    if (!iso) continue

    if (!map[iso]) {
      map[iso] = {
        pending: [],
        approved: [],
        rejected: []
      }
    }

    const status = (r.status || "").toLowerCase()

    if (status === "pending") map[iso].pending.push(r)
    else if (status === "approved") map[iso].approved.push(r)
    else if (status === "rejected") map[iso].rejected.push(r)
  }

  return Object.entries(map).map(([date, data]) => ({
    start: date,
    allDay: true,
    display: "block",
    title: "",

    extendedProps: {
      pending: data.pending,
      approved: data.approved,
      rejected: data.rejected
    }
  }))
})


const calendarOptions = computed(() => ({
  plugins: [dayGridPlugin, interactionPlugin],
  initialView: "dayGridMonth",
  height: "auto",
  contentHeight: "auto",
  headerToolbar: {
    left: "prev,next today",
    center: "title",
    right: "dayGridMonth"
  },

  events: calendarEvents.value,

  eventClick(info) {
    const p = info.event.extendedProps

    selectedDayRequests.value = [
      ...(p.pending || []),
      ...(p.approved || []),
      ...(p.rejected || [])
    ]

    selectedDay.value = info.event.startStr
    showDayModal.value = true
  },

  eventContent(arg) {
    const p = arg.event.extendedProps

    const pending = p.pending?.length || 0
    const approved = p.approved?.length || 0
    const rejected = p.rejected?.length || 0

    const container = document.createElement("div")

    container.innerHTML = `
      <div style="display:flex;gap:4px;flex-wrap:wrap;font-size:12px">
        ${pending ? `<span class="badge bg-warning text-dark">${pending}</span>` : ""}
        ${approved ? `<span class="badge bg-success">${approved}</span>` : ""}
        ${rejected ? `<span class="badge bg-danger">${rejected}</span>` : ""}
      </div>
    `

    return { domNodes: [container] }
  }
}))

</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
      <div>
        <h3 class="mb-1">Dashboard</h3>
        <div class="text-muted small">
          Last updated: {{ lastUpdated || "—" }}
        </div>
      </div>
    </div>

<!-- CLOCK CARD  -->
<div class="row mb-4">
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="text-muted small">Current time</div>
            <div class="fw-bold" style="font-size: 2.25rem; line-height: 1;">
              {{ now ? now.toLocaleTimeString("en-PH") : "—" }}
            </div>
            <div class="text-muted">
              {{ now ? now.toLocaleDateString("en-PH") : "—" }}
            </div>
          </div>

          <div class="text-muted small text-end">
            <div>
              <span v-if="typeof clockStatus !== 'undefined'">
                ({{ clockStatus === "synced" ? "Online" : clockStatus === "syncing" ? "Syncing" : "Offline" }})
              </span>
            </div>
          </div>
        </div>
      </div>
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
  <div class="card shadow-sm cursor-pointer" @click="openLowStockModal">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <h6 class="text-muted mb-1">Low Stock Items</h6>
        <h3 class="mb-0 text-danger">{{ lowStockItems.length }}</h3>
      </div>
      <i class="bi bi-exclamation-triangle fs-2 text-danger"></i>
    </div>
  </div>
</div>

<div class="col-md-4">
  <div 
    class="card shadow-sm cursor-pointer"
    @click="showPendingRequestsModal = true"
  >
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <h6 class="text-muted mb-1">Pending Requests</h6>
        <h3 class="mb-0 text-warning">{{ pendingRequests }}</h3>
      </div>
      <i class="bi bi-list-check fs-2 text-warning"></i>
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

<!-- CHARTS (SIDE BY SIDE) -->
<div v-if="isAdmin" class="row">
  <!-- Chart 1 -->
  <div class="col-md-6 mb-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h6 class="mb-3">Inventory Status Distribution</h6>
        <div class="chart-wrap">
          <canvas ref="statusCanvas"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Chart 2 -->
  <div class="col-md-6 mb-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h6 class="mb-3">Inventory by Category</h6>
        <div class="chart-wrap">
          <canvas ref="categoryCanvas"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- CALENDAR + RECENT ACTIVITY -->
<div v-if="isAdmin" class="row">
  <!-- Calendar (Left or Right) -->
  <div class="col-lg-8 col-md-12 mb-3 mb-lg-0">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h6 class="mb-3">Requests Calendar</h6>
        <FullCalendar :options="calendarOptions" />
      </div>
    </div>
  </div>

  <!-- Recent Activity Logs -->
  <div class="col-lg-4 col-md-12">
    <div class="card shadow-sm h-100">
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


    <!-- LOW STOCK + RECENT REQUESTS -->
    <div class="row mt-2">

<!-- PENDING REQUESTS CARD -->
<template v-if="!isAdmin">
  <div class="col-12 mb-3">
    <div class="card p-3 shadow-sm h-100">
      <h6 class="mb-3">Pending Requests</h6>

      <ul class="list-group list-group-flush">
        <li v-if="recentRequestsList.length === 0" class="list-group-item text-center text-muted">
          No pending requests
        </li>

        <li 
          v-for="r in recentRequestsList" 
          :key="r.id" 
          class="list-group-item d-flex justify-content-between align-items-start"
        >
          <div>
            <strong>{{ r.itemName }}</strong>
            <br>
            <small class="text-muted">{{ r.category }} • {{ r.date || "—" }}</small>
          </div>

          <span
            class="badge"
            :class="(r.status || '').toLowerCase() === 'pending'
              ? 'bg-warning text-dark'
              : (r.status || '').toLowerCase() === 'approved'
              ? 'bg-success'
              : 'bg-danger'"
          >
            {{ r.status }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>
    </div>
  </div>
  <!-- DAY REQUEST MODAL -->
<div
  v-if="showDayModal"
  class="modal fade show"
  style="display:block;background:rgba(0,0,0,0.4)"
>
  <div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">
          Requests on {{ selectedDay }}
        </h5>

        <button class="btn-close" @click="showDayModal=false"></button>
      </div>

      <div class="modal-body modal-scroll">

        <ul class="list-group">

          <li
            v-for="r in selectedDayRequests"
            :key="r.id"
            class="list-group-item d-flex justify-content-between"
          >
            <div>
              <strong>{{ r.itemName }}</strong> × {{ r.qty }}
              <br>
              <small class="text-muted">
                {{ r.requester }} • {{ r.category }}
              </small>
            </div>

            <span
              class="badge"
              :class="(r.status || '').toLowerCase() === 'pending'
                ? 'bg-warning text-dark'
                : (r.status || '').toLowerCase() === 'approved'
                ? 'bg-success'
                : 'bg-danger'"
            >
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
        <li 
          v-for="item in lowStockItems" 
          :key="item.id" 
          class="list-group-item d-flex justify-content-between align-items-start"
        >
          <div>
            <strong>{{ item.name }}</strong><br>
            <small class="text-muted">{{ item.category }}</small>
          </div>
          <span class="badge bg-danger rounded-pill">{{ item.qty }}</span>
        </li>

        <!-- Empty state -->
        <li v-if="!lowStockItems.length" class="list-group-item text-center text-muted">
          No low stock items 🎉
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
      <button class="btn-close" @click="showPendingRequestsModal=false"></button>
    </div>

    <div class="modal-body modal-scroll">
      <ul class="list-group">
        <li v-if="recentRequestsList.length === 0" class="list-group-item text-center text-muted">
          No pending requests
        </li>

        <li v-for="r in recentRequestsList" :key="r.id" class="list-group-item d-flex justify-content-between align-items-start">
          <div>
            <strong>{{ r.itemName }}</strong><br>
            <small class="text-muted">{{ r.category }} • {{ r.date || "—" }}</small>
          </div>
          <span class="badge" 
            :class="(r.status || '').toLowerCase() === 'pending'
              ? 'bg-warning text-dark'
              : (r.status || '').toLowerCase() === 'approved'
              ? 'bg-success'
              : 'bg-danger'"
          >
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
* { max-width: 100%; }

.chart-wrap{
  position: relative;
  width: 100%;
  max-width: 520px;
  margin: 0 auto;
  height: 320px;
}

@media (max-width: 767.98px){
  .chart-wrap{
    height: 260px;
    max-width: 100%;
  }
}

/* Calendar legend: allow wrapping on small screens */
.calendar-legend{
  gap: 12px;
}
.calendar-legend span{
  white-space: normal;
}
.calendar-container{
  max-height: 420px;
  overflow-y: auto;
}

.modal-scroll{
  max-height: 400px;
  overflow-y: auto;
}
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.35);
  display: grid;
  place-items: center;
  z-index: 2000;
  padding: 16px;
}

.modal-custom {
  background: #fff;
  border-radius: 12px;
  max-width: 700px;
  width: 100%;
  max-height: 80%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0,0,0,0.25);
}

.modal-header,
.modal-footer {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid #dee2e6;
}

.modal-body {
  padding: 16px;
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

</style>

<script setup>
import { ref, computed, onMounted, watch, onBeforeUnmount } from "vue"
import { listInventory } from "../services/inventory"
import { listRequests } from "../services/requests"
import { getLogs } from "../services/storage"

// Chart.js
import Chart from "chart.js/auto"

// FullCalendar (Vue 3)
import FullCalendar from "@fullcalendar/vue3"
import dayGridPlugin from "@fullcalendar/daygrid"
import interactionPlugin from "@fullcalendar/interaction"

/* ===================== STATE ===================== */
const items = ref([])
const requests = ref([])
const logs = ref([])

/* ===================== LOAD ===================== */
function loadAll() {
  items.value = listInventory()
  requests.value = listRequests()
  logs.value = getLogs()
}

onMounted(() => {
  loadAll()
  renderCharts()
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

/* ===================== LISTS (CARDS) ===================== */
const lowStockItems = computed(() =>
  items.value
    .filter(i => (Number(i.qty) || 0) <= 5)
    .slice(0, 20)
)

const recentRequestsList = computed(() => [...requests.value].slice(-5).reverse())

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
  // Status Pie
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
        plugins: { legend: { position: "bottom" } }
      }
    })
  }

  // Category Doughnut
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
        plugins: { legend: { position: "bottom" } }
      }
    })
  }
}

// If data changes while dashboard is open, redraw charts
watch([items, requests], () => {
  renderCharts()
})

onBeforeUnmount(() => {
  destroyCharts()
})

/* ===================== CALENDAR ===================== */
// Convert "MM/DD/YYYY" (from toLocaleDateString) into "YYYY-MM-DD" reliably
function toISODate(dateLike) {
  if (!dateLike) return null
  // If it's already ISO-ish, keep it
  if (/^\d{4}-\d{2}-\d{2}/.test(dateLike)) return dateLike.slice(0, 10)

  const d = new Date(dateLike)
  if (Number.isNaN(d.getTime())) return null
  return d.toISOString().slice(0, 10)
}

const calendarEvents = computed(() => {
  return requests.value
    .map(r => {
      const iso = toISODate(r.date)
      if (!iso) return null

      const status = (r.status || "").toLowerCase()
      const title = `${r.itemName || "Request"} x${r.qty || 0}`

      // Keep styling simple; FullCalendar will still show it fine
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
        // optional: different colors by status
        color:
          status === "pending" ? "#f59e0b" :
          status === "approved" ? "#16a34a" :
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
    <h3 class="mb-4">Dashboard</h3>

    <!-- SUMMARY CARDS -->
    <div class="row mb-4">
      <div class="col-md-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="text-muted">Total Inventory Quantity</h6>
            <h3 class="mb-0">{{ totalItems }}</h3>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="text-muted">Low Stock Items</h6>
            <h3 class="mb-0">{{ lowStockCount }}</h3>
          </div>
        </div>
      </div>

      <div class="col-md-4">
        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="text-muted">Pending Requests</h6>
            <h3 class="mb-0">{{ pendingRequests }}</h3>
          </div>
        </div>
      </div>
    </div>

    <!-- CHARTS + CALENDAR -->
    <div class="row">
      <!-- LEFT: 2 CHARTS -->
      <div class="col-md-6 mb-4">
        <div class="card shadow-sm mb-4">
          <div class="card-body">
            <h6 class="mb-3">Inventory Status Distribution</h6>
            <div style="height: 320px;">
              <canvas ref="statusCanvas"></canvas>
            </div>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-body">
            <h6 class="mb-3">Inventory by Category</h6>
            <div style="height: 320px;">
              <canvas ref="categoryCanvas"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: CALENDAR -->
      <div class="col-md-6 mb-4">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-3">Requests Calendar</h6>
            <FullCalendar :options="calendarOptions" />
          </div>
        </div>
      </div>
    </div>

    <!-- LOW STOCK + RECENT REQUESTS -->
    <div class="row mt-2">
      <div class="col-md-6 mb-4">
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

      <div class="col-md-6 mb-4">
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
  </div>
</template>

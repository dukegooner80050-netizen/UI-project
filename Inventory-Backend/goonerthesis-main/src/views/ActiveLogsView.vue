<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from "vue"
import { getLogsPage, getLogUsers } from "../services/logs"
import { requireUser } from "../services/session"
import AlertMessage from "../components/AlertMessage.vue"

// The server filters, sorts and pages the logs, so this page only ever
// holds one page of rows no matter how large the table grows.

const logs = ref([])
const users = ref([])
const loading = ref(false)

const search = ref("")
const dateFrom = ref("")
const dateTo = ref("")
const userId = ref("")

const sortKey = ref("time") // time | action | user | role
const sortDir = ref("desc")

const page = ref(1)
const perPage = ref(25)
const meta = ref({ current_page: 1, last_page: 1, per_page: 25, total: 0 })

const showAlert = ref(false)
const alertType = ref("success")
const alertMessage = ref("")

function notify(message, type = "success") {
  alertMessage.value = message
  alertType.value = type
  showAlert.value = true
}

async function load() {
  loading.value = true
  try {
    const res = await getLogsPage({
      page: page.value,
      per_page: perPage.value,
      sort: sortKey.value,
      dir: sortDir.value,
      search: search.value.trim() || undefined,
      user_id: userId.value || undefined,
      from: dateFrom.value || undefined,
      to: dateTo.value || undefined,
    })
    logs.value = res.data
    meta.value = res.meta
    // Filters can shrink the result below the page we were on.
    if (page.value > res.meta.last_page && res.meta.last_page >= 1) {
      page.value = res.meta.last_page
    }
  } catch (e) {
    notify("Failed to load logs.", "danger")
  } finally {
    loading.value = false
  }
}

async function refresh() {
  await load()
  notify("Logs refreshed successfully.")
}

onMounted(async () => {
  requireUser()
  try {
    users.value = await getLogUsers()
  } catch (e) {
    console.error("Failed to load log users:", e)
  }
  await load()
})

// Typing in the search box waits a moment so we don't ask the server on
// every keystroke.
let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 350)
})
onBeforeUnmount(() => clearTimeout(searchTimer))

watch([dateFrom, dateTo, userId, perPage], () => {
  page.value = 1
  load()
})

watch(page, load)

function sortBy(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc"
  } else {
    sortKey.value = key
    sortDir.value = key === "time" ? "desc" : "asc"
  }
  page.value = 1
  load()
}

function sortMark(key) {
  if (sortKey.value !== key) return ""
  return sortDir.value === "asc" ? "▲" : "▼"
}

function clearFilters() {
  search.value = ""
  dateFrom.value = ""
  dateTo.value = ""
  userId.value = ""
}

const firstRow = computed(() =>
  meta.value.total === 0 ? 0 : (meta.value.current_page - 1) * meta.value.per_page + 1,
)
const lastRow = computed(() =>
  Math.min(meta.value.current_page * meta.value.per_page, meta.value.total),
)

// 1 … 4 5 [6] 7 8 … 20
const pageNumbers = computed(() => {
  const last = meta.value.last_page
  const cur = meta.value.current_page
  const nums = new Set([1, last, cur - 2, cur - 1, cur, cur + 1, cur + 2])
  const list = [...nums].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
  const out = []
  list.forEach((n, i) => {
    if (i > 0 && n - list[i - 1] > 1) out.push("…")
    out.push(n)
  })
  return out
})

function goTo(n) {
  if (typeof n !== "number") return
  if (n < 1 || n > meta.value.last_page || n === page.value) return
  page.value = n
}

function badgeClass(action) {
  const a = (action || "").toLowerCase()
  if (a.includes("borrow")) return "bg-primary"
  if (a.includes("return")) return "bg-secondary"
  if (a.includes("approve")) return "bg-success"
  if (a.includes("reject")) return "bg-danger"
  if (a.includes("restock")) return "bg-success"
  if (a.includes("release")) return "bg-info text-dark"
  return "bg-dark"
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :message="alertMessage" :type="alertType" />
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h3 class="mb-0">Active Logs</h3>
        <div class="text-muted small">Monitoring Database</div>
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-secondary" @click="refresh">Refresh</button>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">

        <div class="row g-2 mb-3 align-items-end">
          <div class="col-md-4">
            <label class="form-label small text-muted mb-1">Search</label>
            <input v-model="search" class="form-control" placeholder="Search logs (action, item, user, date...)" />
          </div>

          <div class="col-md-2">
            <label class="form-label small text-muted mb-1">From</label>
            <input v-model="dateFrom" type="date" class="form-control" />
          </div>

          <div class="col-md-2">
            <label class="form-label small text-muted mb-1">To</label>
            <input v-model="dateTo" type="date" class="form-control" />
          </div>

          <div class="col-md-2">
            <label class="form-label small text-muted mb-1">User</label>
            <select v-model="userId" class="form-select">
              <option value="">All users</option>
              <option v-for="u in users" :key="u.id" :value="u.id">
                {{ u.name }}
              </option>
            </select>
          </div>

          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-outline-secondary w-100" @click="clearFilters">
              Clear Filters
            </button>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="text-muted small">
            Showing <strong>{{ firstRow }}–{{ lastRow }}</strong> of
            <strong>{{ meta.total }}</strong>
            <span v-if="loading" class="ms-2 spinner-border spinner-border-sm"></span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <label class="small text-muted mb-0" for="logs-per-page">Rows per page</label>
            <select id="logs-per-page" name="logs-per-page" v-model.number="perPage" class="form-select form-select-sm" style="width: auto">
              <option :value="10">10</option>
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
            </select>
          </div>
        </div>

        <div class="table-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="sortable" style="min-width: 160px;" @click="sortBy('action')">
                  Action <span class="sort-mark">{{ sortMark("action") }}</span>
                </th>
                <th class="sortable" style="min-width: 160px;" @click="sortBy('user')">
                  Performed By <span class="sort-mark">{{ sortMark("user") }}</span>
                </th>
                <th class="sortable" style="width: 120px;" @click="sortBy('role')">
                  Role <span class="sort-mark">{{ sortMark("role") }}</span>
                </th>
                <th class="sortable" style="width: 130px;" @click="sortBy('time')">
                  Date <span class="sort-mark">{{ sortMark("time") }}</span>
                </th>
                <th style="width: 130px;">Time</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="l in logs" :key="l.id">
                <td>
                  <span class="badge" :class="badgeClass(l.action)">
                    {{ l.action }}
                  </span>
                </td>
                <td>{{ l.performedBy }}</td>
                <td>{{ l.role }}</td>
                <td>{{ l.date }}</td>
                <td>{{ l.time }}</td>
              </tr>

              <tr v-if="!loading && logs.length === 0">
                <td colspan="5" class="text-center text-muted py-4">
                  No logs found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <nav v-if="meta.last_page > 1" class="d-flex justify-content-center mt-3" aria-label="Log pages">
          <ul class="pagination pagination-sm mb-0 flex-wrap">
            <li class="page-item" :class="{ disabled: meta.current_page <= 1 }">
              <button class="page-link" @click="goTo(meta.current_page - 1)">Previous</button>
            </li>
            <li
              v-for="(n, i) in pageNumbers"
              :key="i"
              class="page-item"
              :class="{ active: n === meta.current_page, disabled: n === '…' }"
            >
              <button class="page-link" @click="goTo(n)">{{ n }}</button>
            </li>
            <li class="page-item" :class="{ disabled: meta.current_page >= meta.last_page }">
              <button class="page-link" @click="goTo(meta.current_page + 1)">Next</button>
            </li>
          </ul>
        </nav>

        <div class="text-muted mt-3">Monitoring Database</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.table-scroll {
  max-height: 520px;
  overflow: auto;
}

.sortable {
  cursor: pointer;
  user-select: none;
}

.sort-mark {
  font-size: 0.7rem;
}

.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
  box-shadow: 0 1px 0 #dee2e6;
}
</style>

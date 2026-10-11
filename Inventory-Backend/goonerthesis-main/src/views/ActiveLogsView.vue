<script setup>
import { ref, computed, onMounted } from "vue"
import { getLogs } from "../services/logs"
import { requireUser } from "../services/session"
import AlertMessage from "../components/AlertMessage.vue"


//ALERT STATE
const logs = ref([])
const search = ref("")
const dateFrom = ref("")
const dateTo = ref("")
const userFilter = ref("")

const showAlert = ref(false)
const alertType = ref("success")
const alertMessage = ref("")


onMounted(async () => {
  requireUser()
  await refresh()
})

function notify(message, type = "success") {
  alertMessage.value = message
  alertType.value = type
  showAlert.value = true
}

async function refresh() {
  try {
    logs.value = await getLogs()

    notify("Logs refreshed successfully.")
  } catch (e) {
    notify("Failed to refresh logs.", "danger")
  }
}

const uniqueUsers = computed(() => {
  const names = logs.value.map(l => l.performedBy).filter(Boolean)
  return [...new Set(names)].sort((a, b) => a.localeCompare(b))
})

const filteredLogs = computed(() => {
  const q = search.value.trim().toLowerCase()

  return logs.value.filter(l => {
    if (q) {
      const blob = [
        l.action,
        l.performedBy,
        l.role,
        l.date,
        l.time
      ]
        .join(" ")
        .toLowerCase()

      if (!blob.includes(q)) return false
    }

    // l.date is "YYYY-MM-DD", same format as the date inputs below,
    // so these compare as plain strings.
    if (dateFrom.value && l.date < dateFrom.value) return false
    if (dateTo.value && l.date > dateTo.value) return false

    if (userFilter.value && l.performedBy !== userFilter.value) return false

    return true
  })
})

function clearFilters() {
  search.value = ""
  dateFrom.value = ""
  dateTo.value = ""
  userFilter.value = ""
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
            <select v-model="userFilter" class="form-select">
              <option value="">All users</option>
              <option v-for="name in uniqueUsers" :key="name" :value="name">
                {{ name }}
              </option>
            </select>
          </div>

          <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-outline-secondary w-100" @click="clearFilters">
              Clear Filters
            </button>
          </div>
        </div>

        <div class="text-muted small mb-3">
          Total: <strong>{{ filteredLogs.length }}</strong>
        </div>

        <div class="table-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="min-width: 160px;">Action</th>
                <th style="min-width: 160px;">Performed By</th>
                <th style="width: 120px;">Role</th>
                <th style="width: 130px;">Date</th>
                <th style="width: 130px;">Time</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="l in filteredLogs" :key="l.id">
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

              <tr v-if="filteredLogs.length === 0">
                <td colspan="5" class="text-center text-muted py-4">
                  No logs found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

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

.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
  box-shadow: 0 1px 0 #dee2e6;
}
</style>

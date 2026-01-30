<script setup>
import { ref, computed, onMounted } from "vue"
import { listRequests, approveRequest, setRequestStatus } from "../services/requests"
import { requireAdmin } from "../services/session"

const requests = ref([])
const showAll = ref(false)

onMounted(() => {
  requireAdmin()

  refresh()
})

function refresh() {
  requests.value = listRequests()
}

const pendingRequests = computed(() =>
  requests.value.filter(r => (r.status || "").toLowerCase() === "pending")
)

const displayedRequests = computed(() =>
  showAll.value ? requests.value : pendingRequests.value
)

function approve(id) {
  try {
    approveRequest(id)
    refresh()
  } catch (e) {
    alert(String(e.message || e))
  }
}

function reject(id) {
  if (!confirm("Reject this request?")) return

  try {
    setRequestStatus(id, "Rejected")
    refresh()
  } catch (e) {
    alert(String(e.message || e))
  }
}

function statusBadgeClass(status) {
  const s = (status || "").toLowerCase()
  if (s === "pending") return "bg-warning text-dark"
  if (s === "approved") return "bg-success"
  if (s === "rejected") return "bg-danger"
  return "bg-secondary"
}
</script>

<template>
  <div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h3 class="mb-0">Pending Requests</h3>
        <div class="text-muted small">
          Pending: <strong>{{ pendingRequests.length }}</strong>
        </div>
      </div>

      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="showAll" v-model="showAll" />
        <label class="form-check-label" for="showAll">Show all requests</label>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th>
              <th>Category</th>
              <th>Type</th>
              <th style="width:90px">Qty</th>
              <th>Purpose</th>
              <th>Requester</th>
              <th style="width:120px">Status</th>
              <th style="width:220px">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="r in displayedRequests" :key="r.id">
              <td class="fw-semibold">{{ r.itemName }}</td>
              <td>{{ r.category }}</td>
              <td>{{ r.itemType }}</td>
              <td>{{ r.qty }}</td>
              <td style="max-width: 260px;">
                <div class="text-truncate" :title="r.purpose">{{ r.purpose }}</div>
              </td>
              <td>
                <div>{{ r.requester }}</div>
                <div class="text-muted small">{{ r.role }}</div>
              </td>
              <td>
                <span class="badge" :class="statusBadgeClass(r.status)">
                  {{ r.status }}
                </span>
              </td>
              <td>
                <div class="d-flex gap-2">
                  <button
                    class="btn btn-sm btn-success"
                    :disabled="(r.status || '').toLowerCase() !== 'pending'"
                    @click="approve(r.id)"
                  >
                    Approve
                  </button>

                  <button
                    class="btn btn-sm btn-outline-danger"
                    :disabled="(r.status || '').toLowerCase() !== 'pending'"
                    @click="reject(r.id)"
                  >
                    Reject
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="displayedRequests.length === 0">
              <td colspan="8" class="text-center text-muted py-4">
                {{ showAll ? "No requests found." : "No pending requests." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>

<style scoped>
.table-scroll {
  max-height: 520px;
  overflow-y: auto;
}

.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
  box-shadow: 0 1px 0 #dee2e6;
}
</style>

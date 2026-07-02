<script setup>
import { ref, computed, onMounted } from "vue";
import {
  listRequests,
  approveRequest,
  rejectRequest,
} from "../services/requests";
import { getCurrentUser } from "../services/storage";
import { requireAdmin } from "../services/session";

const requests = ref([]);
const showAll = ref(false);
const admin = getCurrentUser();
const rejectModal = ref(false);
const rejectReason = ref("");
const rejectTargetId = ref(null);

onMounted(() => {
  requireAdmin();

  refresh();
});

function refresh() {
  requests.value = listRequests();
}

const pendingRequests = computed(() =>
  requests.value.filter((r) => (r.status || "").toLowerCase() === "pending"),
);

const displayedRequests = computed(() => {
  const data = showAll.value
    ? [...requests.value]
    : [...pendingRequests.value];

  return data.sort(
    (a, b) =>
      new Date(b.createdAt).getTime() -
      new Date(a.createdAt).getTime()
  );
});

function approve(id) {
  try {
    approveRequest(id, admin?.name || admin?.username || "Admin");
    refresh();
  } catch (e) {
    alert(String(e.message || e));
  }
}

function openRejectModal(id) {
  rejectTargetId.value = id;
  rejectReason.value = "";
  rejectModal.value = true;
}

function confirmReject() {
  if (!rejectReason.value.trim()) {
    alert("Rejection reason is required.");
    return;
  }

  try {
    rejectRequest(
      rejectTargetId.value,
      rejectReason.value.trim(),
      admin?.name || admin?.username || "Admin"
    );

    rejectModal.value = false;
    rejectTargetId.value = null;
    rejectReason.value = "";
    refresh();
  } catch (e) {
    alert(String(e.message || e));
  }
}

function statusBadgeClass(status) {
  const s = (status || "").toLowerCase();
  if (s === "pending") return "bg-warning text-dark";
  if (s === "approved") return "bg-success";
  if (s === "rejected") return "bg-danger";
  return "bg-secondary";
}
</script>

<template>
  <div>
    <div
      class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"
    >
      <div>
        <h3 class="mb-0">Pending Requests</h3>
        <div class="text-muted small">
          Pending: <strong>{{ pendingRequests.length }}</strong>
        </div>
      </div>

      <div class="form-check form-switch">
        <input
          class="form-check-input"
          type="checkbox"
          id="showAll"
          v-model="showAll"
        />
        <label class="form-check-label" for="showAll">Show all requests</label>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Items</th>
              <th style="width: 90px">Qty</th>
              <th style="width: 100px">Total Qty</th>
              
              <th>Category</th>
              <th>Type</th>
              <th>Purpose</th>
              <th>Location</th>
              <th>Requester</th>
              <th style="width: 120px">Status</th>
              <th style="width: 130px">Date</th>
              <th style="width: 220px">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="r in displayedRequests" :key="r.id">
<td>
  <div v-for="(item, i) in r.items" :key="i">
    {{ item.itemName }}
  </div>
</td>
<td>
  <div v-for="(item, i) in r.items" :key="i">
    {{ item.qty }}
  </div>
</td>
              <td>
                {{
                  r.items.reduce((sum, item) => sum + Number(item.qty || 0), 0)
                }}
              </td>

<td>
  <div v-for="(item, i) in r.items" :key="i">
    {{ item.category }}
  </div>
</td>
<td>
  <div v-for="(item, i) in r.items" :key="i">
    {{ item.itemType }}
  </div>
</td>
              <td style="max-width: 260px">
                <div class="text-truncate" :title="r.purpose">
                  {{ r.purpose }}
                </div>
              </td>
              <td>{{ r.location }}</td>
              <td>
                <div>{{ r.requester }}</div>
                <div class="text-muted small">{{ r.role }}</div>
              </td>
              <td>
                <span class="badge" :class="statusBadgeClass(r.status)">
                  {{ r.status }}
                </span>
                <div
                  v-if="
                    (r.status || '').toLowerCase() === 'rejected' &&
                    r.rejectReason
                  "
                  class="text-danger small mt-1"
                >
                  Reason: {{ r.rejectReason }}
                </div>
              </td>
              <td>
  {{ r.date || "—" }}
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
                    @click="openRejectModal(r.id)"
                  >
                    Reject
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="displayedRequests.length === 0">
              <td colspan="9" class="text-center text-muted py-4">
                {{ showAll ? "No requests found." : "No pending requests." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div v-if="rejectModal" class="modal-backdrop-custom">
  <div class="modal-custom">

    <div class="modal-header">
      <h5 class="mb-0">Reject Request</h5>
      <button class="btn-close" @click="rejectModal = false"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">Reason for rejection</label>
      <textarea
        v-model="rejectReason"
        class="form-control"
        rows="4"
        placeholder="Enter reason..."
      ></textarea>
    </div>

    <div class="modal-footer">
      <button class="btn btn-outline-secondary" @click="rejectModal = false">
        Cancel
      </button>

      <button class="btn btn-danger" @click="confirmReject" :disabled="!rejectReason.trim()">
        Reject
      </button>
    </div>

  </div>
</div>
</template>

<style scoped>
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.35);
  display: grid;
  place-items: center;
  z-index: 2000;
  padding: 16px;
}

.modal-custom {
  background: white;
  width: 100%;
  max-width: 500px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0,0,0,.25);
}

.modal-header,
.modal-footer {
  padding: 12px 16px;
  border-bottom: 1px solid #dee2e6;
}

.modal-footer {
  border-top: 1px solid #dee2e6;
  border-bottom: none;
  display: flex;
  justify-content: flex-end;
}

.modal-body {
  padding: 16px;
}
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

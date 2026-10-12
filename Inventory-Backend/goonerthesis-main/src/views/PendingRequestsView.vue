<script setup>
import { ref, computed, onMounted } from "vue";
import { getRequests, approveRequest, rejectRequest,} from "../services/requests";
import { getCurrentUser } from "../services/storage";
import { requireAdmin } from "../services/session";
import AlertMessage from "../components/AlertMessage.vue";
import { getErrorMessage } from "../services/errors";
import {
  isDueEligible,
  addDaysISO,
  todayISO,
  formatDue,
} from "../services/dueDates";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

const requests = ref([]);
const showAll = ref(false);
const admin = getCurrentUser();
const userFilter = ref("");
const expandedId = ref(null);
// Due date picked per request item: { [requestItemId]: "YYYY-MM-DD" }
const dueDates = ref({});
const rejectModal = ref(false);
const rejectReason = ref("");
const rejectTargetId = ref(null);
const confirmModal = ref(false);
const confirmTitle = ref("");
const confirmMessage = ref("");
const confirmButton = ref("Confirm");
const confirmButtonClass = ref("btn-primary");
const confirmAction = ref(null);

onMounted(async () => {
  requireAdmin();

  await refresh();
});

async function refresh() {
  requests.value = await getRequests();

  // Pre-fill each pending borrowed item from its return period (if one is
  // set) so the admin only has to adjust it; never overwrite a date already
  // picked.
  requests.value.forEach((r) => {
    if ((r.status || "").toLowerCase() !== "pending") return;
    (r.items || []).forEach((item) => {
      if (
        isDueEligible(item) &&
        !dueDates.value[item.requestItemId] &&
        item.suggestedDueDays
      ) {
        dueDates.value[item.requestItemId] = addDaysISO(item.suggestedDueDays);
      }
    });
  });
}

const pendingRequests = computed(() =>
  requests.value.filter((r) => (r.status || "").toLowerCase() === "pending"),
);

const uniqueRequesters = computed(() => {
  const names = requests.value
    .map((r) => r.requester)
    .filter((name) => !!name);

  return [...new Set(names)].sort((a, b) => a.localeCompare(b));
});

const displayedRequests = computed(() => {
  let data = showAll.value
    ? [...requests.value]
    : [...pendingRequests.value];

  if (userFilter.value) {
    data = data.filter((r) => r.requester === userFilter.value);
  }

  return data.sort(
    (a, b) =>
      (toDate(b.request_date) || 0) -
      (toDate(a.request_date) || 0)
  );
});
const requestRows = computed(() => {
  return displayedRequests.value;
});

// request_date arrives as "YYYY-MM-DD HH:MM:SS"; the "T" keeps Safari happy.
function toDate(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d;
}

// Date + time. Older requests were saved with a date only (time 00:00:00),
// so for those just show the date instead of a misleading "12:00 AM".
function formatRequestDate(value) {
  const d = toDate(value);
  if (!d) return value || "—";

  const dateOnly = d.getHours() === 0 && d.getMinutes() === 0 && d.getSeconds() === 0;
  const date = d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
  if (dateOnly) return date;

  const time = d.toLocaleTimeString("en-PH", { hour: "numeric", minute: "2-digit" });
  return `${date}, ${time}`;
}

function totalQty(r) {
  return (
    (r.items || []).reduce((sum, item) => sum + Number(item.qty || 0), 0) +
    (r.uniforms || []).reduce(
      (sum, uniform) => sum + Number(uniform.quantity || 0),
      0,
    )
  );
}

function itemSummary(r) {
  const names = [
    ...(r.items || []).map((item) => item.itemName),
    ...(r.uniforms || []).map((uniform) => uniform.uniformName || "Uniform"),
  ].filter(Boolean);

  if (names.length === 0) return "—";
  if (names.length === 1) return names[0];

  return `${names[0]} +${names.length - 1} more`;
}

function toggleExpand(id) {
  expandedId.value = expandedId.value === id ? null : id;
}

async function approve(r) {
  const id = r.id;
  const dueItems = (r.items || []).filter(isDueEligible);
  const missing = dueItems.filter((i) => !dueDates.value[i.requestItemId]);
  const past = dueItems.filter(
    (i) => dueDates.value[i.requestItemId] < todayISO(),
  );

  if (missing.length || past.length) {
    expandedId.value = id; // open the row so the date fields are visible
    notify(
      "warning",
      missing.length
        ? `Set a due date for: ${missing.map((i) => i.itemName).join(", ")}.`
        : `Due date can't be in the past: ${past.map((i) => i.itemName).join(", ")}.`,
    );
    return;
  }

  const payload = {};
  dueItems.forEach((i) => {
    payload[i.requestItemId] = dueDates.value[i.requestItemId];
  });

  confirmTitle.value = "Approve Request";
  confirmMessage.value =
    "Are you sure you want to approve this request? This action will update the inventory.";

  confirmButton.value = "Approve";
  confirmButtonClass.value = "btn-success";

  confirmAction.value = async () => {
    await approveRequest(id, payload);
    await refresh();
  };

  confirmModal.value = true;
}

function openRejectModal(id) {
  rejectTargetId.value = id;
  rejectReason.value = "";
  rejectModal.value = true;
}

async function confirmReject() {
  if (!rejectReason.value.trim()) {
    notify("warning", "Rejection reason is required.");
    return;
  }

  const id = rejectTargetId.value;
  const reason = rejectReason.value.trim();

  rejectModal.value = false;

  confirmTitle.value = "Reject Request";
  confirmMessage.value =
    "Are you sure you want to reject this request?";

  confirmButton.value = "Reject";
  confirmButtonClass.value = "btn-danger";

  confirmAction.value = async () => {
    await rejectRequest(id, reason);

    rejectTargetId.value = null;
    rejectReason.value = "";

    await refresh();
  };

  confirmModal.value = true;
}

async function executeConfirm() {
  try {
    if (confirmAction.value) {
      await confirmAction.value();
    }
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }

  confirmModal.value = false;
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
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <div
      class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"
    >
      <div>
        <h3 class="mb-0">Pending Requests</h3>
        <div class="text-muted small">
          Pending: <strong>{{ pendingRequests.length }}</strong>
        </div>
      </div>

      <div class="d-flex align-items-center gap-3 flex-wrap">
        <select
          class="form-select form-select-sm"
          style="width: auto; min-width: 180px"
          v-model="userFilter"
        >
          <option value="">All requesters</option>
          <option v-for="name in uniqueRequesters" :key="name" :value="name">
            {{ name }}
          </option>
        </select>

        <div class="form-check form-switch mb-0">
          <input
            class="form-check-input"
            type="checkbox"
            id="showAll"
            v-model="showAll"
          />
          <label class="form-check-label" for="showAll">Show all requests</label>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 36px"></th>
              <th>Requester</th>
              <th>Items</th>
              <th style="width: 100px">Total Qty</th>
              <th style="width: 120px">Status</th>
              <th style="width: 180px">Date</th>
              <th style="width: 220px">Actions</th>
            </tr>
          </thead>

          <tbody>
            <template v-for="r in displayedRequests" :key="r.id">
              <tr style="cursor: pointer" @click="toggleExpand(r.id)">
                <td class="text-center text-muted">
                  <span :class="{ rotated: expandedId === r.id }" class="chevron">▶</span>
                </td>
                <td>
                  <div>{{ r.requester }}</div>
                  <div class="text-muted small">{{ r.role }}</div>
                </td>
                <td>{{ itemSummary(r) }}</td>
                <td>{{ totalQty(r) }}</td>
                <td>
                  <span class="badge" :class="statusBadgeClass(r.status)">
                    {{ r.status }}
                  </span>
                </td>
                <td>{{ formatRequestDate(r.request_date) }}</td>
                <td @click.stop>
                  <div class="d-flex gap-2">
                    <button
                      class="btn btn-sm btn-success"
                      :disabled="(r.status || '').toLowerCase() !== 'pending'"
                      @click="approve(r)"
                    >
                      Approve
                    </button>

                    <button
                      class="btn btn-sm btn-danger text-white"
                      :disabled="(r.status || '').toLowerCase() !== 'pending'"
                      @click="openRejectModal(r.id)"
                    >
                      Reject
                    </button>
                  </div>
                </td>
              </tr>

              <tr v-if="expandedId === r.id" class="detail-row">
                <td></td>
                <td colspan="6">
                  <div class="detail-panel">
                    <div class="row g-3">
                      <div class="col-md-7">
                        <div class="fw-semibold small text-muted mb-1">
                          Requested Items
                        </div>

                        <table class="table table-sm mb-0">
                          <thead>
                            <tr>
                              <th>Item</th>
                              <th>Category</th>
                              <th>Type</th>
                              <th style="width: 70px">Qty</th>
                              <th style="width: 170px">Due Date</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="item in r.items || []" :key="'item-' + item.itemId">
                              <td>{{ item.itemName }}</td>
                              <td>{{ item.category }}</td>
                              <td>{{ item.itemType }}</td>
                              <td>{{ item.qty }}</td>
                              <td>
                                <template v-if="isDueEligible(item)">
                                  <input
                                    v-if="(r.status || '').toLowerCase() === 'pending'"
                                    type="date"
                                    class="form-control form-control-sm"
                                    :min="todayISO()"
                                    v-model="dueDates[item.requestItemId]"
                                  />
                                  <span v-else class="small">{{ formatDue(item.dueAt) }}</span>
                                </template>
                                <span v-else class="text-muted">—</span>
                              </td>
                            </tr>
                            <tr
                              v-for="uniform in r.uniforms || []"
                              :key="'uniform-' + uniform.uniformVariantId"
                            >
                              <td>
                                {{ uniform.uniformName || "Uniform" }}
                                <div class="text-muted small">
                                  {{ uniform.department || "N/A" }}
                                  — Size {{ uniform.size || "N/A" }}
                                </div>
                              </td>
                              <td>Uniforms</td>
                              <td>Uniform</td>
                              <td>{{ uniform.quantity }}</td>
                              <td class="text-muted">—</td>
                            </tr>
                            <tr v-if="(r.items || []).length === 0 && (r.uniforms || []).length === 0">
                              <td colspan="5" class="text-muted">No items listed.</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>

                      <div class="col-md-5">
                        <div class="fw-semibold small text-muted mb-1">Details</div>
                        <div class="small mb-1"><strong>Purpose:</strong> {{ r.purpose || "—" }}</div>
                        <div class="small mb-1"><strong>Location:</strong> {{ r.location || "—" }}</div>
                        <div class="small mb-1"><strong>Room:</strong> {{ r.room || "—" }}</div>

                        <div
                          v-if="(r.status || '').toLowerCase() === 'rejected' && r.rejectReason"
                          class="text-danger small mt-2"
                        >
                          Rejection reason: {{ r.rejectReason }}
                        </div>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>

            <tr v-if="displayedRequests.length === 0">
              <td colspan="7" class="text-center text-muted py-4">
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
      <button class="btn btn-secondary text-white" @click="rejectModal = false">
        Cancel
      </button>

      <button class="btn btn-danger" @click="confirmReject" :disabled="!rejectReason.trim()">
        Reject
      </button>
    </div>

  </div>
</div>
<div v-if="confirmModal" class="modal-backdrop-custom">
  <div class="modal-custom" style="max-width:420px">

    <div class="modal-header">
      <h5 class="mb-0">{{ confirmTitle }}</h5>
    </div>

    <div class="modal-body">
      <p class="mb-0">
        {{ confirmMessage }}
      </p>
    </div>

    <div class="modal-footer">
      <button
        class="btn btn-secondary text-white"
        @click="confirmModal = false"
      >
        Cancel
      </button>

      <button
        class="btn"
        :class="confirmButtonClass"
        @click="executeConfirm"
      >
        {{ confirmButton }}
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

.chevron {
  display: inline-block;
  transition: transform 0.15s ease;
  font-size: 0.75rem;
}

.chevron.rotated {
  transform: rotate(90deg);
}

.detail-row td {
  background: var(--bs-tertiary-bg, #f8f9fa);
  border-top: none;
}

.detail-panel {
  padding: 0.75rem 0.5rem;
}
</style>

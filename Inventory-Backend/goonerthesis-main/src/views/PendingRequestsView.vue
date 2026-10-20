<script setup>
import { ref, reactive, computed, onMounted, watch } from "vue";
import { getRequests, approveRequest, rejectRequest, releaseRequest } from "../services/requests";
import { getCurrentUser } from "../services/storage";
import { requireAdmin } from "../services/session";
import AlertMessage from "../components/AlertMessage.vue";
import DueBadge from "../components/DueBadge.vue";
import { getErrorMessage } from "../services/errors";
import { isReturnableType } from "../services/itemTypes";
import { printBorrowerForm, hasBorrowedItems } from "../services/borrowerForm";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

const requests = ref([]);
const admin = getCurrentUser();
const userFilter = ref("");
const expandedId = ref(null);

// Tabs: Approving a request no longer means it's released -- those are
// two separate admin actions now, so they get two separate tabs instead
// of living in the same undifferentiated list.
const activeTab = ref("pending"); // "pending" | "release" | "all"

// On the All tab the Actions buttons are collapsed behind a small toggle
// so the table stays compact. Keyed by request id; other tabs always show
// their buttons.
const actionsOpen = reactive({});
function toggleActions(id) {
  actionsOpen[id] = !actionsOpen[id];
}
watch(activeTab, () => {
  for (const key of Object.keys(actionsOpen)) delete actionsOpen[key];
});

// Due dates the admin picks per borrowed line before approving
// (roadmap #8). Keyed "requestId:requestItemId". Blank = item default.
const dueDates = ref({});

function dueKey(r, item) {
  return `${r.id}:${item.requestItemId}`;
}

function ymd(date) {
  const p = (n) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`;
}

const todayYmd = computed(() => ymd(new Date()));

// Prefill with today + the item's default return period, when it has one.
function defaultDue(item) {
  if (!item.defaultReturnDays) return "";
  const d = new Date();
  d.setDate(d.getDate() + Number(item.defaultReturnDays));
  return ymd(d);
}

function dueValue(r, item) {
  const key = dueKey(r, item);
  return key in dueDates.value ? dueDates.value[key] : defaultDue(item);
}

function setDue(r, item, value) {
  dueDates.value = { ...dueDates.value, [dueKey(r, item)]: value };
}
const rejectModal = ref(false);
const rejectReason = ref("");
const rejectTargetId = ref(null);
const confirmModal = ref(false);
const confirmTitle = ref("");
const confirmMessage = ref("");
const confirmButton = ref("Confirm");
const confirmButtonClass = ref("btn-primary");
const confirmAction = ref(null);
// Optional note the approver can attach (only shown for Approve).
const confirmAskRemarks = ref(false);
const approvalRemarks = ref("");

onMounted(async () => {
  requireAdmin();

  await refresh();
});

async function refresh() {
  requests.value = await getRequests();
}

const pendingRequests = computed(() =>
  requests.value.filter((r) => ["with inventory", "pending"].includes((r.status || "").toLowerCase())),
);

// Approved, but Inventory hasn't recorded For Pick Up / For Delivery yet.
const releaseRequests = computed(() =>
  requests.value.filter(
    (r) => (r.status || "").toLowerCase() === "approved" && !r.releasedAt,
  ),
);

const uniqueRequesters = computed(() => {
  const names = requests.value
    .map((r) => r.requester)
    .filter((name) => !!name);

  return [...new Set(names)].sort((a, b) => a.localeCompare(b));
});

const tabBase = computed(() => {
  if (activeTab.value === "release") return releaseRequests.value;
  if (activeTab.value === "all") return requests.value;
  return pendingRequests.value;
});

const displayedRequests = computed(() => {
  let data = [...tabBase.value];

  if (userFilter.value) {
    data = data.filter((r) => r.requester === userFilter.value);
  }

  return data.sort(
    (a, b) =>
      new Date(b.request_date) -
      new Date(a.request_date)
  );
});
const requestRows = computed(() => {
  return displayedRequests.value;
});

const emptyMessage = computed(() => {
  if (activeTab.value === "release") return "No approved requests waiting to be released.";
  if (activeTab.value === "all") return "No requests found.";
  return "No pending requests.";
});

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

async function approve(id) {
  confirmTitle.value = "Approve Request";
  confirmMessage.value =
    "Are you sure you want to approve this request? This action will update the inventory.";

  confirmButton.value = "Approve";
  confirmButtonClass.value = "btn-success";
  confirmAskRemarks.value = true;
  approvalRemarks.value = "";

  const target = requests.value.find((r) => r.id === id);
  const chosen = {};
  for (const item of target?.items || []) {
    if (!isReturnableType(item.itemType)) continue;
    const value = dueValue(target, item);
    if (!value) continue;
    if (value < todayYmd.value) {
      notify("warning", `Due date for ${item.itemName} cannot be in the past.`);
      return;
    }
    chosen[item.requestItemId] = value;
  }

  confirmAction.value = async () => {
    await approveRequest(id, chosen, approvalRemarks.value.trim());
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
  confirmAskRemarks.value = false;

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

// RELEASE (roadmap #15 / #19) //
// Once a request is Approved, Inventory records whether the requester
// is picking the items up or having them delivered.

const releaseModal = ref(false);
const releaseTargetId = ref(null);
const releaseMethod = ref("For Pick Up");

function openReleaseModal(id) {
  const target = requests.value.find((r) => r.id === id);
  releaseTargetId.value = id;
  releaseMethod.value = target?.releaseMethod || "For Pick Up";
  releaseModal.value = true;
}

async function confirmRelease() {
  const id = releaseTargetId.value;
  const method = releaseMethod.value;

  releaseModal.value = false;

  try {
    await releaseRequest(id, method);
    notify("success", `Request marked as released: ${method}.`);
    await refresh();
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }
}

function releaseBadgeText(r) {
  if (!r.releasedAt) return "";
  return r.releaseMethod || "Released";
}

async function resendNotification(id) {
  try {
    const { data } = await (await import('../axios')).default.post(`/requests/${id}/resend-notification`)
    notify('success', data.message || 'In-app notification recorded.')
    await refresh()
  } catch (e) { notify('danger', e.response?.data?.message || 'Unable to record notification.') }
}

function statusBadgeClass(status) {
  const s = (status || "").toLowerCase();
  if (s === "pending" || s === "with inventory") return "bg-warning text-dark";
  if (s === "with coordinator") return "bg-info text-dark";
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
        <h3 class="mb-0">Requests</h3>
        <div class="text-muted small">
          Pending: <strong>{{ pendingRequests.length }}</strong>
          &nbsp;·&nbsp;
          Awaiting release: <strong>{{ releaseRequests.length }}</strong>
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
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <button
        type="button"
        class="btn"
        :class="activeTab === 'pending' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'pending'; expandedId = null"
      >
        Pending
        <span class="badge bg-warning text-dark ms-1">{{ pendingRequests.length }}</span>
      </button>
      <button
        type="button"
        class="btn"
        :class="activeTab === 'release' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'release'; expandedId = null"
      >
        For Release
        <span class="badge bg-info text-dark ms-1">{{ releaseRequests.length }}</span>
      </button>
      <button
        type="button"
        class="btn"
        :class="activeTab === 'all' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'all'; expandedId = null"
      >
        All
      </button>
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
              <th style="width: 130px">Date</th>
              <th :style="{ width: activeTab === 'all' ? '130px' : '250px' }">Actions</th>
            </tr>
          </thead>

          <tbody>
            <template v-for="r in displayedRequests" :key="r.id">
              <tr style="cursor: pointer" @click="toggleExpand(r.id)">
                <td class="text-center text-muted">
                  <span :class="{ rotated: expandedId === r.id }" class="chevron">▶&#xFE0E;</span>
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
                  <div
                    v-if="releaseBadgeText(r)"
                    class="badge bg-info text-dark mt-1"
                  >
                    {{ releaseBadgeText(r) }}
                  </div>
                </td>
                <td>{{ r.request_date || "—" }}</td>
                <td @click.stop>
                  <button
                    v-if="activeTab === 'all'"
                    class="btn btn-sm btn-secondary text-white"
                    :aria-expanded="!!actionsOpen[r.id]"
                    @click="toggleActions(r.id)"
                  >
                    Actions
                    <span :class="{ rotated: actionsOpen[r.id] }" class="chevron ms-1">▶&#xFE0E;</span>
                  </button>
                  <div
                    v-if="activeTab !== 'all' || actionsOpen[r.id]"
                    :class="activeTab === 'all' ? 'd-flex flex-column align-items-stretch gap-1 mt-2 text-nowrap' : 'd-flex gap-1 text-nowrap'"
                  >
                    <button
                      v-if="activeTab !== 'release'"
                      class="btn btn-sm btn-success"
                      :disabled="!['with inventory', 'pending'].includes((r.status || '').toLowerCase())"
                      @click="approve(r.id)"
                    >
                      Approve
                    </button>

                    <button
                      v-if="activeTab !== 'release'"
                      class="btn btn-sm btn-danger text-white"
                      :disabled="!['with inventory', 'pending'].includes((r.status || '').toLowerCase())"
                      @click="openRejectModal(r.id)"
                    >
                      Reject
                    </button>
                    <button
                      v-if="(r.status || '').toLowerCase() === 'approved'"
                      class="btn btn-sm btn-info text-dark"
                      @click="openReleaseModal(r.id)"
                      :title="r.releasedAt ? 'Change how this request is being released' : 'Mark items as released to the requester'"
                    >
                      {{ r.releasedAt ? "Change Release" : "Release" }}
                    </button>

                    <button
                      v-if="(r.status || '').toLowerCase() === 'approved' && hasBorrowedItems(r)"
                      class="btn btn-sm btn-dark text-white"
                      @click="printBorrowerForm(r)"
                      title="Print the Borrower's Form for this request"
                    >Print Form</button>

                    <button
                      v-if="['approved', 'rejected'].includes((r.status || '').toLowerCase())"
                      class="btn btn-sm btn-secondary text-white"
                      @click="resendNotification(r.id)"
                      title="Create another in-app notification for the requester"
                    >Notify</button>
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
                                <input
                                  v-if="isReturnableType(item.itemType) && ['Pending', 'With Inventory'].includes(r.status)"
                                  type="date"
                                  class="form-control form-control-sm"
                                  :min="todayYmd"
                                  :value="dueValue(r, item)"
                                  @input="setDue(r, item, $event.target.value)"
                                />
                                <DueBadge
                                  v-else-if="isReturnableType(item.itemType) && item.dueDate"
                                  :due-date="item.dueDate"
                                  :overdue="item.overdue"
                                  :days-overdue="item.daysOverdue"
                                />
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
                          v-if="(r.status || '').toLowerCase() === 'approved'"
                          class="small mb-1"
                        >
                          <strong>Release:</strong>
                          <span v-if="r.releasedAt">
                            {{ r.releaseMethod }} —
                            {{ new Date(r.releasedAt).toLocaleString("en-PH") }}
                            <span v-if="r.releasedBy" class="text-muted">
                              (by {{ r.releasedBy }})
                            </span>
                          </span>
                          <span v-else class="text-muted">Not yet released</span>
                        </div>

                        <div
                          v-if="(r.status || '').toLowerCase() === 'approved' && r.approvalRemarks"
                          class="small mb-1"
                        >
                          <strong>Approval remarks:</strong> {{ r.approvalRemarks }}
                        </div>

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
                {{ emptyMessage }}
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
<div v-if="releaseModal" class="modal-backdrop-custom">
  <div class="modal-custom">

    <div class="modal-header">
      <h5 class="mb-0">Release Request</h5>
      <button class="btn-close" @click="releaseModal = false"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">How are these items going out?</label>

      <div class="form-check">
        <input
          class="form-check-input"
          type="radio"
          id="release-pickup"
          value="For Pick Up"
          v-model="releaseMethod"
        />
        <label class="form-check-label" for="release-pickup">
          For Pick Up — the requester collects it themselves
        </label>
      </div>

      <div class="form-check">
        <input
          class="form-check-input"
          type="radio"
          id="release-delivery"
          value="For Delivery"
          v-model="releaseMethod"
        />
        <label class="form-check-label" for="release-delivery">
          For Delivery — delivered to the request's location/room
        </label>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-secondary text-white" @click="releaseModal = false">
        Cancel
      </button>

      <button class="btn btn-info text-dark" @click="confirmRelease">
        Confirm Release
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

      <div v-if="confirmAskRemarks" class="mt-3">
        <label class="form-label small mb-1">Remarks (optional)</label>
        <textarea
          v-model="approvalRemarks"
          class="form-control"
          rows="3"
          maxlength="500"
          placeholder="Request Approved but won't be released yet."
        ></textarea>
        <div class="form-text">The requester will see this with the approval.</div>
      </div>
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

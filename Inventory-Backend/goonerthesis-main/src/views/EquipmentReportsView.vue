<script setup>
import { ref, computed, onMounted } from "vue";
import {
  getReportLocations,
  getEquipmentReports,
  createEquipmentReport,
  sendReportToInspection,
  dismissReport,
} from "../services/equipmentReports";
import { getCurrentUser } from "../services/storage";
import { isAdmin as checkAdmin } from "../services/session";
import AlertMessage from "../components/AlertMessage.vue";

const admin = computed(() => checkAdmin(getCurrentUser()));

// ---------- notifications ----------
const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

// ---------- data ----------
const loading = ref(true);
const rooms = ref([]);
const reports = ref([]);

async function load() {
  loading.value = true;
  try {
    const [r, list] = await Promise.all([
      getReportLocations(),
      getEquipmentReports(),
    ]);
    rooms.value = r;
    reports.value = list;
  } catch (e) {
    notify("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
  // Keep the sidebar's open-report badge in step.
  window.dispatchEvent(new Event("cims:equipment-reports-changed"));
}

onMounted(load);

// ---------- report form ----------
const form = ref({
  idroom: "",
  iditems: "",
  quantity: 1,
  report_type: "Maintenance",
  description: "",
});
const formError = ref("");
const submitting = ref(false);

const selectedRoom = computed(
  () => rooms.value.find((r) => String(r.idroom) === String(form.value.idroom)) || null,
);
const selectedEquipment = computed(
  () =>
    selectedRoom.value?.equipment.find(
      (e) => String(e.iditems) === String(form.value.iditems),
    ) || null,
);
const maxQty = computed(() => selectedEquipment.value?.available_to_report ?? 0);

function equipmentLabel(e) {
  const extra = e.already_reported ? `, ${e.already_reported} already reported` : "";
  return `${e.item_name} (${e.quantity} in room${extra})`;
}

function onRoomChange() {
  form.value.iditems = "";
  form.value.quantity = 1;
  formError.value = "";
}

function onEquipmentChange() {
  form.value.quantity = 1;
  formError.value = "";
}

function validateForm() {
  if (!form.value.idroom) return "Choose the room.";
  if (!form.value.iditems) return "Choose the equipment.";
  const qty = Number(form.value.quantity);
  if (!Number.isInteger(qty) || qty < 1) return "Quantity must be at least 1.";
  if (qty > maxQty.value) {
    return maxQty.value === 0
      ? "All units of this equipment in the room are already in an open report."
      : `Only ${maxQty.value} unit(s) can still be reported for this room.`;
  }
  if (!form.value.description.trim()) return "Describe what is wrong.";
  return "";
}

async function submitReport() {
  formError.value = validateForm();
  if (formError.value) return;

  submitting.value = true;
  try {
    const res = await createEquipmentReport({
      idroom: Number(form.value.idroom),
      iditems: Number(form.value.iditems),
      quantity: Number(form.value.quantity),
      report_type: form.value.report_type,
      description: form.value.description.trim(),
    });
    notify("success", res.message || "Report submitted.");
    form.value.iditems = "";
    form.value.quantity = 1;
    form.value.description = "";
    await load();
  } catch (e) {
    formError.value = errMsg(e);
  } finally {
    submitting.value = false;
  }
}

// ---------- report list ----------
const statusFilter = ref(admin.value ? "Open" : "All");

const filteredReports = computed(() =>
  statusFilter.value === "All"
    ? reports.value
    : reports.value.filter((r) => r.status === statusFilter.value),
);

const openCount = computed(
  () => reports.value.filter((r) => r.status === "Open").length,
);

function typeLabel(type) {
  return type === "Disposal" ? "For Disposal" : "Needs Maintenance";
}

function statusClass(status) {
  if (status === "Open") return "bg-warning text-dark";
  if (status === "Sent to Inspection") return "bg-info text-dark";
  return "bg-secondary";
}

function formatDate(value) {
  if (!value) return "-";
  const d = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function inspectionSummary(r) {
  const i = r.inspection;
  if (!i) return "";
  if (i.status === "Pending") return "Waiting for inspection";
  const parts = [];
  if (i.fit_for_use > 0) parts.push(`${i.fit_for_use} fit for use`);
  if (i.maintenance > 0) parts.push(`${i.maintenance} need maintenance`);
  if (i.disposal > 0) parts.push(`${i.disposal} for disposal`);
  return `Inspected: ${parts.join(", ") || "done"}`;
}

// ---------- admin actions ----------
const sendTarget = ref(null);
const dismissTarget = ref(null);
const dismissNotes = ref("");
const dismissError = ref("");
const working = ref(false);

async function confirmSend() {
  working.value = true;
  try {
    const res = await sendReportToInspection(sendTarget.value.id);
    notify("success", res.message || "Sent to Pending Inspection.");
    sendTarget.value = null;
    await load();
  } catch (e) {
    notify("danger", errMsg(e));
    sendTarget.value = null;
  } finally {
    working.value = false;
  }
}

function openDismiss(r) {
  dismissTarget.value = r;
  dismissNotes.value = "";
  dismissError.value = "";
}

async function confirmDismiss() {
  if (!dismissNotes.value.trim()) {
    dismissError.value = "Please give a reason.";
    return;
  }
  working.value = true;
  try {
    const res = await dismissReport(dismissTarget.value.id, dismissNotes.value.trim());
    notify("success", res.message || "Report dismissed.");
    dismissTarget.value = null;
    await load();
  } catch (e) {
    dismissError.value = errMsg(e);
  } finally {
    working.value = false;
  }
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <h4 class="mb-1">Report Equipment</h4>
    <p class="text-muted mb-4">
      Report school equipment that needs maintenance or disposal.
    </p>

    <div class="row g-3">
      <!-- REPORT FORM -->
      <div class="col-lg-5">
        <div class="card shadow-sm">
          <div class="card-header"><h6 class="mb-0">New report</h6></div>
          <div class="card-body">
            <div v-if="!loading && rooms.length === 0" class="alert alert-info mb-0">
              No equipment has been assigned to a room yet, so there is nothing to
              report. The admin can assign equipment under Equipment Distribution.
            </div>

            <template v-else>
              <div class="mb-3">
                <label class="form-label">Room</label>
                <select
                  class="form-select"
                  v-model="form.idroom"
                  @change="onRoomChange"
                >
                  <option value="" disabled>Select a room</option>
                  <option v-for="r in rooms" :key="r.idroom" :value="r.idroom">
                    {{ r.building_name ? r.building_name + " - " : "" }}{{ r.room_name }}
                  </option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Equipment</label>
                <select
                  class="form-select"
                  v-model="form.iditems"
                  :disabled="!selectedRoom"
                  @change="onEquipmentChange"
                >
                  <option value="" disabled>
                    {{ selectedRoom ? "Select equipment" : "Choose a room first" }}
                  </option>
                  <option
                    v-for="e in selectedRoom?.equipment || []"
                    :key="e.iditems"
                    :value="e.iditems"
                    :disabled="e.available_to_report === 0"
                  >
                    {{ equipmentLabel(e) }}
                  </option>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Quantity affected</label>
                <input
                  type="number"
                  min="1"
                  :max="maxQty || 1"
                  class="form-control"
                  v-model="form.quantity"
                  :disabled="!selectedEquipment"
                />
                <small v-if="selectedEquipment" class="text-muted">
                  Up to {{ maxQty }} unit(s) can be reported.
                </small>
              </div>

              <div class="mb-3">
                <label class="form-label d-block">What does it need?</label>
                <div class="form-check form-check-inline">
                  <input
                    id="rt-maint"
                    class="form-check-input"
                    type="radio"
                    value="Maintenance"
                    v-model="form.report_type"
                  />
                  <label class="form-check-label" for="rt-maint">Maintenance</label>
                </div>
                <div class="form-check form-check-inline">
                  <input
                    id="rt-disp"
                    class="form-check-input"
                    type="radio"
                    value="Disposal"
                    v-model="form.report_type"
                  />
                  <label class="form-check-label" for="rt-disp">Disposal</label>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label">What is wrong?</label>
                <textarea
                  class="form-control"
                  rows="3"
                  maxlength="500"
                  v-model="form.description"
                  placeholder="e.g. Aircon is not cooling and leaks water"
                ></textarea>
              </div>

              <div v-if="formError" class="alert alert-danger py-2">
                {{ formError }}
              </div>

              <button
                class="btn btn-primary w-100"
                :disabled="submitting"
                @click="submitReport"
              >
                {{ submitting ? "Submitting..." : "Submit report" }}
              </button>
            </template>
          </div>
        </div>
      </div>

      <!-- REPORT LIST -->
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div
            class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"
          >
            <h6 class="mb-0">
              {{ admin ? "All reports" : "My reports" }}
              <span v-if="admin && openCount" class="badge bg-warning text-dark ms-1">
                {{ openCount }} open
              </span>
            </h6>
            <select
              class="form-select form-select-sm w-auto"
              v-model="statusFilter"
            >
              <option value="All">All</option>
              <option value="Open">Open</option>
              <option value="Sent to Inspection">Sent to Inspection</option>
              <option value="Dismissed">Dismissed</option>
            </select>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Equipment</th>
                  <th style="width: 60px">Qty</th>
                  <th>Status</th>
                  <th v-if="admin" style="width: 150px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in filteredReports" :key="r.id">
                  <td>
                    <div class="fw-semibold">{{ r.item_name }}</div>
                    <div class="small text-muted">
                      {{ r.building_name ? r.building_name + " - " : "" }}{{ r.room_name }}
                    </div>
                    <div class="small mt-1">
                      <span
                        class="badge me-1"
                        :class="r.report_type === 'Disposal' ? 'bg-danger' : 'bg-primary'"
                      >
                        {{ typeLabel(r.report_type) }}
                      </span>
                      <span class="text-break">{{ r.description }}</span>
                    </div>
                    <div class="small text-muted mt-1">
                      {{ formatDate(r.created_at) }}
                      <template v-if="admin && r.reported_by"> · by {{ r.reported_by }}</template>
                    </div>
                  </td>
                  <td>{{ r.quantity }}</td>
                  <td>
                    <span class="badge" :class="statusClass(r.status)">{{ r.status }}</span>
                    <div v-if="r.inspection" class="small text-muted mt-1">
                      {{ inspectionSummary(r) }}
                    </div>
                    <div v-if="r.status === 'Dismissed' && r.review_notes" class="small text-muted mt-1">
                      {{ r.review_notes }}
                    </div>
                  </td>
                  <td v-if="admin" class="text-end">
                    <div v-if="r.status === 'Open'" class="d-flex flex-column gap-1">
                      <button class="btn btn-primary btn-sm" @click="sendTarget = r">
                        Send to Inspection
                      </button>
                      <button class="btn btn-outline-secondary btn-sm" @click="openDismiss(r)">
                        Dismiss
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="!filteredReports.length">
                  <td :colspan="admin ? 4 : 3" class="text-center text-muted py-4">
                    {{ loading ? "Loading..." : "No reports found." }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- SEND TO INSPECTION (confirm) -->
    <div v-if="sendTarget" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Send to Pending Inspection</h5>
          <button class="btn-close" @click="sendTarget = null"></button>
        </div>
        <div class="modal-body">
          Remove <strong>{{ sendTarget.quantity }}</strong> ×
          <strong>{{ sendTarget.item_name }}</strong> from
          <strong>{{ sendTarget.room_name }}</strong> and send it to Pending
          Inspection?
          <div class="small text-muted mt-2">
            The units leave the room and wait there until you evaluate them as
            fit for use, needing maintenance, or for disposal.
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="working" @click="sendTarget = null">
            Cancel
          </button>
          <button class="btn btn-primary" :disabled="working" @click="confirmSend">
            {{ working ? "Sending..." : "Send" }}
          </button>
        </div>
      </div>
    </div>

    <!-- DISMISS (reason) -->
    <div v-if="dismissTarget" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Dismiss report</h5>
          <button class="btn-close" @click="dismissTarget = null"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">
            {{ dismissTarget.item_name }} × {{ dismissTarget.quantity }} in
            {{ dismissTarget.room_name }}
          </p>
          <label class="form-label">Reason</label>
          <textarea
            class="form-control"
            rows="2"
            maxlength="255"
            v-model="dismissNotes"
            placeholder="e.g. Checked: the equipment is working"
          ></textarea>
          <div v-if="dismissError" class="alert alert-danger py-2 mt-3 mb-0">
            {{ dismissError }}
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="working" @click="dismissTarget = null">
            Cancel
          </button>
          <button class="btn btn-danger" :disabled="working" @click="confirmDismiss">
            {{ working ? "Saving..." : "Dismiss" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
</style>

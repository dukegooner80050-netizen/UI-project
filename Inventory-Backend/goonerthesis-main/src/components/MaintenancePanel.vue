<script setup>
import { ref, computed, onMounted } from "vue";
import { getMaintenanceRecords, resolveMaintenance } from "../services/maintenance";
import AlertMessage from "./AlertMessage.vue";

// The Maintenance view of the Inventory page: every batch of units sent for
// repair, with the reported problem and the room it came from. Repaired units
// go back to that room; units that can't be repaired move to the disposal list.

const props = defineProps({
  search: { type: String, default: "" },
});
const emit = defineEmits(["changed"]);

const open = ref([]);
const history = ref([]);
const loading = ref(true);
const view = ref("open"); // "open" | "history"

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

async function load() {
  loading.value = true;
  try {
    const data = await getMaintenanceRecords();
    open.value = data.open || [];
    history.value = data.history || [];
  } catch (e) {
    notify("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

function matches(r, keyword) {
  if (!keyword) return true;
  return [r.item_name, r.category, r.origin, r.problem]
    .filter(Boolean)
    .some((v) => String(v).toLowerCase().includes(keyword));
}

const keyword = computed(() => props.search.trim().toLowerCase());
const openRows = computed(() => open.value.filter((r) => matches(r, keyword.value)));
const historyRows = computed(() => history.value.filter((r) => matches(r, keyword.value)));

function formatDate(value) {
  if (!value) return "-";
  const d = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}

function daysLabel(n) {
  if (n === null || n === undefined) return "-";
  if (n === 0) return "Today";
  return `${n} day${n === 1 ? "" : "s"}`;
}

function daysClass(n) {
  if (n > 30) return "bg-danger";
  if (n > 14) return "bg-warning text-dark";
  return "bg-secondary";
}

// Where repaired units will go.
function destinationText(r) {
  if (r.goes_back_to) return `Repaired units return to ${r.goes_back_to}`;
  if (r.room_missing) return "Its room no longer exists, so repaired units go to available stock";
  return "Repaired units go to available stock";
}

function resultText(r) {
  const parts = [];
  if (r.repaired_qty) parts.push(`${r.repaired_qty} repaired`);
  if (r.unrepairable_qty) parts.push(`${r.unrepairable_qty} couldn't be repaired`);
  return parts.join(", ") || "-";
}

// ---------- resolve modal ----------
const target = ref(null);
const outcome = ref("repaired");
const qty = ref(1);
const notes = ref("");
const formError = ref("");
const saving = ref(false);

function openResolve(r, which) {
  target.value = r;
  outcome.value = which;
  qty.value = r.remaining;
  notes.value = "";
  formError.value = "";
}

function closeResolve() {
  target.value = null;
  formError.value = "";
}

async function confirmResolve() {
  const r = target.value;
  const n = Number(qty.value);

  if (!Number.isInteger(n) || n < 1) {
    formError.value = "Enter a quantity of at least 1.";
    return;
  }
  if (n > r.remaining) {
    formError.value = `Only ${r.remaining} unit(s) are still in repair.`;
    return;
  }
  if (outcome.value === "unrepairable" && !notes.value.trim()) {
    formError.value = "Add a short note on why it can't be repaired.";
    return;
  }

  saving.value = true;
  try {
    const res = await resolveMaintenance(r.id, {
      outcome: outcome.value,
      quantity: n,
      notes: notes.value.trim(),
    });
    notify("success", res.message || "Saved.");
    closeResolve();
    await load();
    emit("changed");
  } catch (e) {
    formError.value = errMsg(e);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <div class="card shadow-sm">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="btn-group btn-group-sm">
          <button
            class="btn"
            :class="view === 'open' ? 'btn-primary' : 'btn-outline-primary'"
            @click="view = 'open'"
          >
            In repair ({{ open.length }})
          </button>
          <button
            class="btn"
            :class="view === 'history' ? 'btn-primary' : 'btn-outline-primary'"
            @click="view = 'history'"
          >
            History
          </button>
        </div>
        <small class="text-muted">
          Repaired units go back to the room they came from.
        </small>
      </div>

      <div class="table-responsive">
        <!-- IN REPAIR -->
        <table v-if="view === 'open'" class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th>
              <th>From</th>
              <th>Problem reported</th>
              <th style="width: 90px">Units</th>
              <th style="width: 120px">In repair</th>
              <th style="width: 250px">Result</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in openRows" :key="r.id">
              <td>
                <div class="fw-semibold">{{ r.item_name }}</div>
                <div class="small text-muted">{{ r.category }}</div>
              </td>
              <td>
                <div>{{ r.origin || "-" }}</div>
                <div class="small text-muted">{{ destinationText(r) }}</div>
              </td>
              <td class="text-break">{{ r.problem || "—" }}</td>
              <td>
                <span class="badge bg-warning text-dark">{{ r.remaining }}</span>
                <div v-if="r.remaining !== r.quantity" class="small text-muted">
                  of {{ r.quantity }}
                </div>
              </td>
              <td>
                <span class="badge" :class="daysClass(r.days_in_repair)">
                  {{ daysLabel(r.days_in_repair) }}
                </span>
                <div class="small text-muted mt-1">since {{ formatDate(r.sent_at) }}</div>
              </td>
              <td>
                <div class="d-flex flex-wrap gap-2">
                  <button class="btn btn-sm btn-success" @click="openResolve(r, 'repaired')">
                    Mark repaired
                  </button>
                  <button
                    class="btn btn-sm btn-danger text-white"
                    @click="openResolve(r, 'unrepairable')"
                  >
                    Can't be repaired
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="!openRows.length">
              <td colspan="6" class="text-center text-muted py-4">
                {{
                  loading
                    ? "Loading..."
                    : open.length
                      ? "No records match your search."
                      : "No units are currently under maintenance."
                }}
              </td>
            </tr>
          </tbody>
        </table>

        <!-- HISTORY -->
        <table v-else class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th>
              <th>From</th>
              <th>Problem reported</th>
              <th>Result</th>
              <th>Sent</th>
              <th>Closed</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in historyRows" :key="r.id">
              <td>
                <div class="fw-semibold">{{ r.item_name }}</div>
                <div class="small text-muted">{{ r.category }}</div>
              </td>
              <td>{{ r.origin || "-" }}</td>
              <td class="text-break">{{ r.problem || "—" }}</td>
              <td>{{ resultText(r) }}</td>
              <td class="text-nowrap">{{ formatDate(r.sent_at) }}</td>
              <td class="text-nowrap">
                {{ formatDate(r.closed_at) }}
                <div v-if="r.closed_by" class="small text-muted">{{ r.closed_by }}</div>
              </td>
              <td class="small text-break" style="white-space: pre-line">{{ r.notes || "—" }}</td>
            </tr>

            <tr v-if="!historyRows.length">
              <td colspan="7" class="text-center text-muted py-4">
                {{
                  loading
                    ? "Loading..."
                    : history.length
                      ? "No records match your search."
                      : "No finished repairs yet."
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- RESOLVE -->
    <div v-if="target" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ outcome === "repaired" ? "Mark as repaired" : "Can't be repaired" }}
          </h5>
          <button type="button" class="btn-close" @click="closeResolve"></button>
        </div>

        <div class="modal-body">
          <p class="mb-3">
            <strong>{{ target.item_name }}</strong>
            <span class="text-muted"> — {{ target.remaining }} unit(s) in repair</span>
            <br />
            <small class="text-muted">From {{ target.origin || "-" }}</small>
          </p>

          <label class="form-label" for="mt-qty">How many units?</label>
          <input
            id="mt-qty"
            v-model.number="qty"
            type="number"
            min="1"
            :max="target.remaining"
            class="form-control mb-3"
          />

          <label class="form-label" for="mt-notes">
            Notes<span v-if="outcome === 'unrepairable'"> (required)</span>
          </label>
          <textarea
            id="mt-notes"
            v-model="notes"
            rows="2"
            maxlength="255"
            class="form-control"
            :placeholder="
              outcome === 'repaired'
                ? 'e.g. Replaced the compressor'
                : 'e.g. Beyond repair, parts no longer available'
            "
          ></textarea>

          <div class="alert alert-light border small mt-3 mb-0">
            <template v-if="outcome === 'repaired'">{{ destinationText(target) }}.</template>
            <template v-else>
              These units move to the disposal list (the Damaged view), where you can
              dispose of them.
            </template>
          </div>

          <div v-if="formError" class="alert alert-danger py-2 mt-3 mb-0">
            {{ formError }}
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="saving" @click="closeResolve">
            Cancel
          </button>
          <button
            type="button"
            class="btn"
            :class="outcome === 'repaired' ? 'btn-success' : 'btn-danger'"
            :disabled="saving"
            @click="confirmResolve"
          >
            {{ saving ? "Saving..." : "Confirm" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: grid;
  place-items: center;
  z-index: 2000;
  padding: 16px;
}
.modal-custom {
  background: var(--bs-body-bg, #fff);
  color: var(--bs-body-color, inherit);
  width: 100%;
  max-width: 480px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
}
.modal-header,
.modal-footer {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.modal-footer {
  justify-content: flex-end;
}
.modal-body {
  padding: 16px;
}
</style>

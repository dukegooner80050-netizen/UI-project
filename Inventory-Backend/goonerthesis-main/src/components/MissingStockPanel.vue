<script setup>
import { ref, computed, onMounted } from "vue";
import { getMissingStock, markStockFound } from "../services/missingStock";
import AlertMessage from "./AlertMessage.vue";

const props = defineProps({ search: { type: String, default: "" } });
const emit = defineEmits(["changed"]);

const open = ref([]);
const history = ref([]);
const missingTotal = ref(0);
const loading = ref(true);
const view = ref("open");
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
    const data = await getMissingStock();
    open.value = data.open || [];
    history.value = data.history || [];
    missingTotal.value = Number(data.missing_total) || 0;
  } catch (e) {
    notify("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const keyword = computed(() => props.search.trim().toLowerCase());
function matches(record) {
  if (!keyword.value) return true;
  return [record.item_name, record.category, record.reason, record.notes, record.reported_by, record.found_by]
    .filter(Boolean)
    .some((value) => String(value).toLowerCase().includes(keyword.value));
}
const openRows = computed(() => open.value.filter(matches));
const historyRows = computed(() => history.value.filter(matches));

function formatDate(value) {
  if (!value) return "—";
  const d = new Date(String(value).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}

function daysLabel(n) {
  if (n === null || n === undefined) return "—";
  return `${n} day${n === 1 ? "" : "s"}`;
}

const target = ref(null);
const qty = ref(1);
const formError = ref("");
const saving = ref(false);

function openFound(record) {
  target.value = record;
  qty.value = record.remaining;
  formError.value = "";
}

function closeFound() {
  if (!saving.value) target.value = null;
  formError.value = "";
}

async function confirmFound() {
  const record = target.value;
  const quantity = Number(qty.value);
  if (!Number.isInteger(quantity) || quantity < 1) {
    formError.value = "Enter a quantity of at least 1.";
    return;
  }
  if (quantity > record.remaining) {
    formError.value = `Only ${record.remaining} unit(s) remain marked missing.`;
    return;
  }

  saving.value = true;
  try {
    const result = await markStockFound(record.id, quantity);
    target.value = null;
    notify("success", result.message || "Recovered units returned to stock.");
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
          <button class="btn" :class="view === 'open' ? 'btn-primary' : 'btn-outline-primary'" @click="view = 'open'">
            Currently missing ({{ missingTotal }} units)
          </button>
          <button class="btn" :class="view === 'history' ? 'btn-primary' : 'btn-outline-primary'" @click="view = 'history'">
            Found history
          </button>
        </div>
        <small class="text-muted">Recovered units are returned to available inventory.</small>
      </div>

      <div class="table-responsive">
        <table v-if="view === 'open'" class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Item</th><th>Reason</th><th>Notes</th><th>Reported</th><th>Reported by</th><th>Missing for</th><th>Still missing</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in openRows" :key="r.id">
              <td><div class="fw-semibold">{{ r.item_name }}</div><div class="small text-muted">{{ r.category || "—" }}</div></td>
              <td><span class="badge bg-warning text-dark">{{ r.reason }}</span></td>
              <td class="text-break">{{ r.notes || "—" }}</td>
              <td class="text-nowrap">{{ formatDate(r.reported_at) }}</td>
              <td>{{ r.reported_by }}</td>
              <td><span class="badge" :class="r.days_missing > 30 ? 'bg-danger' : r.days_missing > 14 ? 'bg-warning text-dark' : 'bg-secondary'">{{ daysLabel(r.days_missing) }}</span></td>
              <td><span class="badge bg-danger">{{ r.remaining }}</span><div v-if="r.found_quantity" class="small text-muted">of {{ r.quantity }} ({{ r.found_quantity }} found)</div></td>
              <td><button class="btn btn-sm btn-success" @click="openFound(r)">Mark found</button></td>
            </tr>
            <tr v-if="!openRows.length"><td colspan="8" class="text-center text-muted py-4">{{ loading ? "Loading..." : open.length ? "No records match your search." : "No units are currently marked missing." }}</td></tr>
          </tbody>
        </table>

        <table v-else class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Item</th><th>Reason</th><th>Quantity found</th><th>Reported</th><th>Found on</th><th>Found by</th><th>Notes</th></tr></thead>
          <tbody>
            <tr v-for="r in historyRows" :key="r.id">
              <td><div class="fw-semibold">{{ r.item_name }}</div><div class="small text-muted">{{ r.category || "—" }}</div></td>
              <td>{{ r.reason }}</td><td>{{ r.found_quantity }} / {{ r.quantity }}</td><td class="text-nowrap">{{ formatDate(r.reported_at) }}</td><td class="text-nowrap">{{ formatDate(r.found_at) }}</td><td>{{ r.found_by || "—" }}</td><td class="small text-break">{{ r.notes || "—" }}</td>
            </tr>
            <tr v-if="!historyRows.length"><td colspan="7" class="text-center text-muted py-4">{{ loading ? "Loading..." : history.length ? "No records match your search." : "No recovered-item history yet." }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="target" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header"><h5 class="mb-0">Mark recovered units as found</h5><button type="button" class="btn-close" :disabled="saving" @click="closeFound"></button></div>
        <div class="modal-body">
          <p><strong>{{ target.item_name }}</strong> — {{ target.remaining }} unit(s) still marked missing ({{ target.reason }}).</p>
          <label class="form-label" for="found-qty">Quantity found</label>
          <input id="found-qty" v-model.number="qty" type="number" min="1" :max="target.remaining" class="form-control" />
          <div class="form-text">This quantity will be added back to available stock. The report stays in the found history.</div>
          <div v-if="formError" class="alert alert-danger py-2 mt-3 mb-0">{{ formError }}</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="saving" @click="closeFound">Cancel</button>
          <button type="button" class="btn btn-success" :disabled="saving" @click="confirmFound">{{ saving ? "Saving..." : "Confirm found" }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop-custom { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.35); display: grid; place-items: center; z-index: 2000; padding: 16px; }
.modal-custom { background: var(--bs-body-bg, #fff); color: var(--bs-body-color, inherit); width: 100%; max-width: 480px; border-radius: 12px; overflow: hidden; box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25); }
.modal-header, .modal-footer { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.modal-footer { justify-content: flex-end; }
.modal-body { padding: 16px; }
</style>

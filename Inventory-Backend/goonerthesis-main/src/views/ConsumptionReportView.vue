<script setup>
import { ref, computed, onMounted } from "vue";
import { getConsumption } from "../services/requests";
import { getCurrentUser } from "../services/storage";
import AlertMessage from "../components/AlertMessage.vue";

const loading = ref(true);
const viewMode = ref("item"); // "item" | "requester" | "records"
const sortBy = ref("time-desc"); // time-desc | time-asc | user-asc | user-desc
// Optional date range ("YYYY-MM-DD"), shared by every tab. Empty = all dates.
const dateFrom = ref("");
const dateTo = ref("");
const currentUser = getCurrentUser();
const isInventoryManagement =
  String(currentUser?.role || "").toLowerCase() === "amo";

// Flattened list of every consumed thing (consumable items + uniforms)
const consumptionRecords = ref([]);

const showAlert = ref(false);
const alertType = ref("info");
const alertMessage = ref("");

function showNotification(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return (
    e?.response?.data?.message ||
    e?.message ||
    "Something went wrong."
  );
}

function formatDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

async function load() {
  loading.value = true;

  try {
    // The server already limits non-AMO users to their own records.
    const rows = await getConsumption();
    consumptionRecords.value = rows.filter((r) => r.date);
  } catch (e) {
    showNotification("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
}

onMounted(load);

// Local calendar day ("YYYY-MM-DD") of a record.
function dayKey(date) {
  const d = new Date(String(date).replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return "";
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

function dayLabel(iso) {
  return new Date(`${iso}T00:00:00`).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

const hasRange = computed(() => !!(dateFrom.value || dateTo.value));
const rangeInvalid = computed(
  () => !!(dateFrom.value && dateTo.value && dateFrom.value > dateTo.value),
);

function clearDates() {
  dateFrom.value = "";
  dateTo.value = "";
}

// Small note shown next to each heading.
const periodNote = computed(() => {
  if (!hasRange.value) return "All dates";
  if (dateFrom.value && dateTo.value)
    return `${dayLabel(dateFrom.value)} to ${dayLabel(dateTo.value)}`;
  if (dateFrom.value) return `From ${dayLabel(dateFrom.value)} onward`;
  return `Up to ${dayLabel(dateTo.value)}`;
});

const emptyText = computed(() =>
  hasRange.value
    ? "No consumption recorded in this date range."
    : "No consumption recorded yet.",
);

const filteredRecords = computed(() => {
  if (rangeInvalid.value) return [];

  return consumptionRecords.value.filter((r) => {
    const day = dayKey(r.date);
    if (!day) return false;
    if (dateFrom.value && day < dateFrom.value) return false;
    if (dateTo.value && day > dateTo.value) return false;
    return true;
  });
});

// ITEM-FOCUSED: group by item/uniform name
const byItem = computed(() => {
  const map = new Map();
  filteredRecords.value.forEach((r) => {
    if (!map.has(r.name)) {
      map.set(r.name, { name: r.name, type: r.type, total: 0, breakdown: [] });
    }
    const entry = map.get(r.name);
    entry.total += r.qty;
    entry.breakdown.push(r);
  });
  return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
});

// REQUESTER-FOCUSED: group by requester
const byRequester = computed(() => {
  const map = new Map();
  filteredRecords.value.forEach((r) => {
    const key = r.requester || "Unknown";
    if (!map.has(key)) {
      map.set(key, { requester: key, total: 0, breakdown: [] });
    }
    const entry = map.get(key);
    entry.total += r.qty;
    entry.breakdown.push(r);
  });
  return [...map.values()].sort((a, b) => a.requester.localeCompare(b.requester));
});

// ALL RECORDS: flat list, sorted by time (everyone) or by user (AMO only).
const sortedRecords = computed(() => {
  const list = [...filteredRecords.value];
  const byTime = (a, b) => new Date(a.date) - new Date(b.date);
  const byUser = (a, b) =>
    String(a.requester || "").localeCompare(String(b.requester || ""));
  switch (sortBy.value) {
    case "time-asc":
      return list.sort(byTime);
    case "user-asc":
      return list.sort((a, b) => byUser(a, b) || byTime(b, a));
    case "user-desc":
      return list.sort((a, b) => byUser(b, a) || byTime(b, a));
    default:
      return list.sort((a, b) => byTime(b, a));
  }
});

const expandedRow = ref(null);
function toggleExpand(key) {
  expandedRow.value = expandedRow.value === key ? null : key;
}
</script>

<template>
  <div>
        <AlertMessage
      v-model:show="showAlert"
      :type="alertType"
      :message="alertMessage"
    />
    <h3 class="mb-1">  {{ isInventoryManagement ? "Monthly Consumption" : "My Monthly Consumption" }}</h3>
    <p class="text-muted mb-4">
       {{ isInventoryManagement
      ? "View monthly consumption of consumable office supplies and uniforms."
      : "View your monthly consumption of consumable office supplies and uniforms."
       }}
    </p>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-end justify-content-between">
          <div class="d-flex gap-2">
            <button
              class="btn"
              :class="viewMode === 'item' ? 'btn-primary' : 'btn-outline-primary'"
              @click="viewMode = 'item'"
            >
              By Item
            </button>
            <button
              class="btn"
              :class="viewMode === 'records' ? 'btn-primary' : 'btn-outline-primary'"
              @click="viewMode = 'records'"
            >
              All Records
            </button>
            <button v-if="isInventoryManagement" class="btn" :class=" viewMode === 'requester'
             ? 'btn-primary' : 'btn-outline-primary'" @click="viewMode = 'requester'">
              By Requester
            </button>
          </div>

          <div v-if="viewMode === 'records'">
            <label class="form-label mb-1">Sort by</label>
            <select class="form-select" v-model="sortBy">
              <option value="time-desc">Time: newest first</option>
              <option value="time-asc">Time: oldest first</option>
              <option v-if="isInventoryManagement" value="user-asc">User: A to Z</option>
              <option v-if="isInventoryManagement" value="user-desc">User: Z to A</option>
            </select>
          </div>

          <div>
            <label class="form-label mb-1">From</label>
            <input
              v-model="dateFrom"
              type="date"
              class="form-control"
              :max="dateTo || undefined"
            />
          </div>

          <div>
            <label class="form-label mb-1">To</label>
            <input
              v-model="dateTo"
              type="date"
              class="form-control"
              :min="dateFrom || undefined"
            />
          </div>

          <div>
            <button
              class="btn btn-outline-secondary"
              :disabled="!dateFrom && !dateTo"
              @click="clearDates"
            >
              Clear dates
            </button>
          </div>
        </div>

        <div v-if="rangeInvalid" class="text-danger small mt-2">
          The "From" date is after the "To" date. Change one of them or clear the dates.
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

    <!-- ALL RECORDS (flat, sortable) -->
    <div v-else-if="viewMode === 'records'" class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">
          {{ isInventoryManagement ? "All records" : "My records" }}
          ({{ sortedRecords.length }})
          <small class="text-muted fs-6 fw-normal ms-1">{{ periodNote }}</small>
        </h5>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th v-if="isInventoryManagement">User</th>
                <th>Item / Uniform</th>
                <th>Type</th>
                <th style="width: 100px">Qty</th>
                <th style="width: 110px">Request</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in sortedRecords" :key="r.key">
                <td>{{ formatDate(r.date) }}</td>
                <td v-if="isInventoryManagement">{{ r.requester }}</td>
                <td>{{ r.name }}</td>
                <td>{{ r.type }}</td>
                <td>{{ r.qty }}</td>
                <td>#{{ r.requestId }}</td>
              </tr>
              <tr v-if="sortedRecords.length === 0">
                <td :colspan="isInventoryManagement ? 6 : 5" class="text-center text-muted py-4">
                  {{ emptyText }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ITEM-FOCUSED VIEW -->
    <div v-else-if="viewMode === 'item'" class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">
          Consumed ({{ byItem.length }} item{{ byItem.length === 1 ? "" : "s" }})
          <small class="text-muted fs-6 fw-normal ms-1">{{ periodNote }}</small>
        </h5>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item / Uniform</th>
                <th>Type</th>
                <th style="width: 140px">Total Qty</th>
                <th style="width: 100px"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="entry in byItem" :key="entry.name">
                <tr>
                  <td>{{ entry.name }}</td>
                  <td>{{ entry.type }}</td>
                  <td>{{ entry.total }}</td>
                  <td>
                    <button
                      class="btn btn-sm btn-secondary text-white"
                      @click="toggleExpand(entry.name)"
                    >
                      {{ expandedRow === entry.name ? "Hide" : "Details" }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedRow === entry.name">
                  <td colspan="4" class="bg-light">
                    <div
                      v-for="b in entry.breakdown"
                      :key="b.key"
                      class="d-flex justify-content-between border-bottom py-1 small"
                    >
                      <span>Req #{{ b.requestId }} — {{ b.requester }}</span>
                      <span>Qty: {{ b.qty }}</span>
                      <span>{{ formatDate(b.date) }}</span>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="byItem.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  {{ emptyText }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- REQUESTER-FOCUSED VIEW -->
    <div v-else class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">
          Requested ({{ byRequester.length }}
          requester{{ byRequester.length === 1 ? "" : "s" }})
          <small class="text-muted fs-6 fw-normal ms-1">{{ periodNote }}</small>
        </h5>
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Requester</th>
                <th style="width: 140px">Total Qty</th>
                <th style="width: 100px"></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="entry in byRequester" :key="entry.requester">
                <tr>
                  <td>{{ entry.requester }}</td>
                  <td>{{ entry.total }}</td>
                  <td>
                    <button
                      class="btn btn-sm btn-secondary text-white"
                      @click="toggleExpand(entry.requester)"
                    >
                      {{ expandedRow === entry.requester ? "Hide" : "Details" }}
                    </button>
                  </td>
                </tr>
                <tr v-if="expandedRow === entry.requester">
                  <td colspan="3" class="bg-light">
                    <div
                      v-for="b in entry.breakdown"
                      :key="b.key"
                      class="d-flex justify-content-between border-bottom py-1 small"
                    >
                      <span>{{ b.name }} ({{ b.type }})</span>
                      <span>Req #{{ b.requestId }} — Qty: {{ b.qty }}</span>
                      <span>{{ formatDate(b.date) }}</span>
                    </div>
                  </td>
                </tr>
              </template>
              <tr v-if="byRequester.length === 0">
                <td colspan="3" class="text-center text-muted py-4">
                  {{ emptyText }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

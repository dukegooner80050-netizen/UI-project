<script setup>
import { ref, computed, onMounted } from "vue";
import { getDisposalRecords } from "../services/disposals";
import AlertMessage from "../components/AlertMessage.vue";

const records = ref([]);
const loading = ref(true);

const search = ref("");
const dateFrom = ref("");
const dateTo = ref("");

const showAlert = ref(false);
const alertType = ref("danger");
const alertMessage = ref("");

async function load() {
  loading.value = true;
  try {
    records.value = await getDisposalRecords();
  } catch (e) {
    alertType.value = "danger";
    alertMessage.value =
      e?.response?.data?.message || "Unable to load disposal records.";
    showAlert.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const rangeInvalid = computed(
  () => !!(dateFrom.value && dateTo.value && dateFrom.value > dateTo.value),
);

const filtered = computed(() => {
  if (rangeInvalid.value) return [];
  const q = search.value.trim().toLowerCase();

  return records.value.filter((r) => {
    if (dateFrom.value && r.disposed_on < dateFrom.value) return false;
    if (dateTo.value && r.disposed_on > dateTo.value) return false;
    if (!q) return true;
    return [r.item_name, r.category, r.method, r.notes, r.disposed_by]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q));
  });
});

const totalUnits = computed(() =>
  filtered.value.reduce((s, r) => s + (Number(r.quantity) || 0), 0),
);

function clearFilters() {
  search.value = "";
  dateFrom.value = "";
  dateTo.value = "";
}

function formatDay(iso) {
  if (!iso) return "-";
  return new Date(`${iso}T00:00:00`).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

async function exportExcel() {
  const XLSX = await import("xlsx");
  const rows = filtered.value.map((r) => ({
    "Date Disposed": r.disposed_on,
    Item: r.item_name,
    Category: r.category || "",
    Quantity: r.quantity,
    Method: r.method,
    Notes: r.notes || "",
    "Disposed By": r.disposed_by || "",
  }));

  const sheet = XLSX.utils.json_to_sheet(rows);
  sheet["!cols"] = [
    { wch: 14 }, { wch: 28 }, { wch: 18 }, { wch: 10 }, { wch: 16 },
    { wch: 36 }, { wch: 20 },
  ];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, sheet, "Disposal Records");
  XLSX.writeFile(
    wb,
    `disposal-records-${new Date().toISOString().slice(0, 10)}.xlsx`,
  );
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <h4 class="mb-1">Disposal Records</h4>
        <p class="text-muted mb-0">
          Every disposal is kept here permanently, even if the item is deleted later.
        </p>
      </div>
      <button
        class="btn btn-success"
        :disabled="!filtered.length"
        @click="exportExcel"
      >
        Export to Excel
      </button>
    </div>

    <!-- FILTERS -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-end gap-3">
          <div class="flex-grow-1" style="min-width: 220px">
            <label class="form-label mb-1" for="dr-search">Search</label>
            <input
              id="dr-search"
              v-model="search"
              type="text"
              class="form-control"
              placeholder="Item, method, notes, or who disposed it"
            />
          </div>
          <div>
            <label class="form-label mb-1" for="dr-from">From</label>
            <input
              id="dr-from"
              v-model="dateFrom"
              type="date"
              class="form-control"
              :max="dateTo || undefined"
            />
          </div>
          <div>
            <label class="form-label mb-1" for="dr-to">To</label>
            <input
              id="dr-to"
              v-model="dateTo"
              type="date"
              class="form-control"
              :min="dateFrom || undefined"
            />
          </div>
          <div>
            <button
              class="btn btn-outline-secondary"
              :disabled="!search && !dateFrom && !dateTo"
              @click="clearFilters"
            >
              Clear filters
            </button>
          </div>
        </div>
        <div v-if="rangeInvalid" class="text-danger small mt-2">
          The "From" date is after the "To" date. Change one of them or clear the dates.
        </div>
      </div>
    </div>

    <!-- SUMMARY -->
    <div class="row g-3 mb-3">
      <div class="col-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="text-muted small">Disposal records</div>
            <div class="fs-3 fw-bold">{{ filtered.length }}</div>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <div class="text-muted small">Units disposed</div>
            <div class="fs-3 fw-bold">{{ totalUnits }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Item</th>
              <th style="width: 80px">Qty</th>
              <th>Method</th>
              <th>Notes</th>
              <th>Disposed by</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filtered" :key="r.id">
              <td class="text-nowrap">{{ formatDay(r.disposed_on) }}</td>
              <td>
                <div class="fw-semibold">{{ r.item_name }}</div>
                <div class="small text-muted">{{ r.category }}</div>
              </td>
              <td>{{ r.quantity }}</td>
              <td><span class="badge bg-secondary">{{ r.method }}</span></td>
              <td class="text-break">{{ r.notes || "—" }}</td>
              <td>{{ r.disposed_by || "—" }}</td>
            </tr>

            <tr v-if="!filtered.length">
              <td colspan="6" class="text-center text-muted py-4">
                {{
                  loading
                    ? "Loading..."
                    : records.length
                      ? "No records match these filters."
                      : "Nothing has been disposed of yet."
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

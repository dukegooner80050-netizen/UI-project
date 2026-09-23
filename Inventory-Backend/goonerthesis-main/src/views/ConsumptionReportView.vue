<script setup>
import { ref, computed, onMounted } from "vue";
import { getRequests } from "../services/requests";

const loading = ref(true);
const viewMode = ref("item"); // "item" | "requester"
const selectedMonth = ref(""); // "YYYY-MM"

// Flattened list of every consumed thing (consumable items + uniforms)
const consumptionRecords = ref([]);

function formatDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

function monthKey(date) {
  const d = new Date(date);
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  return `${year}-${month}`;
}

function monthLabel(key) {
  const [year, month] = key.split("-");
  const d = new Date(Number(year), Number(month) - 1, 1);
  return d.toLocaleDateString("en-PH", { year: "numeric", month: "long" });
}

async function load() {
  loading.value = true;
  try {
    const requests = await getRequests();
    const records = [];

    requests.forEach((request) => {
      // Only things that were actually granted count as "consumed".
      if (request.status !== "Approved" && request.status !== "Returned") return;
      if (!request.borrowedAt) return;

      const month = monthKey(request.borrowedAt);

      // Consumable items (e.g. Bond Paper)
      request.items.forEach((reqItem) => {
        if (reqItem.itemType !== "Consumable") return;

        records.push({
          key: `item-${reqItem.requestItemId}`,
          name: reqItem.itemName,
          type: "Item",
          qty: Number(reqItem.qty) || 0,
          requester: request.requester,
          requestId: request.id,
          date: request.borrowedAt,
          month,
        });
      });

      // Uniforms are always treated as consumed, never returned
      request.uniforms.forEach((u) => {
        records.push({
          key: `uniform-${u.requestUniformId}`,
          name: `${u.uniformName} (${u.department} - ${u.size})`,
          type: "Uniform",
          qty: Number(u.quantity) || 0,
          requester: request.requester,
          requestId: request.id,
          date: request.borrowedAt,
          month,
        });
      });
    });

    consumptionRecords.value = records;

    // Default to the most recent month with data, else current month.
    const months = [...new Set(records.map((r) => r.month))].sort().reverse();
    selectedMonth.value = months[0] || monthKey(new Date());
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const availableMonths = computed(() => {
  const months = new Set(consumptionRecords.value.map((r) => r.month));
  months.add(monthKey(new Date())); // always allow picking the current month
  return [...months].sort().reverse();
});

const filteredRecords = computed(() =>
  consumptionRecords.value.filter((r) => r.month === selectedMonth.value),
);

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

const expandedRow = ref(null);
function toggleExpand(key) {
  expandedRow.value = expandedRow.value === key ? null : key;
}
</script>

<template>
  <div>
    <h3 class="mb-1">Monthly Consumption</h3>
    <p class="text-muted mb-4">
      Consumable office supplies and uniforms requested per month. These are
      treated as consumed, not returned.
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
              :class="viewMode === 'requester' ? 'btn-primary' : 'btn-outline-primary'"
              @click="viewMode = 'requester'"
            >
              By Requester
            </button>
          </div>

          <div>
            <label class="form-label mb-1">Month</label>
            <select class="form-select" v-model="selectedMonth">
              <option v-for="m in availableMonths" :key="m" :value="m">
                {{ monthLabel(m) }}
              </option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

    <!-- ITEM-FOCUSED VIEW -->
    <div v-else-if="viewMode === 'item'" class="card shadow-sm">
      <div class="card-body">
        <h5 class="mb-3">
          Consumed in {{ monthLabel(selectedMonth) }} ({{ byItem.length }} item{{
            byItem.length === 1 ? "" : "s"
          }})
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
                      class="btn btn-sm btn-outline-secondary"
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
                  No consumption recorded for this month.
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
          Requested in {{ monthLabel(selectedMonth) }} ({{ byRequester.length }}
          requester{{ byRequester.length === 1 ? "" : "s" }})
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
                      class="btn btn-sm btn-outline-secondary"
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
                  No consumption recorded for this month.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

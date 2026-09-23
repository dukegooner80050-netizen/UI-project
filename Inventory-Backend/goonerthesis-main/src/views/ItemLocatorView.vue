<script setup>
import { ref, computed, onMounted } from "vue";
import { listInventory } from "../services/inventory";
import { getRequests } from "../services/requests";

const loading = ref(true);
const searchText = ref("");
const filterLocation = ref("");
const filterRoom = ref("");

const LOCATIONS = [
  "SFB.Faculty",
  "SFB.Building 1",
  "SFB.Building 2",
  "SFB.Building 3",
];

const ROOMS = ["Room 201", "Room 202", "Room 203", "N/A"];

// Flattened list: one row per currently-borrowed item instance
const borrowRecords = ref([]);

function formatDate(date) {
  if (!date) return "-";
  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

async function load() {
  loading.value = true;
  try {
    const [inventory, requests] = await Promise.all([
      listInventory(),
      getRequests(),
    ]);

    // Only Office Supplies / School Equipment items that are NOT consumables.
    // (Uniforms are handled separately and are excluded here.)
    const locatableItems = inventory.filter(
      (i) =>
        i.item_type !== "Consumable" &&
        (i.category === "Office Supplies" || i.category === "School Equipment"),
    );
    const locatableIds = new Set(locatableItems.map((i) => i.iditems));

    const records = [];

    requests.forEach((request) => {
      if (request.status !== "Approved") return;

      request.items.forEach((reqItem) => {
        if (!locatableIds.has(reqItem.itemId)) return;
        const borrowedQty = Number(reqItem.borrowedQty) || 0;
        if (borrowedQty <= 0) return;

        records.push({
          key: `${request.id}-${reqItem.requestItemId}`,
          itemName: reqItem.itemName,
          category: reqItem.category,
          quantity: borrowedQty,
          requester: request.requester,
          requestId: request.id,
          location: request.location,
          room: request.room,
          purpose: request.purpose,
          borrowedAt: request.borrowedAt,
        });
      });
    });

    borrowRecords.value = records;
  } finally {
    loading.value = false;
  }
}

onMounted(load);

const filteredRecords = computed(() => {
  const text = searchText.value.trim().toLowerCase();

  return borrowRecords.value
    .filter((r) => (text ? r.itemName?.toLowerCase().includes(text) : true))
    .filter((r) => (filterLocation.value ? r.location === filterLocation.value : true))
    .filter((r) => (filterRoom.value ? r.room === filterRoom.value : true))
    .sort((a, b) => a.itemName.localeCompare(b.itemName));
});

function clearFilters() {
  searchText.value = "";
  filterLocation.value = "";
  filterRoom.value = "";
}
</script>

<template>
  <div>
    <h3 class="mb-1">Item Locator</h3>
    <p class="text-muted mb-4">
      Search by item name or filter by building/room to see where
      currently-borrowed office supplies and school equipment are located.
    </p>

    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-md-5">
            <label class="form-label">Search by item name</label>
            <input
              class="form-control"
              v-model="searchText"
              placeholder="e.g. Projector, Chairs..."
            />
          </div>
          <div class="col-md-3">
            <label class="form-label">Building / Location</label>
            <select class="form-select" v-model="filterLocation">
              <option value="">All locations</option>
              <option v-for="loc in LOCATIONS" :key="loc" :value="loc">
                {{ loc }}
              </option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Room</label>
            <select class="form-select" v-model="filterRoom">
              <option value="">All rooms</option>
              <option v-for="room in ROOMS" :key="room" :value="room">
                {{ room }}
              </option>
            </select>
          </div>
          <div class="col-md-1">
            <button class="btn btn-outline-secondary w-100" @click="clearFilters">
              Clear
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Currently Out ({{ filteredRecords.length }})</h5>
        </div>

        <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

        <div v-else class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Qty Out</th>
                <th>Requester</th>
                <th>Request #</th>
                <th>Location</th>
                <th>Room</th>
                <th>Purpose</th>
                <th>Borrowed On</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredRecords" :key="r.key">
                <td>{{ r.itemName }}</td>
                <td>{{ r.category }}</td>
                <td>{{ r.quantity }}</td>
                <td>{{ r.requester }}</td>
                <td>{{ r.requestId }}</td>
                <td>{{ r.location }}</td>
                <td>{{ r.room }}</td>
                <td>{{ r.purpose }}</td>
                <td>{{ formatDate(r.borrowedAt) }}</td>
              </tr>
              <tr v-if="filteredRecords.length === 0">
                <td colspan="9" class="text-center text-muted py-4">
                  No matching borrowed items found.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

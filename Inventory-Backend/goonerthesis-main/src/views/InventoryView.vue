<script setup>
import { ref, computed, onMounted } from "vue";
import { listInventory, clearAllInventory } from "../services/inventory";

const search = ref("");
const statusFilter = ref("ALL");
const borrowerFilter = ref("ALL");
const viewMode = ref("ALL_ITEMS");
const items = ref([]);

const isBorrowedView = computed(() => viewMode.value === "BORROWED");
const isLocationView = computed(() => viewMode.value === "LOCATIONS");

onMounted(() => {
  items.value = listInventory();
});

const borrowedRows = computed(() => {
  const rows = [];

  items.value.forEach((item) => {
    if (viewMode.value === "LOCATIONS" && item.category !== "School Equipment") {
      return;
    }

    (item.transactions || [])
      .filter((t) => !t.returnedAt)
      .forEach((t) => {
        rows.push({
          id: item.id + "_" + t.borrowedAt,
          name: item.name,
          category: item.category,
          status: "Borrowed",
          qty: t.qty,
          borrower: t.borrower,
          location: t.location,
          date: t.borrowedAt,
        });
      });
  });

  return rows;
});

const filteredItems = computed(() => {
  return items.value.filter((i) => {
    const matchesSearch =
      i.name.toLowerCase().includes(search.value.toLowerCase()) ||
      i.category.toLowerCase().includes(search.value.toLowerCase()) ||
      (i.borrower || "").toLowerCase().includes(search.value.toLowerCase()) ||
      (i.location || "").toLowerCase().includes(search.value.toLowerCase());

    const matchesStatus =
      statusFilter.value === "ALL" || i.status === statusFilter.value;

    let matchesViewMode = true;

    if (viewMode.value === "BORROWED") {
      matchesViewMode = i.status === "Borrowed";
    }

    if (viewMode.value === "LOCATIONS") {
      matchesViewMode = !!i.location;
    }

    return matchesSearch && matchesStatus && matchesViewMode;
  });
});

function clearAll() {
  if (!confirm("Delete all inventory data?")) return;
  clearAllInventory();
  items.value = [];
}
</script>
<template>
  <div>
    <h3 class="mb-4">Inventory (All Items)</h3>

    <!-- Filters -->
    <div class="row mb-3">
      <div class="col-md-6">
        <input
          v-model="search"
          class="form-control"
          placeholder="Search item name or category..."
        />
      </div>

      <div class="col-md-3">
        <select v-model="statusFilter" class="form-select">
          <option value="ALL">All Status</option>
          <option>Available</option>
          <option>Borrowed</option>
          <option>Damaged</option>
          <option>Low Stock</option>
          <option>Out of Stock</option>
        </select>
      </div>

      <div class="col-md-3">
        <select v-model="viewMode" class="form-select">
          <option value="ALL_ITEMS">All Items</option>
          <option value="BORROWED">Borrowed Items</option>
          <option value="LOCATIONS">Item Locations</option>
        </select>
      </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle">
          <thead class="table-light">
<tr>
  <th>Item Name</th>

  <th v-if="!isBorrowedView && !isLocationView">Category</th>
  <th v-if="!isBorrowedView && !isLocationView">Status</th>
  <th v-if="!isBorrowedView && !isLocationView">Qty</th>

  <!-- Borrowed View -->
  <template v-if="isBorrowedView">
    <th>Category</th>
    <th>Borrowed Qty</th>
    <th>Borrower</th>
    <th>Location</th>
    <th>Date</th>
  </template>

  <!-- Location View -->
  <template v-if="isLocationView">
    <th>Borrower</th>
    <th>Location</th>
    <th>Qty</th>
    <th>Date</th>
  </template>
</tr>
          </thead>

          <tbody>
            <!-- NORMAL VIEW -->
<template v-if="!isBorrowedView && !isLocationView">
  <tr v-for="i in filteredItems" :key="i.id">
    <td>{{ i.name }}</td>
    <td>{{ i.category }}</td>
    <td>{{ i.status }}</td>
    <td>{{ i.qty }}</td>
  </tr>
</template>
            <!-- BORROWED VIEW -->
<template v-else-if="isBorrowedView">
  <tr v-for="row in borrowedRows" :key="row.id">
    <td>{{ row.name }}</td>
    <td>{{ row.category }}</td>
    <td>{{ row.qty }}</td>
    <td>{{ row.borrower }}</td>
    <td>{{ row.location }}</td>
    <td>{{ new Date(row.date).toLocaleString() }}</td>
  </tr>
</template>
            <!-- LOCATION VIEW -->
<template v-else-if="isLocationView">
  <tr v-for="row in borrowedRows" :key="row.id">
    <td>{{ row.name }}</td>
    <td>{{ row.borrower }}</td>
    <td>{{ row.location }}</td>
    <td>{{ row.qty }}</td>
    <td>{{ new Date(row.date).toLocaleString() }}</td>
  </tr>
</template>

            <tr v-if="isBorrowedView ? !borrowedRows.length : !filteredItems.length">
              <td colspan="7" class="text-center text-muted">
                No items found
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-3 text-end">
      <button class="btn btn-danger" @click="clearAll">
        Delete Inventory Data
      </button>
    </div>
  </div>
</template>
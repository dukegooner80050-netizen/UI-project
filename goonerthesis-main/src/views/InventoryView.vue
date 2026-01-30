<script setup>
import { ref, computed, onMounted } from "vue"
import { listInventory, clearAllInventory } from "../services/inventory"

const search = ref("")
const statusFilter = ref("ALL")
const items = ref([])

onMounted(() => {
  items.value = listInventory()
})

const filteredItems = computed(() => {
  return items.value.filter(i => {
    const matchesSearch =
      i.name.toLowerCase().includes(search.value.toLowerCase()) ||
      i.category.toLowerCase().includes(search.value.toLowerCase())

    const matchesStatus =
      statusFilter.value === "ALL" || i.status === statusFilter.value

    return matchesSearch && matchesStatus
  })
})

function clearAll() {
  if (!confirm("Delete all inventory data?")) return
  clearAllInventory()
  items.value = []
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
    </div>

    <!-- Table -->
    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Category</th>
              <th>Status</th>
              <th>Qty</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="i in filteredItems" :key="i.id">
              <td>{{ i.name }}</td>
              <td>{{ i.category }}</td>
              <td>{{ i.status }}</td>
              <td>{{ i.qty }}</td>
            </tr>

            <tr v-if="!filteredItems.length">
              <td colspan="4" class="text-center text-muted">
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

<script setup>
import { ref, computed, onMounted, watch } from "vue"
import { useRoute } from "vue-router"
import {
  listInventory,
  addItem,
  autoStatus,
  releaseOfficeConsumables,
  restockOfficeSupplies
} from "../services/inventory"

const route = useRoute()

const items = ref([])
const selectedIds = ref(new Set())

// modal state
const modalOpen = ref(false)
const modalMode = ref("") // "release" | "restock"
const modalQty = ref(1)

onMounted(load)

watch(() => route.query.course, load)

function load() {
  items.value = listInventory().filter(i => i.category === "Uniforms")
  selectedIds.value = new Set()
}

const course = computed(() => route.query.course || "ALL")
const isAllView = computed(() => course.value === "ALL")

const uniforms = computed(() => {
  if (isAllView.value) return items.value
  return items.value.filter(i => i.subCategory === course.value)
})

// selection
function toggle(id, checked) {
  const s = new Set(selectedIds.value)
  checked ? s.add(id) : s.delete(id)
  selectedIds.value = s
}

function toggleAll(checked) {
  const s = new Set()
  if (checked) uniforms.value.forEach(i => s.add(i.id))
  selectedIds.value = s
}

const allChecked = computed(() =>
  uniforms.value.length &&
  selectedIds.value.size === uniforms.value.length
)

// ADD UNIFORM modal state
const addModalOpen = ref(false)
const addName = ref("")
const addQty = ref(1)

function openAddUniform() {
  addName.value = ""
  addQty.value = 1
  addModalOpen.value = true
}

function closeAddUniform() {
  addModalOpen.value = false
}

function confirmAddUniform() {
  const name = addName.value.trim()
  const qty = Number(addQty.value) || 0

  if (!name) {
    alert("Please enter a uniform name.")
    return
  }
  if (qty <= 0) {
    alert("Quantity must be at least 1.")
    return
  }

  try {
    // Build new uniform item
    const newItem = autoStatus({
      name,
      category: "Uniforms",
      subCategory: course.value, // current route.query.course
      qty,
      status: "Available"
    })

    addItem(newItem)

    load()
    closeAddUniform()
  } catch (e) {
    alert(String(e.message || e))
  }
}


const selectedArray = computed(() => Array.from(selectedIds.value))

// modal open
function openModal(mode) {
  if (!selectedArray.value.length) {
    alert("Select at least one uniform first.")
    return
  }

  modalMode.value = mode
  modalQty.value = 1
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

// confirm
function confirmModal() {
  const qty = Number(modalQty.value) || 0
  if (qty <= 0) {
    alert("Quantity must be at least 1.")
    return
  }

  try {
    if (modalMode.value === "release") {
      // release behaves like consumables
      releaseOfficeConsumables(selectedArray.value, qty)
    } else if (modalMode.value === "restock") {
      restockOfficeSupplies(selectedArray.value, qty)
    }

    load()
    closeModal()
  } catch (e) {
    alert(String(e.message || e))
  }
}
</script>

<template>
  <div>
    <h3 class="mb-4">
      {{ isAllView ? "All Uniforms" : course + " Uniforms" }}
    </h3>

    <!-- ACTION BAR -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="text-muted">
        {{ isAllView ? "Release allowed only on main page" : "Restock per subcategory" }}
      </div>

      <div class="d-flex gap-2">
        <!-- Release: ONLY on ALL -->
        <button
          v-if="isAllView"
          class="btn btn-primary"
          @click="openModal('release')"
        >
          Release Selected
        </button>

        <!-- Restock: ONLY on subcategories -->
        <button
          v-if="!isAllView"
          class="btn btn-success"
          @click="openModal('restock')"
        >
          Restock Selected
        </button>

        <button
          v-if="!isAllView"
          class="btn btn-primary"
          @click="openAddUniform"
        >
  + Add Uniform
</button>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:48px">
                <input
                  type="checkbox"
                  :checked="allChecked"
                  @change="toggleAll($event.target.checked)"
                />
              </th>
              <th>Name</th>
              <th>Course</th>
              <th>Status</th>
              <th style="width:120px">Qty</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="u in uniforms" :key="u.id">
              <td>
                <input
                  type="checkbox"
                  :checked="selectedIds.has(u.id)"
                  @change="toggle(u.id, $event.target.checked)"
                />
              </td>
              <td>{{ u.name }}</td>
              <td>{{ u.subCategory }}</td>
              <td>{{ u.status }}</td>
              <td>{{ u.qty }}</td>
            </tr>

            <tr v-if="!uniforms.length">
              <td colspan="5" class="text-center text-muted">
                No uniforms found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- MODAL -->
    <div v-if="modalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ modalMode === "release" ? "Release Selected" : "Restock Selected" }}
          </h5>
          <button class="btn-close" @click="closeModal"></button>
        </div>

        <div class="modal-body">
          <label class="form-label">Quantity</label>
          <input
            type="number"
            min="1"
            class="form-control"
            v-model="modalQty"
          />
        </div>

        <div class="modal-footer">
          <button class="btn btn-warning" @click="closeModal">
            Cancel
          </button>
          <button class="btn btn-primary" @click="confirmModal">
            Confirm
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ADD UNIFORM MODAL -->
<div v-if="addModalOpen" class="modal-backdrop-custom">
  <div class="modal-custom">
    <div class="modal-header">
      <h5 class="mb-0">Add Uniform ({{ course }})</h5>
      <button class="btn-close" @click="closeAddUniform"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">Uniform Name</label>
      <input
        class="form-control mb-3"
        v-model="addName"
        placeholder="e.g. PE Uniform"
      />

      <label class="form-label">Quantity</label>
      <input
        type="number"
        min="1"
        class="form-control"
        v-model="addQty"
      />
    </div>

    <div class="modal-footer">
      <button class="btn btn-warning" @click="closeAddUniform">
        Cancel
      </button>
      <button class="btn btn-primary" @click="confirmAddUniform">
        Add
      </button>
    </div>
  </div>
</div>

</template>

<style scoped>
.table-scroll {
  max-height: 420px;
  overflow-y: auto;
}
.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
}

.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.35);
  display: grid;
  place-items: center;
  z-index: 2000;
}
.modal-custom {
  background: #fff;
  width: 100%;
  max-width: 520px;
  border-radius: 12px;
  overflow: hidden;
}
.modal-header,
.modal-footer {
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-body {
  padding: 16px;
}
</style>

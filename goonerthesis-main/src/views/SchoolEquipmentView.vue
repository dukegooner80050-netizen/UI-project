<script setup>
import { ref, computed, onMounted } from "vue"
import {
  listInventory,
  addItem,
  autoStatus,
  borrowEquipment,
  returnEquipment
} from "../services/inventory"

const items = ref([])
const selectedIds = ref(new Set())

// modal state
const modalOpen = ref(false)
const modalMode = ref("") // "borrow" | "return"
const modalQty = ref(1)
const addOpen = ref(false)
const addName = ref("")
const addQty = ref(1)

function openAdd() {
  addName.value = ""
  addQty.value = 1
  addOpen.value = true
}

function closeAdd() {
  addOpen.value = false
}

function confirmAdd() {
  const name = addName.value.trim()
  const qty = Number(addQty.value) || 0

  if (!name) return alert("Please enter equipment name.")
  if (qty <= 0) return alert("Quantity must be at least 1.")

  try {
    const newItem = autoStatus({
      name,
      category: "School Equipment",
      qty,
      borrowedQty: 0
    })

    addItem(newItem)
    load()
    closeAdd()
  } catch (e) {
    alert(String(e.message || e))
  }
}

onMounted(load)

const modalMax = computed(() => {
  if (!selectedArray.value.length) return 0

  // BORROW: max is the smallest available qty among selected
  if (modalMode.value === "borrow") {
    return Math.min(
      ...selectedArray.value.map(id => {
        const it = equipments.value.find(i => i.id === id)
        return Number(it?.qty) || 0
      })
    )
  }

  // RETURN: max is the smallest borrowedQty among selected (must be > 0)
  if (modalMode.value === "return") {
    const borrowedList = selectedArray.value
      .map(id => {
        const it = equipments.value.find(i => i.id === id)
        return Number(it?.borrowedQty) || 0
      })
      .filter(n => n > 0)

    return borrowedList.length ? Math.min(...borrowedList) : 0
  }

  return 0
})

function load() {
  items.value = listInventory().filter(i => {
    const c = (i.category || "").toLowerCase()
    return c === "school equipment" || c === "school equipments"
  })
  selectedIds.value = new Set()
}

// selection helpers
function toggle(id, checked) {
  const s = new Set(selectedIds.value)
  checked ? s.add(id) : s.delete(id)
  selectedIds.value = s
}

function toggleAll(checked) {
  const s = new Set()
  if (checked) equipments.value.forEach(i => s.add(i.id))
  selectedIds.value = s
}

const equipments = computed(() => items.value)
const selectedArray = computed(() => Array.from(selectedIds.value))

const allChecked = computed(() =>
  equipments.value.length &&
  selectedIds.value.size === equipments.value.length
)

// modal open
function openModal(mode) {
  if (!selectedArray.value.length) {
    alert("Select at least one equipment item.")
    return
  }

  if (mode === "return") {
    const hasBorrowed = selectedArray.value.some(id => {
      const item = equipments.value.find(i => i.id === id)
      return item && (Number(item.borrowedQty) || 0) > 0
    })

    if (!hasBorrowed) {
      alert("Selected items have nothing to return.")
      return
    }
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

  const max = Number(modalMax.value) || 0
  if (max > 0 && qty > max) {
    alert(`Max allowed is ${max}.`)
    modalQty.value = max
    return
  }

  try {
    if (modalMode.value === "borrow") {
      // borrow EACH selected item
      selectedArray.value.forEach(id => borrowEquipment(id, qty))
    } else if (modalMode.value === "return") {
      selectedArray.value.forEach(id => returnEquipment(id, qty))
    }

    load()
    closeModal()
  } catch (e) {
    alert(String(e.message || e))
  }
}
</script>

<template>
    <h3 class="mb-4">School Equipment</h3>

    <!-- ACTION BAR -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="text-muted">
        Borrow and return equipment items
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-primary" @click="openAdd">
          + Add Equipment
        </button>
        <button class="btn btn-warning" @click="openModal('borrow')">
          Borrow Selected
        </button>
        <button class="btn btn-success" @click="openModal('return')">
          Return Selected
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
              <th>Status</th>
              <th style="width:120px">Available</th>
              <th style="width:120px">Borrowed</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="e in equipments" :key="e.id">
              <td>
                <input
                  type="checkbox"
                  :checked="selectedIds.has(e.id)"
                  @change="toggle(e.id, $event.target.checked)"
                />
              </td>
              <td>{{ e.name }}</td>
              <td>{{ e.status }}</td>
              <td>{{ e.qty }}</td>
              <td>{{ Number(e.borrowedQty) || 0 }}</td>
            </tr>

            <tr v-if="!equipments.length">
              <td colspan="5" class="text-center text-muted">
                No school equipment found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

<!-- BORROW / RETURN MODAL -->
<div v-if="modalOpen" class="modal-backdrop-custom">
  <div class="modal-custom">
    <div class="modal-header">
      <h5 class="mb-0">
        {{ modalMode === "borrow" ? "Borrow Selected" : "Return Selected" }}
      </h5>
      <button class="btn-close" @click="closeModal"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">Quantity</label>
      <input
        type="number"
        min="1"
        :max="modalMax || undefined"
        class="form-control"
        v-model="modalQty"
      />
      <small class="text-muted d-block mt-1">
        Max: {{ modalMax || 0 }}
      </small>
    </div>

    <div class="modal-footer">
      <button class="btn btn-secondary" @click="closeModal">
        Cancel
      </button>
      <button
        class="btn"
        :class="modalMode === 'borrow' ? 'btn-warning' : 'btn-success'"
        @click="confirmModal"
      >
        {{ modalMode === "borrow" ? "Borrow" : "Return" }}
      </button>
    </div>
  </div>
</div>

<!-- ADD EQUIPMENT MODAL -->
<div v-if="addOpen" class="modal-backdrop-custom">
  <div class="modal-custom">
    <div class="modal-header">
      <h5 class="mb-0">Add School Equipment</h5>
      <button class="btn-close" @click="closeAdd"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">Name</label>
      <input
        class="form-control mb-3"
        v-model="addName"
        placeholder="e.g. Projector"
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
      <button class="btn btn-warning" @click="closeAdd">
        Cancel
      </button>
      <button class="btn btn-primary" @click="confirmAdd">
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

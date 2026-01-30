<script setup>
import { ref, computed, onMounted } from "vue"
import {
  listInventory,
  addItem,
  autoStatus,
  restockOfficeSupplies,
  releaseOfficeConsumables,
  borrowNonConsumables,
  returnNonConsumables
} from "../services/inventory"

const items = ref([])

// selections
const selectedConsumableIds = ref(new Set())
const selectedNonConsumableIds = ref(new Set())

// modal state
const addOpen = ref(false)
const addSubCategory = ref("Consumables") // default
const addName = ref("")
const addQty = ref(1)

function openAdd() {
  addSubCategory.value = "Consumables"
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

  if (!name) return alert("Please enter item name.")
  if (qty <= 0) return alert("Quantity must be at least 1.")

  try {
    const item = autoStatus({
      name,
      category: "Office Supplies",
      subCategory: addSubCategory.value, // Consumables or Non-Consumables
      qty,
      borrowedQty: 0
    })

    addItem(item)
    refresh() // or load(), whichever your file uses to reload items
    closeAdd()
  } catch (e) {
    alert(String(e.message || e))
  }
}


onMounted(() => {
  refresh()
})

function refresh() {
  items.value = listInventory()
}

// Split into two tables
const officeItems = computed(() =>
  items.value.filter(i => i.category === "Office Supplies")
)

const consumables = computed(() =>
  officeItems.value.filter(i => i.subCategory === "Consumables")
)

const nonConsumables = computed(() =>
  officeItems.value.filter(i => i.subCategory !== "Consumables")
)

// --- selection helpers
function toggleConsumable(id, checked) {
  const s = new Set(selectedConsumableIds.value)
  checked ? s.add(id) : s.delete(id)
  selectedConsumableIds.value = s
}

function toggleNonConsumable(id, checked) {
  const s = new Set(selectedNonConsumableIds.value)
  checked ? s.add(id) : s.delete(id)
  selectedNonConsumableIds.value = s
}

function setAllConsumables(checked) {
  const s = new Set()
  if (checked) consumables.value.forEach(i => s.add(i.id))
  selectedConsumableIds.value = s
}

function setAllNonConsumables(checked) {
  const s = new Set()
  if (checked) nonConsumables.value.forEach(i => s.add(i.id))
  selectedNonConsumableIds.value = s
}

const consumableIdsArray = computed(() => Array.from(selectedConsumableIds.value))
const nonConsumableIdsArray = computed(() => Array.from(selectedNonConsumableIds.value))

const allConsumablesChecked = computed(() => {
  return consumables.value.length > 0 && selectedConsumableIds.value.size === consumables.value.length
})
const allNonConsumablesChecked = computed(() => {
  return nonConsumables.value.length > 0 && selectedNonConsumableIds.value.size === nonConsumables.value.length
})

// --- modal openers
function openQtyModal(mode) {
  // selection guard
  const ids = mode === "restock" || mode === "release" ? consumableIdsArray.value : nonConsumableIdsArray.value
  if (!ids.length) {
    alert("Select at least one item first.")
    return
  }

  // additional guard: return should do nothing if nothing to return
  if (mode === "return") {
    const hasSomethingToReturn = ids.some(id => {
      const item = nonConsumables.value.find(i => i.id === id)
      return item && (Number(item.borrowedQty) || 0) > 0
    })
    if (!hasSomethingToReturn) {
      alert("Selected item(s) have nothing to return.")
      return
    }
  }

  modalMode.value = mode
  modalQty.value = 1
  modalTitle.value =
    mode === "restock" ? "Restock Selected" :
    mode === "release" ? "Release Selected" :
    mode === "borrow"  ? "Borrow Selected" :
    "Return Selected"

  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

// --- action confirm
function confirmModal() {
  const qty = Number(modalQty.value) || 0
  if (qty <= 0) {
    alert("Quantity must be at least 1.")
    return
  }

  try {
    if (modalMode.value === "restock") {
      restockOfficeSupplies(consumableIdsArray.value, qty)
      selectedConsumableIds.value = new Set()
    } else if (modalMode.value === "release") {
      releaseOfficeConsumables(consumableIdsArray.value, qty)
      selectedConsumableIds.value = new Set()
    } else if (modalMode.value === "borrow") {
      borrowNonConsumables(nonConsumableIdsArray.value, qty)
      selectedNonConsumableIds.value = new Set()
    } else if (modalMode.value === "return") {
      returnNonConsumables(nonConsumableIdsArray.value, qty)
      selectedNonConsumableIds.value = new Set()
    }

    refresh()
    closeModal()
  } catch (e) {
    // service throws helpful error codes
    alert(String(e.message || e))
  }
}
</script>

<template>
  <div>
    <h3 class="mb-4">Office Supplies</h3>
<button class="btn btn-primary" @click="openAdd('Consumables')">
  + Add Consumable
</button>
    <!-- CONSUMABLES -->
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h5 class="mb-0">Consumables</h5>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" @click="openQtyModal('release')">
              Release Selected
            </button>
            <button class="btn btn-success" @click="openQtyModal('restock')">
              Restock Selected
            </button>
          </div>
        </div>

        <div class="table-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:48px">
                  <input
                    type="checkbox"
                    :checked="allConsumablesChecked"
                    @change="setAllConsumables($event.target.checked)"
                  />
                </th>
                <th>Name</th>
                <th>Status</th>
                <th style="width:120px">Qty</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="i in consumables" :key="i.id">
                <td>
                  <input
                    type="checkbox"
                    :checked="selectedConsumableIds.has(i.id)"
                    @change="toggleConsumable(i.id, $event.target.checked)"
                  />
                </td>
                <td>{{ i.name }}</td>
                <td>{{ i.status }}</td>
                <td>{{ i.qty }}</td>
              </tr>

              <tr v-if="consumables.length === 0">
                <td colspan="4" class="text-center text-muted">No consumables found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- NON-CONSUMABLES -->
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <h5 class="mb-0">Non-Consumables</h5>

          <div class="d-flex gap-2">
            <button class="btn btn-primary" @click="openQtyModal('borrow')">
              Borrow Selected
            </button>
            <button class="btn btn-secondary" @click="openQtyModal('return')">
              Return Selected
            </button>
          </div>
        </div>

        <div class="table-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:48px">
                  <input
                    type="checkbox"
                    :checked="allNonConsumablesChecked"
                    @change="setAllNonConsumables($event.target.checked)"
                  />
                </th>
                <th>Name</th>
                <th>Status</th>
                <th style="width:120px">Qty</th>
                <th style="width:120px">Borrowed</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="i in nonConsumables" :key="i.id">
                <td>
                  <input
                    type="checkbox"
                    :checked="selectedNonConsumableIds.has(i.id)"
                    @change="toggleNonConsumable(i.id, $event.target.checked)"
                  />
                </td>
                <td>{{ i.name }}</td>
                <td>{{ i.status }}</td>
                <td>{{ i.qty }}</td>
                <td>{{ Number(i.borrowedQty) || 0 }}</td>
              </tr>

              <tr v-if="nonConsumables.length === 0">
                <td colspan="5" class="text-center text-muted">No non-consumables found.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="small text-muted mt-3">
          Tip: Return will do nothing if selected items have no borrowed quantity.
        </div>
      </div>
    </div>

    <!-- Modal (simple Vue modal, no Bootstrap JS needed) -->
    <div v-if="modalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">{{ modalTitle }}</h5>
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
          <div class="small text-muted mt-2">
            This quantity will apply to each selected item (same as your previous modal behavior).
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="closeModal">Cancel</button>
          <button class="btn btn-primary" @click="confirmModal">Confirm</button>
        </div>
      </div>
    </div>
  </div>

 <!-- ADD ITEM MODAL -->
<div v-if="addOpen" class="modal-backdrop-custom">
  <div class="modal-custom">
    <div class="modal-header">
      <h5 class="mb-0">Add Office Supply</h5>
      <button class="btn-close" @click="closeAdd"></button>
    </div>

    <div class="modal-body">
      <label class="form-label">Type</label>
      <select class="form-select mb-3" v-model="addSubCategory">
        <option value="Consumables">Consumables</option>
        <option value="Non-Consumables">Non-Consumables</option>
      </select>

      <label class="form-label">Name</label>
      <input class="form-control mb-3" v-model="addName" placeholder="e.g. Bond Paper" />

      <label class="form-label">Quantity</label>
      <input type="number" min="1" class="form-control" v-model="addQty" />

      <div class="small text-muted mt-2">
        Consumables will auto-update status (Low Stock / Out of Stock) depending on qty.
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-secondary" @click="closeAdd">Cancel</button>
      <button class="btn btn-primary" @click="confirmAdd">Add</button>
    </div>
  </div>
</div>
 
</template>

<style scoped>
/* same "table bar" you wanted everywhere */
.table-scroll {
  max-height: 420px;
  overflow-y: auto;
}
.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
  box-shadow: 0 1px 0 #dee2e6;
}

/* lightweight modal */
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.35);
  display: grid;
  place-items: center;
  z-index: 2000;
  padding: 16px;
}
.modal-custom {
  width: 100%;
  max-width: 520px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 18px 50px rgba(0,0,0,.25);
  overflow: hidden;
}
.modal-header, .modal-footer {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.modal-body {
  padding: 16px;
}
</style>

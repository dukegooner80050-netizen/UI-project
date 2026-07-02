<script setup>
import { ref, watch, computed, onMounted, nextTick } from "vue";
import { useRouter } from "vue-router";
import { createRequestBatch, listRequests } from "../services/requests";
import { getCurrentUser } from "../services/storage";
import { listInventory } from "../services/inventory";

onMounted(() => {
  loadInventory();
  loadMyRequests();
});

const user = getCurrentUser();

const listContainer = ref(null);
const activeTab = ref("create");
const lastReceipt = ref(null);
const myRequests = ref([]);
const selected = ref(null);
const category = ref("");
const itemType = ref("");
const itemName = ref("");
const selectedItemId = ref("");
const qty = ref(1);
const location = ref("");
const room = ref("");
const error = ref("");
const invItems = ref([]);
const requestItems = ref([]);
const requestPurpose = ref("");
const warningModal = ref(false);
const warningTitle = ref("");
const warningMessage = ref("");
const reservedStockMap = computed(() => {
  const map = {};
  const all = listRequests();

  for (const req of all) {
    if ((req.status || "").toLowerCase() !== "pending") continue;

    for (const item of req.items || []) {
      map[item.itemId] = (map[item.itemId] || 0) + item.qty;
    }
  }

  return map;
});

const totalRequestedMap = computed(() => {
  const map = {};

  for (const item of requestItems.value) {
    map[item.itemId] = (map[item.itemId] || 0) + item.qty;
  }

  return map;
});

function loadInventory() {
  invItems.value = listInventory() || [];
}

// Items that can appear in Item Name dropdown (based on category + itemType)
const itemOptions = computed(() => {
  const cat = category.value;
  const type = itemType.value;

  if (!cat || !type) return [];

  return invItems.value
    .filter((i) => (i.category || "") === cat)
    .filter((i) => {
      if (cat === "Office Supplies") {
        // Consumable, Non-Consumable based on subCategory from OfficeSuppliesView
        if (type === "Consumable")
          return (i.subCategory || "") === "Consumables";
        if (type === "Non-Consumable")
          return (i.subCategory || "") === "Non-Consumables";
        return false;
      }

      // School Equipment always Non-Consumable
      if (cat === "School Equipment") {
        return type === "Non-Consumable";
      }

      return false;
    })
    .filter((i) => (Number(i.qty) || 0) > 0);
});

// Resolve selected item from dropdown
const selectedItem = computed(
  () =>
    itemOptions.value.find(
      (i) => String(i.id) === String(selectedItemId.value),
    ) || null,
);

watch(category, (val) => {
  // reload inventory so dropdown options are up to date
  loadInventory();

  // reset selection whenever category changes
  selectedItemId.value = "";
  itemName.value = "";

  if (val === "School Equipment") {
    itemType.value = "Non-Consumable";
  } else if (val === "Office Supplies") {
    itemType.value = "";
  } else {
    itemType.value = "";
  }
});
watch(itemType, () => {
  loadInventory();
  selectedItemId.value = "";
  itemName.value = "";
});

const isItemTypeLocked = computed(() => category.value === "School Equipment");

function loadMyRequests() {
  const all = listRequests();
  const key = (user?.username || "").toLowerCase();
  myRequests.value = all
    .filter((r) => Array.isArray(r.items))
    .filter((r) => r.requester?.toLowerCase() === key)
    .slice()
    .reverse();
}

async function addToList() {
  error.value = "";

  if (!selectedItem.value) {
    error.value = "Please select an item.";
    return;
  }

  const quantity = Number(qty.value);

  if (quantity <= 0) {
    error.value = "Invalid quantity.";
    return;
  }

  if (quantity > maxQty.value) {
    error.value = "Quantity exceeds stock.";
    return;
  }

  // 🔥 CHECK IF ITEM ALREADY EXISTS
  const existing = requestItems.value.find(
    (i) => i.itemId === selectedItem.value.id,
  );

  if (existing) {
    const newQty = existing.qty + quantity;

    if (newQty > maxQty.value) {
      error.value = "Total quantity exceeds stock.";
      return;
    }

    existing.qty = newQty;
  } else {
    requestItems.value.push({
      itemId: selectedItem.value.id,
      itemName: selectedItem.value.name,
      category: category.value,
      itemType: itemType.value,
      qty: quantity,
    });
  }

  // reset only necessary fields
  selectedItemId.value = "";
  qty.value = 1;

  await nextTick();

  if (listContainer.value) {
    listContainer.value.scrollTo({
      top: listContainer.value.scrollHeight,
      behavior: "smooth",
    });
  }
}

function removeItem(index) {
  requestItems.value.splice(index, 1);
}

function showWarning(title, message) {
  warningTitle.value = title;
  warningMessage.value = message;
  warningModal.value = true;
}

function submitRequest() {
  error.value = "";
  if (requestItems.value.length === 0) {
  showWarning(
    "No Items Added",
    "Please add at least one item to the request list before submitting."
  )
  return
}

  for (const item of requestItems.value) {
    const inv = invItems.value.find((i) => i.id === item.itemId);

    if (!inv) {
      error.value = `Item not found: ${item.itemName}`;
      return;
    }

    const stock = Number(inv.qty) || 0;
    const reserved = reservedStockMap.value[item.itemId] || 0;

    if (item.qty > stock - reserved) {
  showWarning(
    "Insufficient Stock",
    `Not enough available stock for ${item.itemName}.`
  )
  return
}
  }

if (!location.value) {
  showWarning(
    "Location Required",
    "Please select a location before submitting your request."
  )
  return
}

if (!room.value) {
  showWarning(
    "Room Required",
    "Please select a room before submitting your request."
  )
  return
}

if (!requestPurpose.value.trim()) {
  showWarning(
    "Purpose Required",
    "Please fill out the purpose field before submitting your request."
  )
  return
}

  const batch = {
    id: Date.now(),
    batchId: "REQ-" + Date.now(),
    date: new Date().toLocaleDateString(),
    requester: user?.username,
    role: user?.role,
    location: room.value ? `${location.value} - ${room.value}` : location.value,
    purpose: requestPurpose.value.trim(),
    status: "Pending",
    createdAt: new Date().toISOString(),

    // 🔥 ALWAYS ARRAY
    items: requestItems.value.map((item) => ({
      itemId: item.itemId,
      itemName: item.itemName,
      category: item.category,
      itemType: item.itemType,
      qty: item.qty,
    })),
  };

  createRequestBatch(batch);

  lastReceipt.value = batch;

  loadMyRequests();
  activeTab.value = "mine";

  // reset
  requestItems.value = [];
  requestPurpose.value = "";
  location.value = "";
  room.value = "";
}

/* HELPERS */
const maxQty = computed(() => {
  if (!selectedItem.value) return 1;

  const stock = Number(selectedItem.value.qty) || 0;

  const reserved = reservedStockMap.value[selectedItem.value.id] || 0;

  const alreadyRequested = totalRequestedMap.value[selectedItem.value.id] || 0;

  return Math.max(stock - reserved - alreadyRequested, 0);
});

const qtyWarning = computed(() => {
  if (!selectedItem.value) return "";

  if (maxQty.value === 0) {
    return "No more stock available for this item.";
  }

  if (Number(qty.value) > maxQty.value) {
    return `Only ${maxQty.value} item(s) left (considering your current list).`;
  }

  return "";
});

watch(activeTab, () => {
  loadInventory();
});
</script>

<template>
  <div class="container-fluid">
    <!-- Tabs -->
    <div class="d-flex gap-2 mb-3">
      <button
        class="btn"
        :class="activeTab === 'create' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'create'"
      >
        Create Request
      </button>

      <button
        class="btn"
        :class="activeTab === 'mine' ? 'btn-primary' : 'btn-outline-primary'"
        @click="
          loadMyRequests();
          activeTab = 'mine';
        "
      >
        My Requests
      </button>
    </div>

    <!-- CREATE REQUEST TAB -->
    <div
      v-if="activeTab === 'create'"
      class="d-flex gap-3 flex-column flex-md-row align-items-stretch"
      style="height: 85vh"
    >
      <!-- Card 1: Request Form -->
      <div
        class="card shadow-sm p-4 d-flex flex-column"
        style="flex: 2; height: 100%"
      >
        <h4 class="mb-4">Request Item</h4>

        <div class="mb-3">
          <label class="form-label">Category</label>
          <select v-model="category" class="form-select">
            <option value="">Select category</option>
            <option value="Office Supplies">Office Supplies</option>
            <option value="School Equipment">School Equipment</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">
            Item Type
            <small class="text-muted">(auto-set for School Equipment)</small>
          </label>
          <select
            v-model="itemType"
            class="form-select"
            :disabled="isItemTypeLocked"
          >
            <option value="">Select item type</option>
            <option value="Consumable">Consumable</option>
            <option value="Non-Consumable">Non-Consumable</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Item Name</label>
          <select
            v-model="selectedItemId"
            class="form-select"
            :disabled="!category || !itemType"
          >
            <option value="">Select item</option>
            <option v-for="i in itemOptions" :key="i.id" :value="String(i.id)">
              {{ i.name }} (Qty: {{ Number(i.qty) || 0 }})
            </option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Quantity</label>
          <input
            type="number"
            min="1"
            :max="maxQty"
            v-model="qty"
            class="form-control"
          />
          <div v-if="qtyWarning" class="text-danger small mt-1">
            {{ qtyWarning }}
          </div>
        </div>

        <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

        <div class="text-end">
          <button
            class="btn btn-success me-2"
            :disabled="!selectedItem || qty <= 0 || qty > maxQty"
            @click="addToList"
          >
            Add Item
          </button>

        </div>
      </div>

      <!-- Card 2: Quick Stats (side by side with form) -->
      <div
        class="card shadow-sm p-4 d-flex flex-column"
        style="flex: 1; height: 100%"
      >
        <h4 class="mb-3">Request List</h4>

        <div class="flex-grow-1 d-flex flex-column">
          <div
            ref="listContainer"
            class="flex-grow-1 overflow-auto border rounded"
            style="min-height: 0"
          >
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th>Item</th>
                  <th>Qty</th>
                  <th style="width: 80px">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="requestItems.length === 0">
                  <td colspan="3" class="text-center text-muted py-4">
                    No items added yet.
                  </td>
                </tr>

                <tr v-for="(item, index) in requestItems" :key="item.itemId">
                  <td>{{ item.itemName }}</td>
                  <td>
                    <span class="badge bg-primary">{{ item.qty }}</span>
                  </td>
                  <td>
                    <button
                      class="btn btn-sm btn-outline-danger"
                      @click="removeItem(index)"
                    >
                      ✕
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="row">
          <div class="col-md-6">
            <label class="form-label">Location</label>
            <select v-model="location" class="form-select">
              <option value="">Select location</option>
              <option>SFB.Faculty</option>
              <option>SFB.Building 1</option>
              <option>SFB.Building 2</option>
              <option>SFB.Building 3</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Room</label>
            <select v-model="room" class="form-select">
              <option value="">Select Room</option>
              <option>Room 201</option>
              <option>Room 202</option>
              <option>Room 203</option>
              <option>N/A</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
  <label class="form-label">Purpose</label>
  <textarea
    v-model="requestPurpose"
    class="form-control"
    rows="3"
    placeholder="Enter purpose of request..."
  ></textarea>
</div>

        <div class="mt-3">
          <button
            class="btn btn-primary w-100"
            :disabled="requestItems.length === 0"
            @click="submitRequest"
          >
            Submit All Requests
          </button>
        </div>
      </div>
    </div>

    <!-- MY REQUESTS + RECEIPT -->
    <div v-else>
      <!-- Receipt -->
      <div v-if="lastReceipt" class="alert alert-success">
        <div class="fw-semibold mb-2">Request Receipt</div>

        <div><strong>Batch ID:</strong> {{ lastReceipt.batchId }}</div>
        <div><strong>Date:</strong> {{ lastReceipt.date }}</div>
        <div><strong>Status:</strong> {{ lastReceipt.status }}</div>
        <div><strong>Location:</strong> {{ lastReceipt.location }}</div>
        <div><strong>Purpose:</strong> {{ lastReceipt.purpose }}</div>

        <hr />

        <div class="fw-semibold mb-2">Items</div>
        <ul class="no-bullets mb-0">
          <li v-for="(item, i) in lastReceipt.items" :key="i">
            {{ item.itemName }} — Qty: {{ item.qty }}
          </li>
        </ul>
      </div>

      <!-- Table + Details Layout -->
      <!-- My Requests Card -->
<div class="d-flex gap-3" style="height: 75vh;">
  
  <!-- LEFT: My Requests -->
  <div class="card shadow-sm d-flex flex-column" style="flex: 2;">
    <div class="card-body d-flex flex-column p-3">
      <h5 class="mb-3">My Requests</h5>

      <div class="table-responsive flex-grow-1" style="overflow-y: auto;">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light sticky-top">
            <tr>
              <th>Items</th>
              <th style="width: 90px;">Qty</th>
              <th>Status</th>
              <th>Date</th>
              <th style="width: 120px">Details</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in myRequests" :key="r.id || r.batchId">
<td>
  <div v-for="(item, i) in r.items" :key="i" class="mb-1">
    {{ item.itemName }}
  </div>
</td>

<td>
  <div v-for="(item, i) in r.items" :key="i" class="mb-1 text-center">
    <span class="">{{ item.qty }}</span>
  </div>
</td>

              <td>
                <span
                  class="badge"
                  :class="{
                    'bg-success': (r.status || '').toLowerCase() === 'approved',
                    'bg-danger': (r.status || '').toLowerCase() === 'rejected',
                    'bg-warning text-dark': !['approved','rejected'].includes((r.status || '').toLowerCase())
                  }"
                >
                  {{ r.status }}
                </span>
              </td>

              <td>{{ r.date }}</td>

              <td>
                <button
                  class="btn btn-sm btn-outline-secondary"
                  @click="selected = r"
                >
                  View
                </button>
              </td>
            </tr>

            <tr v-if="myRequests.length === 0">
              <td colspan="5" class="text-center text-muted py-4">
                No requests yet.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- RIGHT: Details -->
  <div
    class="card shadow-sm d-flex flex-column"
    style="flex: 1;"
  >
    <div class="card-body d-flex flex-column p-3">
      <h5 class="mb-3">Request Details</h5>

      <div v-if="selected" class="flex-grow-1 overflow-auto">
        <div><strong>ID:</strong> {{ selected.id }}</div>

        <div class="mt-2"><strong>Items:</strong></div>
        <ul>
          <li v-for="(item, i) in selected.items" :key="i">
            {{ item.itemName }} ({{ item.qty }})
          </li>
        </ul>

        <div><strong>Purpose:</strong> {{ selected.purpose }}</div>
        <div><strong>Status:</strong> {{ selected.status }}</div>

        <div
          v-if="(selected.status || '').toLowerCase() === 'rejected' && selected.rejectReason"
          class="text-danger mt-2"
        >
          <strong>Rejection Reason:</strong> {{ selected.rejectReason }}
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="text-muted text-center my-auto">
        Select a request to view details
      </div>

      <div class="mt-3 text-end">
        <button
          v-if="selected"
          class="btn btn-sm btn-outline-dark"
          @click="selected = null"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</div>
    </div>
  </div>
  <div v-if="warningModal" class="modal-backdrop-custom">
  <div class="modal-custom">

    <div class="modal-header">
      <h5 class="mb-0">{{ warningTitle }}</h5>
      <button
        class="btn-close"
        @click="warningModal = false"
      ></button>
    </div>

    <div class="modal-body">
      <p class="mb-0">
        {{ warningMessage }}
      </p>
    </div>

    <div class="modal-footer">
      <button
        class="btn btn-primary"
        @click="warningModal = false"
      >
        OK
      </button>
    </div>

  </div>
</div>
</template>

<style scoped>
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
  background: white;
  width: 100%;
  max-width: 500px;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0,0,0,.25);
}

.modal-header,
.modal-footer {
  padding: 12px 16px;
  border-bottom: 1px solid #dee2e6;
}

.modal-footer {
  border-top: 1px solid #dee2e6;
  border-bottom: none;
  display: flex;
  justify-content: flex-end;
}

.modal-body {
  padding: 16px;
}

.card-body {
  overflow-y: auto;
}
.scroll-list {
  max-height: 300px;
  overflow-y: auto;
}
ul {
  list-style: none;
  padding-left: 0;
  margin-bottom: 0;
}
.no-bullets {
  list-style: none;
  padding-left: 0;
  margin-bottom: 0;
}
</style>

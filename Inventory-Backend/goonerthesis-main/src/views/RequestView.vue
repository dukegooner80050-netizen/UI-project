<script setup>
import { ref, watch, computed, onMounted, nextTick } from "vue";
import { createRequestBatch, getRequests } from "../services/requests";
import { listUniformTypes, listUniformVariants } from "../services/uniforms";
import { getCurrentUser } from "../services/storage";
import { getItems } from "../services/items";
import { getMonthlyRequestLimit } from "../services/settings";

const user = getCurrentUser();
const userRole = String(user?.role || "").toLowerCase();
const listContainer = ref(null);
const activeTab = ref("create");
const lastReceipt = ref(null);
const myRequests = ref([]);
const selected = ref(null);
const category = ref("");
const itemType = ref("");
const selectedItemId = ref("");
const qty = ref(1);
const location = ref("");
const room = ref("");
const requestPurpose = ref("");
const error = ref("");
const invItems = ref([]);
const requestItems = ref([]);
const requestUniforms = ref([]);
const uniformTypes = ref([]);
const uniformVariants = ref([]);
const selectedUniformTypeId = ref("");
const selectedUniformVariantId = ref("");
const warningModal = ref(false);
const warningTitle = ref("");
const warningMessage = ref("");

// LOAD DATA //

const monthlyLimit = ref(null);

const requestsThisMonth = computed(() => {
  const now = new Date();
  return myRequests.value.filter((r) => {
    const d = new Date(r.date);
    return (
      d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear()
    );
  }).length;
});

onMounted(async () => {
  await loadInventory();
  await loadUniforms();
  await loadMyRequests();

  if (user?.role !== "admin") {
    try {
      const res = await getMonthlyRequestLimit();
      monthlyLimit.value = res.monthly_request_limit;
    } catch (err) {
      console.error("Failed to load monthly request limit:", err);
    }
  }
});

// INVENTORY //

async function loadInventory() {
  try {
    const data = await getItems();

    invItems.value = data.map((item) => ({
      id: item.id,
      name: item.name,
      category: item.category,
      subCategory: item.subCategory,
      qty: Number(item.qty) || 0,
      status: item.status,
    }));
  } catch (err) {
    console.error("Failed to load inventory:", err);

    showWarning("Inventory Error", "Unable to load inventory.");
  }
}

async function loadUniforms() {
  try {
    const [types, variants] = await Promise.all([
      listUniformTypes(),
      listUniformVariants(),
    ]);
    uniformTypes.value = types;
    uniformVariants.value = variants;
  } catch (err) {
    console.error("Failed to load uniforms:", err);
    showWarning("Uniform Error", "Unable to load uniforms.");
  }
}

// PENDING STOCK RESERVATION //

const reservedStockMap = computed(() => {
  const map = {};
  for (const request of myRequests.value) {
    if (String(request.status || "").toLowerCase() !== "pending") {
      continue;
    }
    for (const item of request.items || []) {
      const itemId = item.itemId;
      map[itemId] = (map[itemId] || 0) + Number(item.qty || 0);
    }
  }

  return map;
});

// CURRENT REQUEST LIST //

const totalRequestedMap = computed(() => {
  const map = {};
  for (const item of requestItems.value) {
    const itemId = item.itemId;
    map[itemId] = (map[itemId] || 0) + Number(item.qty || 0);
  }
  return map;
});

// UNIFORM //
const canRequestUniforms = computed(() => {
  return userRole === "cashier";
});

// ITEM FILTERING //

const itemOptions = computed(() => {
  const cat = category.value;
  const type = itemType.value;
  if (!cat || !type) {
    return [];
  }
  return invItems.value
    .filter((item) => {
      return (item.category || "") === cat;
    })
    .filter((item) => {
      if (cat === "Office Supplies") {
        return (item.subCategory || "") === type;
      }
      if (cat === "School Equipment") {
        return type === "Non-Consumable";
      }
      return false;
    })
    .filter((item) => {
      const stock = Number(item.qty) || 0;
      const reserved = reservedStockMap.value[item.id] || 0;
      const alreadyRequested = totalRequestedMap.value[item.id] || 0;
      return stock - reserved - alreadyRequested > 0;
    });
});
const uniformOptions = computed(() => {
  const typeId = selectedUniformTypeId.value;
  if (!typeId) {
    return [];
  }
  return uniformVariants.value.filter((variant) => {
    return String(variant.idUniftype) === String(typeId);
  });
});

// SELECTED ITEM //

const selectedItem = computed(() => {
  return (
    itemOptions.value.find(
      (item) => String(item.id) === String(selectedItemId.value),
    ) || null
  );
});
const selectedUniformVariant = computed(() => {
  return (
    uniformVariants.value.find(
      (variant) =>
        String(variant.idUnifvariant) ===
        String(selectedUniformVariantId.value),
    ) || null
  );
});

// ITEM TYPE //

const isItemTypeLocked = computed(() => {
  return category.value === "School Equipment";
});

// MAXIMUM QUANTITY //

const maxQty = computed(() => {
  if (!selectedItem.value) {
    return 0;
  }
  const stock = Number(selectedItem.value.qty) || 0;
  const reserved = reservedStockMap.value[selectedItem.value.id] || 0;
  const alreadyRequested = totalRequestedMap.value[selectedItem.value.id] || 0;
  return Math.max(stock - reserved - alreadyRequested, 0);
});

const uniformMaxQty = computed(() => {
  if (!selectedUniformVariant.value) {
    return 0;
  }
  return Number(selectedUniformVariant.value.quantity) || 0;
});
// QUANTITY WARNING //

const qtyWarning = computed(() => {
  if (!selectedItem.value) {
    return "";
  }
  if (maxQty.value <= 0) {
    return "No more stock available for this item.";
  }
  if (Number(qty.value) > maxQty.value) {
    return `Only ${maxQty.value} item(s) available.`;
  }
  return "";
});

// WATCH CATEGORY //

watch(category, async (value) => {
  selectedItemId.value = "";

  if (value === "School Equipment") {
    itemType.value = "Non-Consumable";
  } else {
    itemType.value = "";
  }

  await loadInventory();
});

// WATCH ITEM TYPE //

watch(itemType, async () => {
  selectedItemId.value = "";

  await loadInventory();
});

// FORMAT DATE //

function formatDate(date) {
  if (!date) {
    return "";
  }
  return new Date(date).toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

// LOAD MY REQUESTS //

async function loadMyRequests() {
  try {
    const all = await getRequests();
    myRequests.value = all
      .filter((request) => {
        return Number(request.idUsers) === Number(user.idUsers);
      })
      .map((request) => ({
        id: request.id,
        status: request.status,
        date: request.request_date,
        borrowedAt: request.borrowedAt,
        location: request.location,
        room: request.room,
        purpose: request.purpose,
        rejectReason: request.rejectReason,

        items: (request.items || []).map((item) => ({
          itemId: item.itemId,
          itemName: item.itemName,
          category: item.category,
          itemType: item.itemType,
          qty: Number(item.qty) || 0,
          returnedQty: Number(item.returnedQty) || 0,
          borrowedQty: Number(item.borrowedQty) || 0,
        })),
        uniforms: request.uniforms || [],
      }))
      .sort((a, b) => {
        return new Date(b.date) - new Date(a.date);
      });
  } catch (err) {
    console.error("Failed to load requests:", err);

    showWarning("Request Error", "Unable to load your requests.");
  }
}

// ADD ITEM TO REQUEST LIST //

async function addToList() {
  error.value = "";
  const quantity = Number(qty.value);
  if (!Number.isInteger(quantity) || quantity < 1) {
    error.value = "Quantity must be at least 1.";
    return;
  }

  // UNIFORM //

  if (category.value === "Uniforms") {
    if (!canRequestUniforms.value) {
      error.value = "Only cashiers can request uniforms.";
      return;
    }
    if (!selectedUniformVariant.value) {
      error.value = "Please select a uniform variant.";
      return;
    }
    if (quantity > uniformMaxQty.value) {
      error.value = "Quantity exceeds available uniform stock.";
      return;
    }
    const existing = requestUniforms.value.find(
      (uniform) =>
        String(uniform.uniformVariantId) ===
        String(selectedUniformVariant.value.idUnifvariant),
    );
    if (existing) {
      const newQuantity = Number(existing.quantity) + quantity;
      if (newQuantity > uniformMaxQty.value) {
        error.value = "Total quantity exceeds available uniform stock.";
        return;
      }
      existing.quantity = newQuantity;
    } else {
      requestUniforms.value.push({
        uniformVariantId: selectedUniformVariant.value.idUnifvariant,
        uniformName:
          selectedUniformVariant.value.type?.uniform_name || "Uniform",
        department: selectedUniformVariant.value.department?.dept_name || "N/A",
        size: selectedUniformVariant.value.size,
        price: Number(selectedUniformVariant.value.price) || 0,
        quantity,
      });
    }
  }

  // NORMAL INVENTORY ITEM //
  else {
    if (!selectedItem.value) {
      error.value = "Please select an item.";
      return;
    }
    if (quantity > maxQty.value) {
      error.value = "Quantity exceeds available stock.";
      return;
    }
    const existing = requestItems.value.find(
      (item) => String(item.itemId) === String(selectedItem.value.id),
    );
    if (existing) {
      const newQuantity = Number(existing.qty) + quantity;
      if (newQuantity > maxQty.value) {
        error.value = "Total quantity exceeds available stock.";
        return;
      }
      existing.qty = newQuantity;
    } else {
      requestItems.value.push({
        itemId: selectedItem.value.id,
        itemName: selectedItem.value.name,
        category: category.value,
        itemType: itemType.value,
        qty: quantity,
      });
    }
  }

  // RESET //

  selectedItemId.value = "";
  selectedUniformVariantId.value = "";
  selectedUniformTypeId.value = "";
  qty.value = 1;

  await nextTick();

  if (listContainer.value) {
    listContainer.value.scrollTo({
      top: listContainer.value.scrollHeight,
      behavior: "smooth",
    });
  }
}

// REMOVE ITEM //

function removeItem(index) {
  requestItems.value.splice(index, 1);
}
function removeUniform(index) {
  requestUniforms.value.splice(index, 1);
}

// WARNING MODAL //

function showWarning(title, message) {
  warningTitle.value = title;
  warningMessage.value = message;
  warningModal.value = true;
}

// SUBMIT REQUEST //

async function submitRequest() {
  error.value = "";

  if (requestItems.value.length === 0 && requestUniforms.value.length === 0) {
    showWarning(
      "No Items Added",
      "Please add at least one item or uniform to the request list before submitting.",
    );
    return;
  }
  if (!location.value) {
    showWarning(
      "Location Required",
      "Please select a location before submitting your request.",
    );
    return;
  }
  if (!room.value) {
    showWarning(
      "Room Required",
      "Please select a room before submitting your request.",
    );
    return;
  }
  if (!requestPurpose.value.trim()) {
    showWarning(
      "Purpose Required",
      "Please fill out the purpose field before submitting your request.",
    );
    return;
  }

  // Re-check inventory before submitting //

  await loadInventory();

  for (const requestItem of requestItems.value) {
    const inventoryItem = invItems.value.find(
      (item) => String(item.id) === String(requestItem.itemId),
    );
    if (!inventoryItem) {
      showWarning(
        "Item Not Found",
        `Item "${requestItem.itemName}" could no longer be found in inventory.`,
      );
      return;
    }
    const stock = Number(inventoryItem.qty) || 0;
    const reserved = reservedStockMap.value[requestItem.itemId] || 0;
    const available = stock - reserved;
    if (Number(requestItem.qty) > available) {
      showWarning(
        "Insufficient Stock",
        `Not enough available stock for ${requestItem.itemName}.`,
      );
      return;
    }
  }

  // Re-check uniform inventory before submitting

  if (requestUniforms.value.length > 0) {
    for (const requestUniform of requestUniforms.value) {
      const uniform = uniformVariants.value.find(
        (variant) =>
          String(variant.idUnifvariant) ===
          String(requestUniform.uniformVariantId),
      );

      if (!uniform) {
        showWarning(
          "Uniform Not Found",
          `The requested uniform variant could no longer be found.`,
        );

        return;
      }

      const stock = Number(uniform.quantity) || 0;

      if (Number(requestUniform.quantity) > stock) {
        showWarning(
          "Insufficient Uniform Stock",
          `Not enough stock available for ${requestUniform.uniformName} (${requestUniform.size}).`,
        );

        return;
      }
    }
  }

  // Payload

  const payload = {
    request_date: new Date().toISOString().slice(0, 10),
    location: location.value,
    room: room.value,
    purpose: requestPurpose.value.trim(),
    items: requestItems.value.map((item) => ({
      iditems: item.itemId,
      quantity: Number(item.qty),
    })),
    uniforms: requestUniforms.value.map((uniform) => ({
      idUnifvariant: uniform.uniformVariantId,
      quantity: Number(uniform.quantity),
    })),
  };

  try {
    const response = await createRequestBatch(payload);

    await loadInventory();
    await loadMyRequests();

    if (response?.request) {
      lastReceipt.value = myRequests.value.find(
        (request) => String(request.id) === String(response.request.id),
      );
    }
    activeTab.value = "mine";
    requestItems.value = [];
    requestUniforms.value = [];
    requestPurpose.value = "";
    location.value = "";
    room.value = "";
    category.value = "";
    itemType.value = "";
    selectedItemId.value = "";
    qty.value = 1;
  } catch (err) {
    console.error("Request submission failed:", err);

    showWarning(
      "Request Failed",
      err.response?.data?.message || "Unable to submit request.",
    );
  }
}

// TAB WATCHER //

watch(activeTab, async () => {
  await loadInventory();

  if (activeTab.value === "mine") {
    await loadMyRequests();
  }
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

    <!-- MONTHLY LIMIT INDICATOR -->
    <div
      v-if="monthlyLimit !== null"
      class="alert py-2 px-3 mb-3"
      :class="requestsThisMonth >= monthlyLimit ? 'alert-danger' : 'alert-secondary'"
    >
      You've submitted {{ requestsThisMonth }} of {{ monthlyLimit }} requests
      this month.
      <span v-if="requestsThisMonth >= monthlyLimit">
        You've reached your monthly limit.
      </span>
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
            <option v-if="canRequestUniforms" value="Uniforms">Uniforms</option>
          </select>
        </div>

        <!-- NORMAL INVENTORY REQUEST -->

        <template v-if="category !== 'Uniforms'">
          <!-- Item Type -->
          <div class="mb-3">
            <label class="form-label">
              Item Type
              <small class="text-muted">
                (auto-set for School Equipment)
              </small>
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

          <!-- Item Name -->
          <div class="mb-3">
            <label class="form-label">Item Name</label>
            <select
              v-model="selectedItemId"
              class="form-select"
              :disabled="!category || !itemType"
            >
              <option value="">Select item</option>
              <option
                v-for="i in itemOptions"
                :key="i.id"
                :value="String(i.id)"
              >
                {{ i.name }} (Qty: {{ Number(i.qty) || 0 }})
              </option>
            </select>
          </div>

          <!-- Quantity -->
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
        </template>

        <!-- UNIFORM REQUEST -->

        <template v-else-if="category === 'Uniforms'">
          <!-- Uniform Type -->
          <div class="mb-3">
            <label class="form-label"> Uniform Type </label>

            <select v-model="selectedUniformTypeId" class="form-select">
              <option value="">Select uniform type</option>

              <option
                v-for="type in uniformTypes"
                :key="type.idUniftype"
                :value="String(type.idUniftype)"
              >
                {{ type.uniform_name }}
              </option>
            </select>
          </div>

          <!-- Uniform Variant -->
          <div class="mb-3">
            <label class="form-label"> Uniform Variant </label>

            <select
              v-model="selectedUniformVariantId"
              class="form-select"
              :disabled="!selectedUniformTypeId"
            >
              <option value="">Select uniform variant</option>

              <option
                v-for="variant in uniformOptions"
                :key="variant.idUnifvariant"
                :value="String(variant.idUnifvariant)"
              >
                {{ variant.size }}
                —
                {{ variant.department?.dept_name || "N/A" }}
                (Qty: {{ Number(variant.quantity) || 0 }})
              </option>
            </select>
          </div>

          <!-- Uniform Quantity -->
          <div class="mb-3">
            <label class="form-label"> Quantity </label>
            <input
              type="number"
              min="1"
              :max="uniformMaxQty"
              v-model="qty"
              class="form-control"
            />
            <div
              v-if="selectedUniformVariant && uniformMaxQty <= 0"
              class="text-danger small mt-1"
            >
              No stock available for this uniform.
            </div>

            <div
              v-else-if="selectedUniformVariant && Number(qty) > uniformMaxQty"
              class="text-danger small mt-1"
            >
              Only {{ uniformMaxQty }} uniform(s) available.
            </div>
          </div>
        </template>

        <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
        <div class="text-end">
          <button
            class="btn btn-success me-2"
            :disabled="
              category === 'Uniforms'
                ? !selectedUniformVariant || qty <= 0 || qty > uniformMaxQty
                : !selectedItem || qty <= 0 || qty > maxQty
            "
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
                <!-- INVENTORY ITEMS -->
                <tr
                  v-for="(item, index) in requestItems"
                  :key="'item-' + item.itemId"
                >
                  <td>{{ item.itemName }}</td>

                  <td>
                    <span class="badge bg-primary">
                      {{ item.qty }}
                    </span>
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

                <!-- UNIFORMS -->
                <tr
                  v-for="(uniform, index) in requestUniforms"
                  :key="'uniform-' + uniform.uniformVariantId"
                >
                  <td>
                    <div>
                      <strong>{{ uniform.uniformName }}</strong>
                    </div>

                    <small class="text-muted">
                      {{ uniform.department }}
                      — Size {{ uniform.size }}
                    </small>
                  </td>

                  <td>
                    <span class="badge bg-primary">
                      {{ uniform.quantity }}
                    </span>
                  </td>

                  <td>
                    <button
                      class="btn btn-sm btn-outline-danger"
                      @click="removeUniform(index)"
                    >
                      ✕
                    </button>
                  </td>
                </tr>

                <!-- EMPTY STATE -->
                <tr
                  v-if="
                    requestItems.length === 0 && requestUniforms.length === 0
                  "
                >
                  <td colspan="3" class="text-center text-muted py-4">
                    No items added yet.
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
            :disabled="
              requestItems.length === 0 && requestUniforms.length === 0
            "
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

        <div><strong>Batch ID:</strong> {{ lastReceipt.id }}</div>
        <div><strong>Date:</strong> {{ formatDate(lastReceipt.date) }}</div>
        <div><strong>Status:</strong> {{ lastReceipt.status }}</div>
        <div><strong>Location:</strong> {{ lastReceipt.location }}</div>
        <div><strong>Purpose:</strong> {{ lastReceipt.purpose }}</div>
        <div><strong>Room:</strong> {{ lastReceipt.room }}</div>

        <hr />

        <!-- INVENTORY ITEMS -->
        <div v-if="lastReceipt.items?.length">
          <div class="fw-semibold mb-2">Items</div>
          <ul class="no-bullets mb-3">
            <li v-for="(item, i) in lastReceipt.items" :key="'item-' + i">
              {{ item.itemName }} — Qty: {{ item.qty }}
            </li>
          </ul>
        </div>

        <!-- UNIFORMS -->
        <div v-if="lastReceipt.uniforms?.length">
          <div class="fw-semibold mb-2">Uniforms</div>
          <ul class="no-bullets mb-0">
            <li
              v-for="(uniform, i) in lastReceipt.uniforms"
              :key="'uniform-' + i"
            >
              <strong>{{ uniform.uniformName || "Uniform" }}</strong>
              —
              {{ uniform.department || "N/A" }}
              — Size {{ uniform.size || "N/A" }} — Qty: {{ uniform.quantity }}
            </li>
          </ul>
        </div>
      </div>

      <!-- Table + Details Layout -->
      <!-- My Requests Card -->
      <div class="d-flex gap-3" style="height: 75vh">
        <!-- LEFT: My Requests -->
        <div class="card shadow-sm d-flex flex-column" style="flex: 2">
          <div class="card-body d-flex flex-column p-3">
            <h5 class="mb-3">My Requests</h5>

            <div class="table-responsive flex-grow-1" style="overflow-y: auto">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                  <tr>
                    <th>Requested Items</th>
                    <th style="width: 90px">Qty</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="width: 120px">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in myRequests" :key="r.id || r.batchId">
                    <td>
                      <!-- Inventory Items -->
                      <div
                        v-for="(item, i) in r.items"
                        :key="'item-' + i"
                        class="mb-1"
                      >
                        {{ item.itemName }}
                      </div>

                      <!-- Uniforms -->
                      <div
                        v-for="(uniform, i) in r.uniforms"
                        :key="'uniform-' + i"
                        class="mb-1"
                      >
                        {{ uniform.uniformName || "Uniform" }}
                        <small class="text-muted">
                          ({{ uniform.department || "N/A" }}, Size
                          {{ uniform.size || "N/A" }})
                        </small>
                      </div>
                    </td>
                    <td>
                      <!-- Inventory quantities -->
                      <div
                        v-for="(item, i) in r.items"
                        :key="'item-qty-' + i"
                        class="mb-1 text-center"
                      >
                        {{ item.qty }}
                      </div>

                      <!-- Uniform quantities -->
                      <div
                        v-for="(uniform, i) in r.uniforms"
                        :key="'uniform-qty-' + i"
                        class="mb-1 text-center"
                      >
                        {{ uniform.quantity }}
                      </div>
                    </td>
                    <td>
                      <span
                        class="badge"
                        :class="{
                          'bg-success':
                            (r.status || '').toLowerCase() === 'approved',
                          'bg-danger':
                            (r.status || '').toLowerCase() === 'rejected',
                          'bg-primary':
                            (r.status || '').toLowerCase() === 'returned',
                          'bg-warning text-dark':
                            (r.status || '').toLowerCase() === 'pending',
                        }"
                      >
                        {{ r.status }}
                      </span>
                    </td>
                    <td>{{ formatDate(r.date) }}</td>
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
        <div class="card shadow-sm d-flex flex-column" style="flex: 1">
          <div class="card-body d-flex flex-column p-3">
            <h5 class="mb-3">Request Details</h5>

            <div v-if="selected" class="flex-grow-1 overflow-auto">
              <div><strong>ID:</strong> {{ selected.id }}</div>

              <div class="mt-2"><strong>Requested Items:</strong></div>
              <ul>
                <li v-for="(item, i) in selected.items" :key="'item-' + i">
                  {{ item.itemName }} (Qty: {{ item.qty }})
                </li>
                <li
                  v-for="(uniform, i) in selected.uniforms"
                  :key="'uniform-' + i"
                >
                  {{ uniform.uniformName || "Uniform" }} —
                  {{ uniform.department || "N/A" }} — Size
                  {{ uniform.size || "N/A" }} (Qty: {{ uniform.quantity }})
                </li>
              </ul>
              <div><strong>Location:</strong> {{ selected.location }}</div>
              <div><strong>Room:</strong> {{ selected.room }}</div>
              <div><strong>Purpose:</strong> {{ selected.purpose }}</div>
              <div><strong>Status:</strong> {{ selected.status }}</div>
              <div>
                <strong>Request Date:</strong> {{ formatDate(selected.date) }}
              </div>
              <div v-if="selected.borrowedAt">
                <strong>Borrowed On:</strong>
                {{ new Date(selected.borrowedAt).toLocaleString("en-PH") }}
              </div>

              <div
                v-if="
                  (selected.status || '').toLowerCase() === 'rejected' &&
                  selected.rejectReason
                "
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
        <button class="btn-close" @click="warningModal = false"></button>
      </div>

      <div class="modal-body">
        <p class="mb-0">
          {{ warningMessage }}
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary" @click="warningModal = false">
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
  background: rgba(0, 0, 0, 0.35);
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
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
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

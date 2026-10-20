<script setup>
import { ref, computed, onMounted } from "vue";
import {
  getItems,
  createItem,
  updateItem,
  restockItem,
} from "../services/items";
import { getRequests, returnEquipment } from "../services/requests";
import AlertMessage from "../components/AlertMessage.vue";
import StockBadge from "../components/StockBadge.vue";
import DueBadge from "../components/DueBadge.vue";
import StockAdjustmentModal from "../components/StockAdjustmentModal.vue";
import { getErrorMessage } from "../services/errors";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

// STATES
const items = ref([]);
const currentItem = ref(null);
const modalError = ref("");
const addError = ref("");
const activeTab = ref("inventory");
const selectedEquipment = ref(null);

// MODAL STATE
const modalOpen = ref(false);
const modalMode = ref("");
const modalQty = ref(1);
const editName = ref("");
const addOpen = ref(false);
const addName = ref("");
const addQty = ref(1);
const addCritical = ref(2);
const addMinimum = ref(5);
const addOptimal = ref(20);
// Return period in days; blank = category default (Settings page).
const addReturnDays = ref("");
const editReturnDays = ref("");

function returnDaysProblem(value) {
  if (value === "" || value === null || value === undefined) return "";
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1 || n > 3650) {
    return "Return period must be a whole number of days (1 to 3650), or left blank.";
  }
  return "";
}

// Per-head limit (roadmap #9): max of this item one requester may hold
// at once, counting what they already have pending/approved. Blank = no
// limit.
const addPerHeadLimit = ref("");
const editPerHeadLimit = ref("");

function perHeadLimitProblem(value) {
  if (value === "" || value === null || value === undefined) return "";
  const n = Number(value);
  if (!Number.isInteger(n) || n < 1) {
    return "Per-head limit must be a whole number of 1 or more, or left blank.";
  }
  return "";
}

// Fixed asset split (roadmap #10): a fixed asset (e.g. a room's one
// projector or aircon) can't carry a per-head limit at all - there's
// nothing to "limit" about a single unique unit. Defaults to limitable
// (true) for ordinary, stockable School Equipment.
const addIsLimitable = ref(true);
const editIsLimitable = ref(true);

// Keep the UI consistent with the backend rule: a fixed asset can't have
// a per-head limit, so clear it the moment the box is unchecked instead
// of letting the admin hit the server error.
function onAddLimitableChange() {
  if (!addIsLimitable.value) addPerHeadLimit.value = "";
}
function onEditLimitableChange() {
  if (!editIsLimitable.value) editPerHeadLimit.value = "";
}
const editCritical = ref(2);
const editMinimum = ref(5);
const editOptimal = ref(20);
const confirmAction = ref(false);

// Stock levels must be whole numbers with Critical <= Minimum <= Optimal.
function thresholdProblem(critical, minimum, optimal) {
  const [c, m, o] = [critical, minimum, optimal].map(Number);
  if ([c, m, o].some((n) => !Number.isInteger(n) || n < 0)) {
    return "Stock levels must be whole numbers, 0 or higher.";
  }
  if (c > m || m > o) {
    return "Stock levels must satisfy: Critical <= Minimum <= Optimal.";
  }
  return "";
}

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

function openAdd() {
  addName.value = "";
  addQty.value = 1;
  addCritical.value = 2;
  addMinimum.value = 5;
  addOptimal.value = 20;
  addReturnDays.value = "";
  addPerHeadLimit.value = "";
  addIsLimitable.value = true;
  addError.value = "";
  addOpen.value = true;
}

function closeAdd() {
  addOpen.value = false;
}

async function confirmAdd() {
  const name = addName.value.trim();
  const qty = Number(addQty.value) || 0;

  addError.value = "";
  if (!name) {
    addError.value = "Please enter equipment name.";
    return;
  }
  if (qty <= 0) {
    addError.value = "Quantity must be at least 1.";
    return;
  }
  const problem = thresholdProblem(
    addCritical.value,
    addMinimum.value,
    addOptimal.value,
  );
  if (problem) {
    addError.value = problem;
    return;
  }
  const daysProblem = returnDaysProblem(addReturnDays.value);
  if (daysProblem) {
    addError.value = daysProblem;
    return;
  }
  const limitProblem = perHeadLimitProblem(addPerHeadLimit.value);
  if (limitProblem) {
    addError.value = limitProblem;
    return;
  }
  if (!addIsLimitable.value && addPerHeadLimit.value !== "") {
    addError.value = "Fixed-asset items cannot have a per-head limit.";
    return;
  }

  try {
    await createItem({
      item_name: name,
      category: "School Equipment",
      item_type: "Equipment",
      quantity: qty,
      critical_stock: Number(addCritical.value),
      minimum_stock: Number(addMinimum.value),
      optimal_stock: Number(addOptimal.value),
      return_days: addReturnDays.value !== "" ? Number(addReturnDays.value) : null,
      per_head_limit: addPerHeadLimit.value !== "" ? Number(addPerHeadLimit.value) : null,
      is_limitable: addIsLimitable.value,
    });

    await load();
    closeAdd();
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }
}

onMounted(load);

const modalMax = computed(() => {
  if (!currentItem.value) return 0;

  // if (modalMode.value === "borrow") {
  //   return Number(currentItem.value.qty) || 0;
  // }

  if (modalMode.value === "return") {
    return Number(currentItem.value.borrowedQty) || 0;
  }

  return 0;
});

// Report Missing (roadmap #11)
const adjustOpen = ref(false);
const adjustItem = ref(null);

function openAdjust(item) {
  adjustItem.value = item;
  adjustOpen.value = true;
}

async function onAdjusted() {
  notify("success", `Stock adjusted for ${adjustItem.value?.name}.`);
  await load();
}

async function load() {
  const [inventory, requests] = await Promise.all([getItems(), getRequests()]);
  const equipments = inventory
    .filter((i) => i.category === "School Equipment")
    .map((i) => ({
      ...i,
      id: i.id,
      name: i.name,
      qty: i.qty,
      borrowedQty: 0,
      requestItems: [],
    }));
  requests.forEach((request) => {
    if (request.status !== "Approved") return;
    request.items.forEach((reqItem) => {
      const equipment = equipments.find((e) => e.id === reqItem.itemId);
      if (!equipment) return;
      equipment.borrowedQty += Number(reqItem.borrowedQty);
      equipment.requestItems.push({
        requestItemId: reqItem.requestItemId,
        requester: request.requester,
        requestId: request.id,
        location: request.location,
        room: request.room,
        borrowedQty: reqItem.borrowedQty,
        returnedQty: reqItem.returnedQty,
        qty: reqItem.qty,
        borrowedAt: request.borrowedAt,
        dueDate: reqItem.dueDate,
        overdue: !!reqItem.overdue,
        daysOverdue: reqItem.daysOverdue || 0,
        pendingReturnQty: reqItem.pendingReturnQty || 0,
      });
    });
  });
  items.value = equipments;

  // Keep the Borrower Details panel in sync with live data. load()
  // rebuilds brand-new item objects each time (e.g. right after a
  // partial return), so without this the panel would keep showing
  // the old borrowedQty/requestItems instead of the new ones.
  if (selectedEquipment.value) {
    const updated = equipments.find(
      (e) => e.id === selectedEquipment.value.id,
    );
    selectedEquipment.value =
      updated && Number(updated.borrowedQty) > 0 ? updated : null;
  }
}

const equipments = computed(() => items.value);
const borrowedEquipments = computed(() => {
  return items.value
    .filter((item) => Number(item.borrowedQty) > 0)
    .sort((a, b) => a.name.localeCompare(b.name));
});

const equipmentBorrowers = computed(() => {
  return selectedEquipment.value?.requestItems || [];
});

function toggleEquipmentDetails(equipment) {
  selectedEquipment.value =
    selectedEquipment.value?.id === equipment.id ? null : equipment;
}

function openModal(mode, item) {
  modalMode.value = mode;
  currentItem.value = item;
  modalQty.value = 1;
  editName.value = item.name;
  editCritical.value = item.critical_stock ?? 2;
  editMinimum.value = item.minimum_stock ?? 5;
  editOptimal.value = item.optimal_stock ?? 20;
  editReturnDays.value = item.return_days ?? "";
  editPerHeadLimit.value = item.per_head_limit ?? "";
  editIsLimitable.value = item.is_limitable ?? true;
  modalError.value = "";
  confirmAction.value = false;
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  confirmAction.value = false;
}

async function confirmModal() {
  modalError.value = "";
  try {
    if (modalMode.value === "return") {
      const qty = Number(modalQty.value) || 0;

      if (qty <= 0) {
        modalError.value = "Quantity must be at least 1.";
        return;
      }
      if (qty > modalMax.value) {
        modalError.value = `Max allowed is ${modalMax.value}.`;
        return;
      }
      await returnEquipment(
        currentItem.value.requestItems[0].requestItemId,
        qty,
      );
    } else if (modalMode.value === "restock") {
      const qty = Number(modalQty.value);

      if (qty <= 0) {
        modalError.value = "Quantity must be at least 1.";
        return;
      }
      await restockItem(currentItem.value.id, qty);
    } else if (modalMode.value === "edit") {
      if (!editName.value.trim()) {
        modalError.value = "Equipment name cannot be empty.";
        return;
      }
      const problem = thresholdProblem(
        editCritical.value,
        editMinimum.value,
        editOptimal.value,
      );
      if (problem) {
        modalError.value = problem;
        confirmAction.value = false;
        return;
      }
      const daysProblem = returnDaysProblem(editReturnDays.value);
      if (daysProblem) {
        modalError.value = daysProblem;
        confirmAction.value = false;
        return;
      }
      const limitProblem = perHeadLimitProblem(editPerHeadLimit.value);
      if (limitProblem) {
        modalError.value = limitProblem;
        confirmAction.value = false;
        return;
      }
      if (!editIsLimitable.value && editPerHeadLimit.value !== "") {
        modalError.value = "Fixed-asset items cannot have a per-head limit.";
        confirmAction.value = false;
        return;
      }
      await updateItem(currentItem.value.id, {
        item_name: editName.value,
        description: currentItem.value.description,
        category: currentItem.value.category,
        item_type: currentItem.value.item_type,
        quantity: currentItem.value.qty,
        critical_stock: Number(editCritical.value),
        minimum_stock: Number(editMinimum.value),
        optimal_stock: Number(editOptimal.value),
        return_days: editReturnDays.value !== "" ? Number(editReturnDays.value) : null,
        per_head_limit: editPerHeadLimit.value !== "" ? Number(editPerHeadLimit.value) : null,
        is_limitable: editIsLimitable.value,
      });
    }
    await load();
    closeModal();
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }
}
</script>

<template>
  <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />
  <h3 class="mb-4">School Equipment</h3>

  <!-- ACTION BAR -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <!-- LEFT -->
    <div class="d-flex gap-2">
      <button
        class="btn"
        :class="
          activeTab === 'inventory' ? 'btn-primary' : 'btn-outline-primary'
        "
        @click="activeTab = 'inventory'"
      >
        Equipment Inventory
      </button>
      <button
        class="btn"
        :class="
          activeTab === 'borrowed' ? 'btn-primary' : 'btn-outline-primary'
        "
        @click="activeTab = 'borrowed'"
      >
        Borrowed Equipment
      </button>
    </div>
    <!-- RIGHT -->
    <button
      v-if="activeTab === 'inventory'"
      class="btn btn-primary"
      @click="openAdd"
    >
      + Add Equipment
    </button>
  </div>

  <!-- TABLE -->
  <div v-if="activeTab === 'inventory'">
    <div class="card shadow-sm">
      <div class="table-responsive table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Name</th>
              <th>Status</th>
              <th>Stock Level</th>
              <th style="width: 120px">Available</th>
              <th style="width: 120px">Borrowed</th>
              <th>Return Period</th>
              <th style="width: 110px">Per-Head Limit</th>
              <th style="width: 110px">Type</th>
              <th style="width: 290px; text-align: center">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="e in equipments" :key="e.iditems">
              <td>{{ e.name }}</td>
              <td>{{ e.status }}</td>
              <td>
                <StockBadge
                  :level="e.stock_level"
                  :title="`Critical ≤ ${e.critical_stock} · Minimum ≤ ${e.minimum_stock} · Optimal ≥ ${e.optimal_stock}`"
                />
              </td>
              <td>{{ e.qty }}</td>
              <td>{{ Number(e.borrowedQty) || 0 }}</td>
              <td>
                <span v-if="e.return_days">{{ e.return_days }} days</span>
                <span v-else-if="e.effective_return_days" class="text-muted">
                  {{ e.effective_return_days }} days (default)
                </span>
                <span v-else class="text-muted">None</span>
              </td>
              <td>
                <span v-if="e.is_limitable === false" class="text-muted">—</span>
                <span v-else>{{ e.per_head_limit ?? "No limit" }}</span>
              </td>
              <td>
                <span v-if="e.is_limitable === false" class="badge bg-secondary">
                  Fixed Asset
                </span>
                <span v-else class="badge bg-light text-dark border">
                  Limitable
                </span>
              </td>
              <td class="text-center">
                <div class="d-flex justify-content-center gap-2">
                  <button
                    class="btn btn-success btn-sm"
                    @click="openModal('restock', e)"
                  >
                    Restock
                  </button>
                  <button
                    class="btn btn-warning btn-sm"
                    @click="openModal('edit', e)"
                  >
                    Edit
                  </button>
                  <button
                    class="btn btn-danger btn-sm"
                    title="Record missing, lost or stolen stock"
                    @click="openAdjust(e)"
                  >
                    Missing
                  </button>
                </div>
              </td>
            </tr>

            <tr v-if="!equipments.length">
              <td colspan="9" class="text-center text-muted">
                No school equipment found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div v-else class="d-flex gap-3" style="height: 75vh">
    <!-- LEFT CARD -->
    <div class="card shadow-sm">
      <div class="table-responsive table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Equipment</th>
              <th>Status</th>
              <th>Borrowed</th>
              <th>Overdue</th>
              <th style="width: 160px">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="equipment in borrowedEquipments" :key="equipment.id">
              <td>{{ equipment.name }}</td>
              <td>{{ equipment.status }}</td>
              <td>{{ equipment.borrowedQty }}</td>
              <td>
                <span
                  v-if="equipment.requestItems.some((r) => r.overdue)"
                  class="badge bg-danger"
                >
                  {{ equipment.requestItems.filter((r) => r.overdue).length }} overdue
                </span>
                <span v-else class="text-muted">-</span>
              </td>
              <td>
                <button
                  class="btn btn-sm"
                  :class="selectedEquipment?.id === equipment.id ? 'btn-outline-success' : 'btn-success'"
                  @click="toggleEquipmentDetails(equipment)"
                >
                  {{ selectedEquipment?.id === equipment.id ? "Hide Borrowers" : "View Borrowers" }}
                </button>
              </td>
            </tr>
            <tr v-if="borrowedEquipments.length === 0">
              <td colspan="5" class="text-center text-muted py-4">
                No borrowed equipment.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <!-- RIGHT CARD -->
    <div class="card shadow-sm" style="flex: 1">
      <div class="card-body borrower-card-body d-flex flex-column p-3">
        <h5 class="mb-3">Borrower Details</h5>
        <div v-if="selectedEquipment" class="flex-grow-1 overflow-auto">
          <div
            v-for="borrower in equipmentBorrowers"
            :key="borrower.requestItemId"
            class="border rounded p-3 mb-3"
          >
            <div>
              <strong>Requester:</strong>
              {{ borrower.requester }}
            </div>
            <div>
              <strong>Request #:</strong>
              {{ borrower.requestId }}
            </div>
            <div>
              <strong>Location:</strong>
              {{ borrower.location }}
            </div>
            <div>
              <strong>Room:</strong>
              {{ borrower.room }}
            </div>
            <div>
              <strong>Borrowed On:</strong>
              {{ formatDate(borrower.borrowedAt) }}
            </div>
            <div>
              <strong>Borrowed:</strong>
              {{ borrower.borrowedQty }}
            </div>
            <div>
              <strong>Return by:</strong>
              <DueBadge
                :due-date="borrower.dueDate || ''"
                :overdue="borrower.overdue"
                :days-overdue="borrower.daysOverdue"
              />
            </div>
            <div v-if="borrower.pendingReturnQty > 0">
              <strong>Return reported:</strong>
              <span class="badge bg-warning text-dark">
                {{ borrower.pendingReturnQty }} awaiting your check
              </span>
            </div>
            <div>
              <strong>Returned:</strong>
              {{ borrower.returnedQty }}
            </div>
            <button
              class="btn btn-success mt-3"
              @click="
                openModal('return', {
                  item_name: selectedEquipment.name,
                  borrowedQty: borrower.borrowedQty,
                  requestItems: [borrower],
                })
              "
            >
              Return
            </button>
          </div>
        </div>
        <div v-else class="text-center text-muted my-auto">
          Select an equipment to view borrowers.
        </div>
      </div>
    </div>
  </div>

  <!-- RETURN MODAL -->
  <div v-if="modalOpen" class="modal-backdrop-custom">
    <div class="modal-custom">
      <div class="modal-header">
        <h5 class="mb-0">
          {{
            modalMode === "restock"
              ? `Restock: ${currentItem?.name}`
              : modalMode === "edit"
                ? `Edit: ${currentItem?.name}`
                : `Return: ${currentItem?.item_name || currentItem?.name}`
          }}
        </h5>
        <button class="btn-close" @click="closeModal"></button>
      </div>

      <div class="modal-body">
        <template v-if="!confirmAction">
          <!-- EDIT -->
          <template v-if="modalMode === 'edit'">
            <label class="form-label"> Equipment Name </label>

            <input class="form-control" v-model="editName" />

        <label class="form-label mt-3">Stock Levels</label>
        <div class="row g-2">
          <div class="col">
            <small class="text-muted">Critical (at or below)</small>
            <input type="number" min="0" class="form-control" v-model="editCritical" />
          </div>
          <div class="col">
            <small class="text-muted">Minimum (at or below)</small>
            <input type="number" min="0" class="form-control" v-model="editMinimum" />
          </div>
          <div class="col">
            <small class="text-muted">Optimal (at or above)</small>
            <input type="number" min="0" class="form-control" v-model="editOptimal" />
          </div>
        </div>

        <label class="form-label mt-3">Return Period (days)</label>
        <input
          type="number"
          min="1"
          class="form-control"
          v-model="editReturnDays"
          placeholder="Blank = category default"
        />
        <small class="text-muted d-block">
          How long a borrower may keep this equipment before it is overdue.
        </small>

        <div class="form-check mt-3">
          <input
            type="checkbox"
            class="form-check-input"
            id="editIsLimitable"
            v-model="editIsLimitable"
            @change="onEditLimitableChange"
          />
          <label class="form-check-label" for="editIsLimitable">
            Limitable (ordinary, stockable equipment)
          </label>
        </div>
        <small class="text-muted d-block mb-2">
          Uncheck this for a fixed asset (e.g. a room's one projector or
          aircon) - unique equipment like that can't carry a per-head limit.
        </small>

        <label class="form-label mt-2">Per-Head Limit</label>
        <input
          type="number"
          min="1"
          class="form-control"
          v-model="editPerHeadLimit"
          placeholder="Blank = no limit"
          :disabled="!editIsLimitable"
        />
        <small class="text-muted d-block">
          Max a single requester may hold/have pending of this item at once.
        </small>
          </template>

          <!-- RESTOCK / RETURN -->
          <template v-else>
            <!-- RESTOCK -->
            <template v-if="modalMode === 'restock'">
              <div class="mb-3">
                <label class="form-label"> Current Stock </label>
                <input class="form-control" :value="currentItem.qty" disabled />
              </div>

              <label class="form-label"> Restock Quantity </label>
              <input
                type="number"
                min="1"
                class="form-control"
                v-model="modalQty"
              />
            </template>

            <!-- RETURN -->
            <template v-else>
              <label class="form-label"> Return Quantity </label>
              <input
                type="number"
                min="1"
                :max="modalMax"
                class="form-control"
                v-model="modalQty"
              />
              <small class="text-muted"> Max: {{ modalMax }} </small>
            </template>
          </template>
        </template>

        <!-- CONFIRMATION SCREEN -->
        <template v-else>
          <div class="alert alert-warning mb-0">
            <h6 class="mb-3">Confirm Action</h6>
            <div v-if="modalMode === 'restock'">
              Restock
              <strong class="highlight">{{ currentItem.name }}</strong>
              by
              <strong class="highlight">{{ modalQty }}</strong>
              item(s)?
            </div>
            <div v-if="modalMode === 'edit'">
              Save changes to
              <strong class="highlight">{{ currentItem.name }}</strong>
              (name: <strong class="highlight">{{ editName }}</strong>, Critical
              ≤ {{ editCritical }}, Minimum ≤ {{ editMinimum }}, Optimal ≥
              {{ editOptimal }}, Per-Head Limit:
              {{
                editIsLimitable
                  ? (editPerHeadLimit === "" ? "No limit" : editPerHeadLimit)
                  : "Fixed Asset (not limitable)"
              }})?
            </div>
            <div v-if="modalMode === 'return'">
              Return
              <strong class="highlight">{{ modalQty }}</strong>
              item(s) of
              <strong class="highlight">{{
                currentItem.item_name || currentItem.name
              }}</strong
              >?
            </div>
          </div>
        </template>
      </div>

      <div class="modal-footer">
        <button
          class="btn btn-secondary"
          @click="confirmAction ? (confirmAction = false) : closeModal()"
        >
          {{ confirmAction ? "Back" : "Cancel" }}
        </button>

        <!-- FIRST CLICK -->
        <button
          v-if="!confirmAction"
          class="btn btn-primary"
          :disabled="
            (modalMode === 'edit' && !editName.trim()) ||
            (modalMode !== 'edit' && modalQty <= 0)
          "
          @click="confirmAction = true"
        >
          Continue
        </button>

        <!-- SECOND CLICK -->
        <button v-else class="btn btn-success" @click="confirmModal">
          Confirm
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
        <input type="number" min="1" class="form-control" v-model="addQty" />

        <label class="form-label mt-3">Stock Levels</label>
        <div class="row g-2">
          <div class="col">
            <small class="text-muted">Critical (at or below)</small>
            <input type="number" min="0" class="form-control" v-model="addCritical" />
          </div>
          <div class="col">
            <small class="text-muted">Minimum (at or below)</small>
            <input type="number" min="0" class="form-control" v-model="addMinimum" />
          </div>
          <div class="col">
            <small class="text-muted">Optimal (at or above)</small>
            <input type="number" min="0" class="form-control" v-model="addOptimal" />
          </div>
        </div>

        <label class="form-label mt-3">Return Period (days)</label>
        <input
          type="number"
          min="1"
          class="form-control"
          v-model="addReturnDays"
          placeholder="Blank = category default"
        />
        <small class="text-muted d-block">
          How long a borrower may keep this equipment before it is overdue.
        </small>

        <div class="form-check mt-3">
          <input
            type="checkbox"
            class="form-check-input"
            id="addIsLimitable"
            v-model="addIsLimitable"
            @change="onAddLimitableChange"
          />
          <label class="form-check-label" for="addIsLimitable">
            Limitable (ordinary, stockable equipment)
          </label>
        </div>
        <small class="text-muted d-block mb-2">
          Uncheck this for a fixed asset (e.g. a room's one projector or
          aircon) - unique equipment like that can't carry a per-head limit.
        </small>

        <label class="form-label mt-2">Per-Head Limit</label>
        <input
          type="number"
          min="1"
          class="form-control"
          v-model="addPerHeadLimit"
          placeholder="Blank = no limit"
          :disabled="!addIsLimitable"
        />
        <small class="text-muted d-block">
          Max a single requester may hold/have pending of this item at once.
        </small>
      </div>
      <div v-if="addError" class="alert alert-danger py-3 mb-3">
        <strong>Error:</strong> {{ addError }}
      </div>
      <div class="modal-footer">
        <button class="btn btn-warning" @click="closeAdd">Cancel</button>
        <button class="btn btn-primary" @click="confirmAdd">Add</button>
      </div>
    </div>
  </div>

  <!-- REPORT MISSING (roadmap #11) -->
  <StockAdjustmentModal
    :show="adjustOpen"
    :item-id="adjustItem?.iditems"
    :item-name="adjustItem?.name"
    :available="Number(adjustItem?.qty) || 0"
    @close="adjustOpen = false"
    @done="onAdjusted"
  />
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
  background: rgba(0, 0, 0, 0.35);
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
.borrower-card-body {
  overflow: hidden;
}
.highlight {
  text-decoration: underline;
}
</style>

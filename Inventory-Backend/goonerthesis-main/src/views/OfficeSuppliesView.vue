<script setup>
const modalOpen = ref(false);
const modalMode = ref("");
const modalQty = ref(1);

import { ref, computed, onMounted } from "vue";
import {
  listInventory,
  addItem,
  updateItem,
  restockOfficeSupplies,
} from "../services/inventory";
import { getRequests, returnEquipment } from "../services/requests";
import AlertMessage from "../components/AlertMessage.vue";
import StockBadge from "../components/StockBadge.vue";
import DueBadge from "../components/DueBadge.vue";
import StockAdjustmentModal from "../components/StockAdjustmentModal.vue";
import {
  OFFICE_TYPE_OPTIONS,
  TYPE_CONSUMABLE,
  TYPE_A,
  TYPE_B,
} from "../services/itemTypes";
import { getErrorMessage } from "../services/errors";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

const modalError = ref("");
const addError = ref("");

// STATES
const activeTab = ref("inventory");
const selectedSupply = ref(null);

const items = ref([]);
const currentItem = ref(null);
const addOpen = ref(false);
const addSubCategory = ref(TYPE_CONSUMABLE);
const addName = ref("");
const addQty = ref(1);
const addCritical = ref(2);
const addMinimum = ref(5);
const addOptimal = ref(20);
const editName = ref("");
const editType = ref(TYPE_CONSUMABLE);
// Return period in days for Type B items; blank = category default.
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
const editCritical = ref(2);
const editMinimum = ref(5);
const editOptimal = ref(20);

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
const confirmAction = ref(false);

function openAdd() {
  addSubCategory.value = TYPE_CONSUMABLE;
  addReturnDays.value = "";
  addPerHeadLimit.value = "";
  addName.value = "";
  addQty.value = 1;
  addCritical.value = 2;
  addMinimum.value = 5;
  addOptimal.value = 20;
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
    addError.value = "Please enter item name.";
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
  const daysProblem =
    addSubCategory.value === TYPE_B ? returnDaysProblem(addReturnDays.value) : "";
  if (daysProblem) {
    addError.value = daysProblem;
    return;
  }
  const limitProblem = perHeadLimitProblem(addPerHeadLimit.value);
  if (limitProblem) {
    addError.value = limitProblem;
    return;
  }

  try {
    await addItem({
      item_name: name,
      category: "Office Supplies",
      item_type: addSubCategory.value,
      quantity: qty,
      critical_stock: Number(addCritical.value),
      minimum_stock: Number(addMinimum.value),
      optimal_stock: Number(addOptimal.value),
      return_days:
        addSubCategory.value === TYPE_B && addReturnDays.value !== ""
          ? Number(addReturnDays.value)
          : null,
      per_head_limit: addPerHeadLimit.value !== "" ? Number(addPerHeadLimit.value) : null,
    });

    await refresh();
    closeAdd();
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }
}

onMounted(async () => {
  await refresh();
});

const modalMax = computed(() => {
  if (!currentItem.value) return 0;

  if (modalMode.value === "return") {
    return Number(currentItem.value.borrowedQty) || 0;
  }

  return 0; // restock has no limit
});

// Report Missing (roadmap #11)
const adjustOpen = ref(false);
const adjustItem = ref(null);

function openAdjust(item) {
  adjustItem.value = item;
  adjustOpen.value = true;
}

async function onAdjusted() {
  notify("success", `Stock adjusted for ${adjustItem.value?.item_name}.`);
  await refresh();
}

async function refresh() {
  const [inventory, requests] = await Promise.all([
    listInventory(),
    getRequests(),
  ]);
  const officeInventory = inventory
    .filter((i) => i.category === "Office Supplies")
    .map((i) => ({
      ...i,
      borrowedQty: 0,
      requestItems: [],
    }));
  requests.forEach((request) => {
    if (request.status !== "Approved") return;
    request.items.forEach((reqItem) => {
      const item = officeInventory.find(
        (i) => i.iditems === reqItem.itemId && i.item_type === TYPE_B,
      );
      if (!item) return;
      item.borrowedQty += Number(reqItem.borrowedQty);
      item.requestItems.push({
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
  items.value = officeInventory;

  // The Borrower Details panel holds a snapshot of the selected item.
  // refresh() rebuilds brand-new item objects every time (e.g. right
  // after a partial return), so without this the panel would keep
  // showing the old borrowedQty/requestItems instead of the new ones.
  if (selectedSupply.value) {
    const updated = officeInventory.find(
      (i) => i.iditems === selectedSupply.value.iditems,
    );
    selectedSupply.value =
      updated && Number(updated.borrowedQty) > 0 ? updated : null;
  }
}

const borrowedSupplies = computed(() =>
  typeBItems.value
    .filter((i) => Number(i.borrowedQty) > 0)
    .sort((a, b) => a.item_name.localeCompare(b.item_name)),
);

const supplyBorrowers = computed(
  () => selectedSupply.value?.requestItems || [],
);

function toggleSupplyDetails(item) {
  selectedSupply.value =
    selectedSupply.value?.iditems === item.iditems ? null : item;
}

const officeItems = computed(() =>
  items.value.filter((i) => i.category === "Office Supplies"),
);

const typeBItems = computed(() =>
  officeItems.value.filter((i) => i.item_type === TYPE_B),
);

// ---- One table: search, filter by type / stock level, sort by column ----
const LEVELS = ["Out of Stock", "Critical", "Minimum", "Adequate", "Optimal"];
const LEVEL_ORDER = Object.fromEntries(LEVELS.map((l, n) => [l, n]));

const search = ref("");
const typeFilter = ref("ALL");
const levelFilter = ref("ALL");
const sortKey = ref("name");
const sortDir = ref("asc");

function typeShort(type) {
  return OFFICE_TYPE_OPTIONS.find((t) => t.value === type)?.short || type;
}

function typeBadge(type) {
  if (type === TYPE_A) return "bg-primary";
  if (type === TYPE_B) return "bg-success";
  return "bg-secondary";
}

function sortBy(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === "asc" ? "desc" : "asc";
  } else {
    sortKey.value = key;
    sortDir.value = "asc";
  }
}

function sortMark(key) {
  if (sortKey.value !== key) return "";
  return sortDir.value === "asc" ? " ▲" : " ▼";
}

const tableRows = computed(() => {
  const q = search.value.trim().toLowerCase();

  const rows = officeItems.value.filter(
    (i) =>
      (!q || (i.item_name || "").toLowerCase().includes(q)) &&
      (typeFilter.value === "ALL" || i.item_type === typeFilter.value) &&
      (levelFilter.value === "ALL" || i.stock_level === levelFilter.value),
  );

  const value = (i) => {
    switch (sortKey.value) {
      case "type":
        return typeShort(i.item_type);
      case "level":
        return LEVEL_ORDER[i.stock_level] ?? 99;
      case "qty":
        return Number(i.quantity) || 0;
      case "borrowed":
        return Number(i.borrowedQty) || 0;
      default:
        return (i.item_name || "").toLowerCase();
    }
  };

  const dir = sortDir.value === "asc" ? 1 : -1;
  return [...rows].sort((x, y) => {
    const a = value(x);
    const b = value(y);
    const c = typeof a === "number" ? a - b : String(a).localeCompare(String(b));
    return c * dir || (x.item_name || "").localeCompare(y.item_name || "");
  });
});

function openQtyModal(mode, item) {
  modalMode.value = mode;
  currentItem.value = item;
  modalQty.value = 1;
  editName.value = item.item_name;
  editType.value = item.item_type;
  editReturnDays.value = item.return_days ?? "";
  editPerHeadLimit.value = item.per_head_limit ?? "";
  editCritical.value = item.critical_stock ?? 2;
  editMinimum.value = item.minimum_stock ?? 5;
  editOptimal.value = item.optimal_stock ?? 20;
  modalError.value = "";
  confirmAction.value = false;
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  confirmAction.value = false;
}

async function proceedAction() {
  const qty = Number(modalQty.value) || 0;

  if (modalMode.value === "return" && qty <= 0) {
    modalError.value = "Quantity must be at least 1.";
    return;
  }

  if (modalMode.value === "return" && qty > currentItem.value.borrowedQty) {
    modalError.value = `Max allowed is ${currentItem.value.borrowedQty}.`;
    return;
  }

  try {
    if (modalMode.value === "restock") {
      await restockOfficeSupplies(currentItem.value.iditems, qty);
    } else if (modalMode.value === "edit") {
      if (!editName.value.trim()) {
        modalError.value = "Item name is required.";
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
      const daysProblem =
        editType.value === TYPE_B ? returnDaysProblem(editReturnDays.value) : "";
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

      await updateItem(currentItem.value.iditems, {
        item_name: editName.value.trim(),
        description: currentItem.value.description,
        category: currentItem.value.category,
        item_type: editType.value,
        quantity: Number(currentItem.value.quantity),
        critical_stock: Number(editCritical.value),
        minimum_stock: Number(editMinimum.value),
        optimal_stock: Number(editOptimal.value),
        return_days:
          editType.value === TYPE_B && editReturnDays.value !== ""
            ? Number(editReturnDays.value)
            : null,
        per_head_limit: editPerHeadLimit.value !== "" ? Number(editPerHeadLimit.value) : null,
      });
    } else if (modalMode.value === "return") {
      await returnEquipment(
        currentItem.value.requestItems[0].requestItemId,
        qty,
      );
    }

    await refresh();
    confirmAction.value = false;
    modalOpen.value = false;
  } catch (e) {
    notify("danger", getErrorMessage(e));
  }
}
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <h3 class="mb-4">Office Supplies</h3>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="d-flex gap-2">
        <button
          class="btn"
          :class="
            activeTab === 'inventory' ? 'btn-primary' : 'btn-outline-primary'
          "
          @click="activeTab = 'inventory'"
        >
          Inventory
        </button>
        <button
          class="btn"
          :class="
            activeTab === 'borrowed' ? 'btn-primary' : 'btn-outline-primary'
          "
          @click="activeTab = 'borrowed'"
        >
          Borrowed Items
        </button>
      </div>
      <button
        v-if="activeTab === 'inventory'"
        class="btn btn-primary"
        @click="openAdd"
      >
        + Add Office Supply
      </button>
    </div>
    <div v-if="activeTab === 'inventory'" class="card shadow-sm">
      <div class="card-body">
        <!-- Filters: one table for everything; narrow it by type or stock level -->
        <div class="row g-2 align-items-end mb-3">
          <div class="col-md-4">
            <label class="form-label small mb-1" for="os-search">Search</label>
            <input
              id="os-search"
              name="os-search"
              v-model="search"
              type="search"
              class="form-control"
              placeholder="Search by item name" />
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-1" for="os-type-filter">Type</label>
            <select
              id="os-type-filter"
              name="os-type-filter"
              v-model="typeFilter"
              class="form-select">
              <option value="ALL">All types</option>
              <option v-for="t in OFFICE_TYPE_OPTIONS" :key="t.value" :value="t.value">
                {{ t.short }}
              </option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-1" for="os-level-filter">Stock level</label>
            <select
              id="os-level-filter"
              name="os-level-filter"
              v-model="levelFilter"
              class="form-select">
              <option value="ALL">All levels</option>
              <option v-for="l in LEVELS" :key="l" :value="l">{{ l }}</option>
            </select>
          </div>
          <div class="col-md-2 text-md-end small text-muted">
            Showing {{ tableRows.length }} of {{ officeItems.length }}
          </div>
        </div>

        <div class="table-responsive table-scroll">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="sortable" @click="sortBy('name')">Name{{ sortMark("name") }}</th>
                <th class="sortable" @click="sortBy('type')">Type{{ sortMark("type") }}</th>
                <th class="sortable" @click="sortBy('level')">Stock Level{{ sortMark("level") }}</th>
                <th class="sortable" style="width: 90px" @click="sortBy('qty')">Qty{{ sortMark("qty") }}</th>
                <th class="sortable" style="width: 100px" @click="sortBy('borrowed')">Borrowed{{ sortMark("borrowed") }}</th>
                <th>Return Period</th>
                <th style="width: 110px">Per-Head Limit</th>
                <th style="width: 260px; text-align: center">Action</th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="i in tableRows" :key="i.iditems">
                <td>{{ i.item_name }}</td>
                <td>
                  <span class="badge" :class="typeBadge(i.item_type)">
                    {{ typeShort(i.item_type) }}
                  </span>
                </td>
                <td>
                  <StockBadge
                    :level="i.stock_level"
                    :title="`Critical ≤ ${i.critical_stock} · Minimum ≤ ${i.minimum_stock} · Optimal ≥ ${i.optimal_stock}`"
                  />
                </td>
                <td>{{ i.quantity }}</td>
                <td>
                  <template v-if="i.item_type === TYPE_B">
                    {{ Number(i.borrowedQty) || 0 }}
                  </template>
                  <span v-else class="text-muted">-</span>
                </td>
                <td>
                  <template v-if="i.item_type === TYPE_B">
                    <span v-if="i.return_days">{{ i.return_days }} days</span>
                    <span v-else-if="i.effective_return_days" class="text-muted">
                      {{ i.effective_return_days }} days (default)
                    </span>
                    <span v-else class="text-muted">None</span>
                  </template>
                  <span v-else class="text-muted">-</span>
                </td>
                <td>{{ i.per_head_limit ?? "No limit" }}</td>
                <td class="text-center">
                  <div class="d-flex justify-content-center gap-2">
                    <button
                      class="btn btn-success btn-sm"
                      @click="openQtyModal('restock', i)"
                    >
                      Restock
                    </button>
                    <button
                      class="btn btn-warning btn-sm"
                      @click="openQtyModal('edit', i)"
                    >
                      Edit
                    </button>
                    <button
                      class="btn btn-danger btn-sm"
                      title="Record missing, lost or stolen stock"
                      @click="openAdjust(i)"
                    >
                      Missing
                    </button>
                  </div>
                </td>
              </tr>

              <tr v-if="tableRows.length === 0">
                <td colspan="8" class="text-center text-muted py-4">
                  No items match the current filters.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div v-else class="d-flex gap-3" style="height: 75vh">
      <!-- LEFT -->
      <div class="card shadow-sm" style="flex: 1">
        <div class="table-responsive table-scroll">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Borrowed</th>
                <th>Overdue</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in borrowedSupplies" :key="item.iditems">
                <td>{{ item.item_name }}</td>
                <td>{{ item.status }}</td>
                <td>{{ item.borrowedQty }}</td>
                <td>
                  <span
                    v-if="item.requestItems.some((r) => r.overdue)"
                    class="badge bg-danger"
                  >
                    {{ item.requestItems.filter((r) => r.overdue).length }} overdue
                  </span>
                  <span v-else class="text-muted">-</span>
                </td>
                <td>
                  <button
                    class="btn btn-sm"
                    :class="selectedSupply?.iditems === item.iditems ? 'btn-outline-success' : 'btn-success'"
                    @click="toggleSupplyDetails(item)"
                  >
                    {{ selectedSupply?.iditems === item.iditems ? "Hide Borrowers" : "View Borrowers" }}
                  </button>
                </td>
              </tr>
              <tr v-if="borrowedSupplies.length === 0">
                <td colspan="5" class="text-center text-muted">
                  No borrowed office supplies.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- RIGHT -->
      <div
        class="card shadow-sm borrower-details-card"
        style="flex: 1"
      >
        <div class="card-body d-flex flex-column">

          <h5 class="flex-shrink-0">
            Borrower Details
          </h5>

          <div
            v-if="selectedSupply"
            class="borrower-details-scroll"
          >

            <div
              v-for="borrower in supplyBorrowers"
              :key="borrower.requestItemId"
              class="border rounded p-3 mb-3 borrower-card"
            >

              <div class="borrower-detail">
                <strong>Requester:</strong>
                <span class="borrower-value">
                  {{ borrower.requester }}
                </span>
              </div>

              <div class="borrower-detail">
                <strong>Request #:</strong>
                <span class="borrower-value">
                  {{ borrower.requestId }}
                </span>
              </div>

              <div class="borrower-detail">
                <strong>Location:</strong>
                <span class="borrower-value">
                  {{ borrower.location }}
                </span>
              </div>

              <div class="borrower-detail">
                <strong>Room:</strong>
                <span class="borrower-value">
                  {{ borrower.room }}
                </span>
              </div>

              <div class="borrower-detail">
                <strong>Borrowed:</strong>
                <span class="borrower-value">
                  {{ borrower.borrowedQty }}
                </span>
              </div>

              <div class="borrower-detail">
                <strong>Return by:</strong>
                <span class="borrower-value">
                  <DueBadge
                    :due-date="borrower.dueDate || ''"
                    :overdue="borrower.overdue"
                    :days-overdue="borrower.daysOverdue"
                  />
                </span>
              </div>

              <div v-if="borrower.pendingReturnQty > 0" class="borrower-detail">
                <strong>Return reported:</strong>
                <span class="borrower-value">
                  <span class="badge bg-warning text-dark">
                    {{ borrower.pendingReturnQty }} awaiting your check
                  </span>
                </span>
              </div>

              <button
                class="btn btn-success mt-3"
                @click="
                  openQtyModal('return', {
                    item_name: selectedSupply.item_name,
                    borrowedQty: borrower.borrowedQty,
                    requestItems: [borrower],
                  })
                "
              >
                Return
              </button>

            </div>

          </div>

          <div
            v-else
            class="text-center text-muted mt-5"
          >
            Select an item to view borrowers.
          </div>

        </div>
      </div>
    </div>

    <div v-if="modalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">
            {{
              modalMode === "restock"
                ? `Restock: ${currentItem?.item_name}`
                : modalMode === "edit"
                  ? `Edit: ${currentItem?.item_name}`
                  : modalMode === "release"
                    ? `Release: ${currentItem?.item_name}`
                    : modalMode === "borrow"
                      ? `Borrow: ${currentItem?.item_name}`
                      : `Return: ${currentItem?.item_name}`
            }}
          </h5>
          <button class="btn-close" @click="closeModal"></button>
        </div>
        <div class="modal-body">
          <template v-if="modalMode === 'edit'">
            <label for="os-edit-name" class="form-label">Item Name</label>
            <input class="form-control mb-3" v-model="editName" id="os-edit-name" name="edit-name" />

            <label for="os-edit-type" class="form-label">Type</label>
            <select class="form-select mb-3" v-model="editType" id="os-edit-type" name="edit-type">
              <option
                v-for="opt in OFFICE_TYPE_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </option>
            </select>

            <template v-if="editType === TYPE_B">
              <label for="os-edit-return-days" class="form-label">Return Period (days)</label>
              <input
                type="number"
                min="1"
                class="form-control"
                v-model="editReturnDays"
                placeholder="Blank = category default" id="os-edit-return-days" name="edit-return-days" />
              <small class="text-muted d-block mb-3">
                How long a borrower may keep this item before it is overdue.
              </small>
            </template>

            <label class="form-label">Stock Levels</label>
            <div class="row g-2 mb-1">
              <div class="col">
                <small class="text-muted">Critical (at or below)</small>
                <input aria-label="Edit critical" type="number" min="0" class="form-control" v-model="editCritical" id="os-edit-critical" name="edit-critical" />
              </div>
              <div class="col">
                <small class="text-muted">Minimum (at or below)</small>
                <input aria-label="Edit minimum" type="number" min="0" class="form-control" v-model="editMinimum" id="os-edit-minimum" name="edit-minimum" />
              </div>
              <div class="col">
                <small class="text-muted">Optimal (at or above)</small>
                <input aria-label="Edit optimal" type="number" min="0" class="form-control" v-model="editOptimal" id="os-edit-optimal" name="edit-optimal" />
              </div>
            </div>

            <label for="os-edit-per-head-limit" class="form-label mt-3">Per-Head Limit</label>
            <input
              type="number"
              min="1"
              class="form-control"
              v-model="editPerHeadLimit"
              placeholder="Blank = no limit" id="os-edit-per-head-limit" name="edit-per-head-limit" />
            <small class="text-muted d-block">
              Max a single requester may hold/have pending of this item at once.
            </small>
          </template>
          <template v-else>
            <label for="os-modal-qty" class="form-label">Quantity</label>
            <input
              type="number"
              min="1"
              :max="modalMax || undefined"
              class="form-control"
              v-model="modalQty" id="os-modal-qty" name="modal-qty" />
          </template>
          <small
            v-if="modalMode !== 'edit' && modalMax"
            class="text-muted d-block mt-1"
          >
            Max: {{ modalMax }}
          </small>
          <div v-if="modalMode !== 'edit'" class="small text-muted mt-2">
            This quantity will apply to each selected item.
          </div>
          <div v-if="modalError" class="alert alert-danger py-3 mb-3">
            <strong>Error:</strong> {{ modalError }}
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="closeModal">Cancel</button>
          <button class="btn btn-primary" @click="confirmAction = true">
            Confirm
          </button>
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
        <label for="os-add-sub-category" class="form-label">Type</label>
        <select class="form-select mb-3" v-model="addSubCategory" id="os-add-sub-category" name="add-sub-category">
          <option
            v-for="opt in OFFICE_TYPE_OPTIONS"
            :key="opt.value"
            :value="opt.value"
          >
            {{ opt.label }}
          </option>
        </select>

        <label for="os-add-name" class="form-label">Name</label>
        <input
          class="form-control mb-3"
          v-model="addName"
          placeholder="Name of the item that is being Added." id="os-add-name" name="add-name" />

        <label for="os-add-qty" class="form-label">Quantity</label>
        <input type="number" min="1" class="form-control" v-model="addQty" id="os-add-qty" name="add-qty" />

        <template v-if="addSubCategory === TYPE_B">
          <label for="os-add-return-days" class="form-label mt-3">Return Period (days)</label>
          <input
            type="number"
            min="1"
            class="form-control"
            v-model="addReturnDays"
            placeholder="Blank = category default" id="os-add-return-days" name="add-return-days" />
          <small class="text-muted d-block">
            How long a borrower may keep this item before it is overdue.
          </small>
        </template>

        <label class="form-label mt-3">Stock Levels</label>
        <div class="row g-2">
          <div class="col">
            <small class="text-muted">Critical (at or below)</small>
            <input aria-label="Add critical" type="number" min="0" class="form-control" v-model="addCritical" id="os-add-critical" name="add-critical" />
          </div>
          <div class="col">
            <small class="text-muted">Minimum (at or below)</small>
            <input aria-label="Add minimum" type="number" min="0" class="form-control" v-model="addMinimum" id="os-add-minimum" name="add-minimum" />
          </div>
          <div class="col">
            <small class="text-muted">Optimal (at or above)</small>
            <input aria-label="Add optimal" type="number" min="0" class="form-control" v-model="addOptimal" id="os-add-optimal" name="add-optimal" />
          </div>
        </div>

        <label for="os-add-per-head-limit" class="form-label mt-3">Per-Head Limit</label>
        <input
          type="number"
          min="1"
          class="form-control"
          v-model="addPerHeadLimit"
          placeholder="Blank = no limit" id="os-add-per-head-limit" name="add-per-head-limit" />
        <small class="text-muted d-block">
          Max a single requester may hold/have pending of this item at once.
        </small>

        <div class="small text-muted mt-2">
          The stock level badge updates automatically from the quantity.
        </div>
        <div v-if="addError" class="alert alert-danger py-3 mb-3">
          <strong>Error:</strong> {{ addError }}
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" @click="closeAdd">Cancel</button>
        <button class="btn btn-primary" @click="confirmAdd">Add</button>
      </div>
    </div>
  </div>
  <!-- CONFIRMATION MODAL -->
  <template v-else>
    <div v-if="confirmAction" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm Action</h5>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning mb-0">
            <h6 class="mb-3">Confirm Action</h6>
            <div v-if="modalMode === 'restock'">
              Restock
              <strong class="highlight">{{ currentItem.item_name }}</strong>
              by
              <strong class="highlight">{{ modalQty }}</strong>
              item(s)?
            </div>
            <div v-else-if="modalMode === 'edit'">
              Save changes to
              <strong class="highlight">{{ currentItem.item_name }}</strong>
              (name: <strong class="highlight">{{ editName }}</strong>, Critical
              ≤ {{ editCritical }}, Minimum ≤ {{ editMinimum }}, Optimal ≥
              {{ editOptimal }}, Per-Head Limit:
              {{ editPerHeadLimit === "" ? "No limit" : editPerHeadLimit }})?
            </div>
            <div v-else-if="modalMode === 'return'">
              Return
              <strong class="highlight">{{ modalQty }}</strong>
              item(s) of
              <strong class="highlight">{{ currentItem.item_name }}</strong
              >?
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="confirmAction = false">
            Cancel
          </button>
          <button class="btn btn-primary" @click="proceedAction">Yes</button>
        </div>
      </div>
    </div>
  </template>

  <!-- REPORT MISSING (roadmap #11) -->
  <StockAdjustmentModal
    :show="adjustOpen"
    :item-id="adjustItem?.iditems"
    :item-name="adjustItem?.item_name"
    :available="Number(adjustItem?.quantity) || 0"
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
  box-shadow: 0 1px 0 #dee2e6;
}
.highlight {
  text-decoration: underline;
}
.borrower-details-card {
  min-width: 0;
  overflow: hidden;
}

.borrower-details-scroll {
  flex: 1;
  min-height: 0;
  max-height: calc(75vh - 90px);
  overflow-y: auto;
  overflow-x: hidden;
  padding-right: 6px;
}

.borrower-card {
  overflow: hidden;
}

.borrower-detail {
  display: flex;
  gap: 6px;
  margin-bottom: 6px;
  min-width: 0;
}

.borrower-value {
  min-width: 0;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.sortable {
  cursor: pointer;
  user-select: none;
  white-space: nowrap;
}
</style>

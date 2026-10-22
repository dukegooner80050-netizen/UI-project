<script setup>
import { printBorrowerForm, hasBorrowedItems } from "../services/borrowerForm";
import { ref, watch, computed, onMounted, nextTick } from "vue";
import { createRequestBatch, getRequests, confirmReceived, releaseRequest } from "../services/requests";
import {
  listUniformTypes,
  listUniformVariants,
  listUniformReservations,
  createUniformReservation,
  cancelUniformReservation,
} from "../services/uniforms";
import { getCurrentUser } from "../services/storage";
import { getItems } from "../services/items";
import { TYPE_B, OFFICE_TYPE_OPTIONS } from "../services/itemTypes";
import { getMonthlyRequestLimit } from "../services/settings";
import DueBadge from "../components/DueBadge.vue";
import { reportReturn } from "../services/returnReports";
import { isReturnableType } from "../services/itemTypes";

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
const uniformReservations = ref([]);
const reservationBusy = ref(false);
const selectedUniformTypeId = ref("");
const selectedUniformVariantId = ref("");
const warningModal = ref(false);
const submitConfirmOpen = ref(false);
const submitting = ref(false);
const warningTitle = ref("");
const warningMessage = ref("");

// LOAD DATA //

const monthlyLimit = ref(null);

// Uniform-only requests have no monthly limit, so they are not counted.
const requestsThisMonth = computed(() => {
  const now = new Date();
  return myRequests.value.filter((r) => {
    if ((r.items || []).length === 0 && (r.uniforms || []).length > 0) {
      return false;
    }
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

  if (user?.role !== "amo") {
    try {
      const res = await getMonthlyRequestLimit();
      monthlyLimit.value = res.monthly_request_limit;
    } catch (err) {
      console.error("Failed to load monthly request limit:", err);
    }
  }
});

// ---- Report a return (the AMO still confirms it) ----
const reportingLine = ref(null); // requestItemId whose form is open
const reportQty = ref(1);
const reportBusy = ref(false);

// Units of a line that can still be reported: not returned, not already waiting.
function reportable(item) {
  return item.borrowedQty - item.pendingReturnQty;
}

function startReport(item) {
  reportingLine.value = item.requestItemId;
  reportQty.value = reportable(item);
}

// Send only validates and asks "are you sure?"; the report itself goes out
// from the confirmation modal (doReport).
const receiveConfirmOpen = ref(false);
const receiveBusy = ref(false);
const uniformReleaseBusy = ref(false);

async function releaseUniformRequest() {
  const request = selected.value;
  if (!request?.id || userRole !== "cashier") return;
  if ((request.status || "").toLowerCase() !== "approved" || request.releasedAt) return;
  if (!(request.uniforms || []).length || (request.items || []).length) return;
  const studentName = window.prompt(
    `Enter the full name of the student collecting the uniform for request #${request.id}:`,
    request.releasedToStudent || "",
  );
  if (studentName === null) return;
  const confirmedStudentName = studentName.trim();
  if (!confirmedStudentName) {
    showWarning("Student Name Required", "Enter the student's full name before confirming pickup.");
    return;
  }
  if (!window.confirm(`Confirm that ${confirmedStudentName} has physically received all uniforms in request #${request.id}? Stock will be deducted now.`)) return;

  uniformReleaseBusy.value = true;
  try {
    await releaseRequest(request.id, "For Pick Up", confirmedStudentName);
    await loadMyRequests();
    selected.value = myRequests.value.find((r) => Number(r.id) === Number(request.id)) || null;
    await loadUniforms();
    showWarning("Uniform Released", "Handover recorded. Uniform stock has now been deducted.");
  } catch (e) {
    showWarning("Uniform Release", e.response?.data?.message || "Unable to record the uniform handover.");
  } finally {
    uniformReleaseBusy.value = false;
  }
}

async function doConfirmReceived() {
  const id = selected.value?.id;
  receiveConfirmOpen.value = false;
  if (!id) return;
  receiveBusy.value = true;
  try {
    await confirmReceived(id);
    await loadMyRequests();
    selected.value = myRequests.value.find((r) => r.id === id) || null;
    showWarning("Receipt recorded", "Thanks, your receipt has been recorded.");
  } catch (e) {
    showWarning(
      "Confirm Received",
      e.response?.data?.message || "Unable to record your receipt.",
    );
  } finally {
    receiveBusy.value = false;
  }
}

const reportConfirmOpen = ref(false);
const reportTarget = ref(null);
const reportTargetQty = ref(0);

function sendReport(item) {
  const qty = Number(reportQty.value);
  if (!Number.isInteger(qty) || qty < 1 || qty > reportable(item)) {
    showWarning("Report Return", `Enter a quantity from 1 to ${reportable(item)}.`);
    return;
  }
  reportTarget.value = item;
  reportTargetQty.value = qty;
  reportConfirmOpen.value = true;
}

async function doReport() {
  const item = reportTarget.value;
  const qty = reportTargetQty.value;
  reportConfirmOpen.value = false;
  if (!item) return;

  reportBusy.value = true;
  try {
    await reportReturn(item.requestItemId, qty);
    reportingLine.value = null;
    const openId = selected.value?.id;
    await loadMyRequests();
    selected.value = myRequests.value.find((r) => r.id === openId) || null;
    showWarning(
      "Return reported",
      "The administrator has been told. Please hand the item over if you haven't yet; it stays on your record until they confirm it.",
    );
  } catch (e) {
    showWarning(
      "Report Return",
      e.response?.data?.message || "Unable to report this return.",
    );
  } finally {
    reportBusy.value = false;
  }
}

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
      requestLimit: item.requestLimit ?? null,
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

async function loadReservations() {
  if (userRole !== "cashier") return;
  try {
    uniformReservations.value = await listUniformReservations();
  } catch (err) {
    console.error("Failed to load uniform reservations:", err);
    showWarning("Reservation Error", "Unable to load uniform reservations.");
  }
}

async function reserveOutOfStockUniform() {
  if (userRole !== "cashier" || !selectedUniformVariant.value) return;
  const quantity = Number(qty.value);
  if (!Number.isInteger(quantity) || quantity < 1) {
    error.value = "Reservation quantity must be at least 1.";
    return;
  }
  reservationBusy.value = true;
  error.value = "";
  try {
    await createUniformReservation(selectedUniformVariant.value.idUnifvariant, quantity);
    await loadReservations();
    showWarning("Reservation Added", "This uniform is out of stock. Your reservation has been added to the queue. The reservation status will change to Ready when stock is replenished.");
    activeTab.value = "reservations";
  } catch (err) {
    showWarning("Reservation Failed", err.response?.data?.message || "Unable to reserve this uniform.");
  } finally {
    reservationBusy.value = false;
  }
}

async function cancelReservation(reservation) {
  try {
    await cancelUniformReservation(reservation.id);
    await loadReservations();
  } catch (err) {
    showWarning("Cancel Reservation", err.response?.data?.message || "Unable to cancel this reservation.");
  }
}

// PENDING STOCK RESERVATION //

const reservedStockMap = computed(() => {
  const map = {};
  for (const request of myRequests.value) {
    if (!["pending", "with coordinator", "with inventory"].includes(String(request.status || "").toLowerCase())) {
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
// Uniform-only requests are picked up by the student at the AMO office,
// so there is no building/room to choose. Mixed requests (uniforms +
// items) still ask for location/room because the items may be delivered.
const AMO_OFFICE = "AMO Office";
const uniformOnly = computed(
  () => requestItems.value.length === 0 && requestUniforms.value.length > 0,
);

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
        return type === TYPE_B;
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
  const available = Math.max(stock - reserved - alreadyRequested, 0);
  // Per-request cap (School Equipment); null = no limit.
  const limit = selectedItem.value.requestLimit;
  if (limit !== null && limit !== undefined) {
    return Math.min(available, Math.max(Number(limit) - alreadyRequested, 0));
  }
  return available;
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
  const limit = selectedItem.value.requestLimit;
  const hasLimit = limit !== null && limit !== undefined;
  if (maxQty.value <= 0) {
    return hasLimit
      ? `Request limit of ${limit} per request reached for this item.`
      : "No more stock available for this item.";
  }
  if (Number(qty.value) > maxQty.value) {
    return hasLimit
      ? `Limited item: you can request up to ${limit} per request (${maxQty.value} more allowed).`
      : `Only ${maxQty.value} item(s) available.`;
  }
  return "";
});

// WATCH CATEGORY //

watch(category, async (value) => {
  selectedItemId.value = "";

  if (value === "School Equipment") {
    itemType.value = TYPE_B;
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
        requester: request.requester,
        requesterName: request.requesterName,
        notedBy: request.notedBy,
        request_date: request.request_date,
        status: request.status,
        date: request.request_date,
        borrowedAt: request.borrowedAt,
        releaseMethod: request.releaseMethod,
        releasedAt: request.releasedAt,
        releasedToStudent: request.releasedToStudent || "",
        receivedAt: request.receivedAt,
        location: request.location,
        room: request.room,
        purpose: request.purpose,
        rejectReason: request.rejectReason,
        approvalRemarks: request.approvalRemarks,

        items: (request.items || []).map((item) => ({
          requestItemId: item.requestItemId,
          pendingReturnQty: Number(item.pendingReturnQty) || 0,
          itemId: item.itemId,
          itemName: item.itemName,
          category: item.category,
          itemType: item.itemType,
          qty: Number(item.qty) || 0,
          returnedQty: Number(item.returnedQty) || 0,
          borrowedQty: Number(item.borrowedQty) || 0,
          dueDate: item.dueDate || "",
          overdue: !!item.overdue,
          daysOverdue: Number(item.daysOverdue) || 0,
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

// Uniforms are requested on their own, never mixed with office supplies or
// school equipment. If the list already holds the other kind, ask whether
// to submit it or discard it first.
const mixConflictOpen = ref(false);
const mixConflictHas = computed(() =>
  requestUniforms.value.length > 0 ? "uniforms" : "items",
);

function mixConflictCancel() {
  mixConflictOpen.value = false;
}

function mixConflictSubmit() {
  mixConflictOpen.value = false;
  submitRequest();
}

async function mixConflictDiscard() {
  mixConflictOpen.value = false;
  requestItems.value = [];
  requestUniforms.value = [];
  await addToList();
}

async function addToList() {
  error.value = "";
  const quantity = Number(qty.value);
  if (!Number.isInteger(quantity) || quantity < 1) {
    error.value = "Quantity must be at least 1.";
    return;
  }

  const addingUniform = category.value === "Uniforms";
  const hasSelection = addingUniform
    ? !!selectedUniformVariant.value
    : !!selectedItem.value;
  if (
    hasSelection &&
    ((addingUniform && requestItems.value.length > 0) ||
      (!addingUniform && requestUniforms.value.length > 0))
  ) {
    mixConflictOpen.value = true;
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
        gender: selectedUniformVariant.value.gender || "Unisex",
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

// Called with no argument from the button (shows the confirmation first)
// and with `true` from the confirmation modal (really submits).
async function submitRequest(confirmed = false) {
  error.value = "";

  if (requestItems.value.length === 0 && requestUniforms.value.length === 0) {
    showWarning(
      "No Items Added",
      "Please add at least one item or uniform to the request list before submitting.",
    );
    return;
  }
  if (!uniformOnly.value && !location.value) {
    showWarning(
      "Location Required",
      "Please select a location before submitting your request.",
    );
    return;
  }
  if (!uniformOnly.value && !room.value) {
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

  if (confirmed !== true) {
    submitConfirmOpen.value = true;
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

      const stock = Math.max(0, (Number(uniform.quantity) || 0) - (Number(uniform.reserved_quantity) || 0));

      if (Number(requestUniform.quantity) > stock) {
        showWarning(
          "Insufficient Uniform Stock",
          `Only ${stock} unreserved unit(s) are available for ${requestUniform.uniformName} (${requestUniform.size}).`,
        );

        return;
      }
    }
  }

  // Payload

  const payload = {
    request_date: new Date().toISOString().slice(0, 10),
    location: uniformOnly.value ? AMO_OFFICE : location.value,
    room: uniformOnly.value ? "N/A" : room.value,
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

  submitting.value = true;
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
  } finally {
    submitting.value = false;
  }
}

function confirmSubmit() {
  submitConfirmOpen.value = false;
  submitRequest(true);
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
      <button
        v-if="canRequestUniforms"
        class="btn"
        :class="activeTab === 'reservations' ? 'btn-primary' : 'btn-outline-primary'"
        @click="loadReservations(); activeTab = 'reservations'"
      >
        Uniform Reservations
        <span v-if="uniformReservations.some((r) => r.status === 'Ready')" class="badge bg-success ms-1">
          {{ uniformReservations.filter((r) => r.status === 'Ready').length }} Ready
        </span>
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
      <span v-if="canRequestUniforms" class="text-muted">
        (Uniform requests are not counted.)
      </span>
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
          <label for="rq-category" class="form-label">Category</label>
          <select v-model="category" class="form-select" id="rq-category" name="category">
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

            <select aria-label="Item type"
              v-model="itemType"
              class="form-select"
              :disabled="isItemTypeLocked" id="rq-item-type" name="item-type">
              <option value="">Select item type</option>
              <option
                v-for="opt in OFFICE_TYPE_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </option>
            </select>
          </div>

          <!-- Item Name -->
          <div class="mb-3">
            <label for="rq-selected-item-id" class="form-label">Item Name</label>
            <select
              v-model="selectedItemId"
              class="form-select"
              :disabled="!category || !itemType" id="rq-selected-item-id" name="selected-item-id">
              <option value="">Select item</option>
              <option
                v-for="i in itemOptions"
                :key="i.id"
                :value="String(i.id)"
              >
                {{ i.name }} (Qty: {{ Number(i.qty) || 0 }}){{
                  i.requestLimit ? ` – limit ${i.requestLimit}/request` : ""
                }}
              </option>
            </select>
          </div>

          <!-- Quantity -->
          <div class="mb-3">
            <label for="rq-qty" class="form-label">Quantity</label>

            <input
              type="number"
              min="1"
              :max="maxQty"
              v-model="qty"
              class="form-control" id="rq-qty" name="qty" />
            <div v-if="qtyWarning" class="text-danger small mt-1">
              {{ qtyWarning }}
            </div>
          </div>
        </template>

        <!-- UNIFORM REQUEST -->

        <template v-else-if="category === 'Uniforms'">
          <!-- Uniform Type -->
          <div class="mb-3">
            <label for="rq-selected-uniform-type-id" class="form-label"> Uniform Type </label>

            <select v-model="selectedUniformTypeId" class="form-select" id="rq-selected-uniform-type-id" name="selected-uniform-type-id">
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
            <label for="rq-selected-uniform-variant-id" class="form-label"> Uniform Variant </label>

            <select
              v-model="selectedUniformVariantId"
              class="form-select"
              :disabled="!selectedUniformTypeId" id="rq-selected-uniform-variant-id" name="selected-uniform-variant-id">
              <option value="">Select uniform variant</option>

              <option
                v-for="variant in uniformOptions"
                :key="variant.idUnifvariant"
                :value="String(variant.idUnifvariant)"
              >
                {{ variant.gender || "Unisex" }} — {{ variant.size }}
                —
                {{ variant.department?.dept_name || "N/A" }}
                (Qty: {{ Number(variant.quantity) || 0 }})
              </option>
            </select>
          </div>

          <!-- Uniform Quantity -->
          <div class="mb-3">
            <label for="rq-qty-2" class="form-label"> Quantity </label>
            <input
              type="number"
              min="1"
              :max="uniformMaxQty"
              v-model="qty"
              class="form-control" id="rq-qty-2" name="qty" />
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
          <button
            v-if="category === 'Uniforms' && selectedUniformVariant && uniformMaxQty <= 0"
            type="button"
            class="btn btn-outline-primary"
            :disabled="reservationBusy || Number(qty) < 1"
            @click="reserveOutOfStockUniform"
          >
            {{ reservationBusy ? "Reserving..." : "Reserve Out-of-Stock Uniform" }}
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
                      class="btn btn-sm btn-danger text-white"
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
                      — {{ uniform.gender || "Unisex" }} — Size {{ uniform.size }}
                    </small>
                  </td>

                  <td>
                    <span class="badge bg-primary">
                      {{ uniform.quantity }}
                    </span>
                  </td>

                  <td>
                    <button
                      class="btn btn-sm btn-danger text-white"
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

        <div v-if="uniformOnly" class="alert alert-info py-2 small">
          Uniforms are picked up by the student at the AMO office, so no
          building or room is needed.
        </div>

        <div v-else class="row">
          <div class="col-md-6">
            <label for="rq-location" class="form-label">Location</label>
            <select v-model="location" class="form-select" id="rq-location" name="location">
              <option value="">Select location</option>
              <option>SFB.Faculty</option>
              <option>SFB.Building 1</option>
              <option>SFB.Building 2</option>
              <option>SFB.Building 3</option>
            </select>
          </div>
          <div class="col-md-6">
            <label for="rq-room" class="form-label">Room</label>
            <select v-model="room" class="form-select" id="rq-room" name="room">
              <option value="">Select Room</option>
              <option>Room 201</option>
              <option>Room 202</option>
              <option>Room 203</option>
              <option>N/A</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label for="rq-request-purpose" class="form-label">Purpose</label>
          <textarea
            v-model="requestPurpose"
            class="form-control"
            rows="3"
            placeholder="Enter purpose of request..." id="rq-request-purpose" name="request-purpose"></textarea>
        </div>

        <div class="mt-3">
          <button
            class="btn btn-primary w-100"
            :disabled="
              submitting ||
              (requestItems.length === 0 && requestUniforms.length === 0)
            "
            @click="submitRequest()"
          >
            Submit All Requests
          </button>
        </div>
      </div>
    </div>

    <!-- UNIFORM RESERVATIONS -->
    <div v-else-if="activeTab === 'reservations' && canRequestUniforms" class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h5 class="mb-0">Uniform Reservation Queue</h5>
          <button class="btn btn-sm btn-outline-primary" @click="loadReservations">Refresh</button>
        </div>
        <div class="alert alert-info py-2">
          Reservations are processed in queue order when stock is replenished. A Ready status means stock was found for the requested quantity; submit a regular uniform request to complete the request.
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr><th>Uniform</th><th>Gender</th><th>Size</th><th>Qty</th><th>Stock</th><th>Status</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
              <tr v-for="reservation in uniformReservations" :key="reservation.id">
                <td>{{ reservation.uniformName }} <small class="text-muted">({{ reservation.department }})</small></td>
                <td>{{ reservation.gender }}</td>
                <td>{{ reservation.size }}</td>
                <td>{{ reservation.quantity }}</td>
                <td>{{ reservation.stock }}</td>
                <td><span class="badge" :class="reservation.status === 'Ready' ? 'bg-success' : reservation.status === 'Waiting' ? 'bg-warning text-dark' : 'bg-secondary'">{{ reservation.status }}</span>
                  <div v-if="reservation.status === 'Ready'" class="small text-success">Stock replenished — ready to request</div>
                </td>
                <td>{{ formatDate(reservation.createdAt) }}</td>
                <td><button v-if="['Waiting','Ready'].includes(reservation.status)" class="btn btn-sm btn-outline-danger" @click="cancelReservation(reservation)">Cancel</button></td>
              </tr>
              <tr v-if="uniformReservations.length === 0"><td colspan="8" class="text-center text-muted py-4">No uniform reservations yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MY REQUESTS + RECEIPT -->
    <div v-else-if="activeTab === 'mine'">
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
              — {{ uniform.gender || "Unisex" }} — Size {{ uniform.size || "N/A" }} — Qty: {{ uniform.quantity }}
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
                          ({{ uniform.department || "N/A" }}, {{ uniform.gender || "Unisex" }}, Size
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
                            ['pending', 'with coordinator', 'with inventory'].includes((r.status || '').toLowerCase()),
                        }"
                      >
                        {{ r.status }}
                      </span>
                      <div v-if="r.releasedAt" class="badge bg-info text-dark mt-1">
                        {{ r.releaseMethod }}
                      </div>
                    </td>
                    <td>{{ formatDate(r.date) }}</td>
                    <td>
                      <button
                        class="btn btn-sm btn-secondary text-white"
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
                  <span
                    v-if="isReturnableType(item.itemType) && selected.status === 'Approved'"
                    class="text-muted small ms-1"
                  >
                    &middot; {{ item.returnedQty }} returned &middot;
                    {{ item.borrowedQty }} still out
                  </span>
                  <span
                    v-else-if="selected.status === 'Approved' && !isReturnableType(item.itemType)"
                    class="text-muted small ms-1"
                  >
                    &middot; issued, no return needed
                  </span>
                  <DueBadge
                    v-if="item.dueDate && item.borrowedQty > 0 && !item.pendingReturnQty"
                    class="ms-1"
                    :due-date="item.dueDate"
                    :overdue="item.overdue"
                    :days-overdue="item.daysOverdue"
                  />
                  <span
                    v-if="item.pendingReturnQty > 0"
                    class="badge bg-warning text-dark ms-1"
                  >
                    Return reported ({{ item.pendingReturnQty }}): awaiting check
                  </span>

                  <!-- Borrower says it was returned; AMO confirms -->
                  <template
                    v-if="
                      selected.status === 'Approved' &&
                      isReturnableType(item.itemType) &&
                      reportable(item) > 0
                    "
                  >
                    <button
                      v-if="reportingLine !== item.requestItemId"
                      type="button"
                      class="btn btn-sm btn-success text-white ms-2"
                      @click="startReport(item)"
                    >
                      Report returned
                    </button>
                    <span v-else class="ms-2 d-inline-flex align-items-center gap-1">
                      <input aria-label="Report qty"
                        v-model="reportQty"
                        type="number"
                        min="1"
                        :max="reportable(item)"
                        class="form-control form-control-sm"
                        style="width: 80px" id="rq-report-qty" name="report-qty" />
                      <button
                        type="button"
                        class="btn btn-sm btn-success"
                        :disabled="reportBusy"
                        @click="sendReport(item)"
                      >
                        Send
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-light"
                        @click="reportingLine = null"
                      >
                        Cancel
                      </button>
                    </span>
                  </template>
                </li>
                <li
                  v-for="(uniform, i) in selected.uniforms"
                  :key="'uniform-' + i"
                >
                  {{ uniform.uniformName || "Uniform" }} —
                  {{ uniform.department || "N/A" }} — {{ uniform.gender || "Unisex" }} — Size
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

              <div v-if="selected.releasedAt" class="mt-1">
                <div v-if="(selected.uniforms || []).length > 0">
                  <strong>Student who collected:</strong> {{ selected.releasedToStudent || "Not recorded" }}
                </div>
                <strong>Release:</strong>
                <span class="badge bg-info text-dark">{{ selected.releaseMethod }}</span>
                <div class="text-muted small">
                  {{ new Date(selected.releasedAt).toLocaleString("en-PH") }}
                </div>
              </div>
              <div
                v-else-if="(selected.status || '').toLowerCase() === 'approved'"
                class="mt-1 text-muted small"
              >
                Approved — awaiting release.
              </div>
              <button
                v-if="userRole === 'cashier' && (selected.uniforms || []).length > 0 && (selected.items || []).length === 0 && (selected.status || '').toLowerCase() === 'approved' && !selected.releasedAt"
                type="button"
                class="btn btn-sm btn-success mt-2"
                :disabled="uniformReleaseBusy"
                @click="releaseUniformRequest"
              >
                <span v-if="uniformReleaseBusy" class="spinner-border spinner-border-sm me-1"></span>
                Confirm Student Pickup
              </button>
              <div
                v-if="userRole === 'cashier' && (selected.uniforms || []).length > 0 && selected.releasedAt"
                class="small text-success mt-2"
              >
                <strong>Handover recorded.</strong> Stock was deducted at pickup.
              </div>

              <div v-if="selected.releasedAt" class="mt-1">
                <template v-if="selected.receivedAt">
                  <strong>Received:</strong>
                  <span class="badge bg-success">Confirmed</span>
                  <div class="text-muted small">
                    {{ new Date(selected.receivedAt).toLocaleString("en-PH") }}
                  </div>
                </template>
                <button
                  v-else
                  type="button"
                  class="btn btn-sm btn-success mt-1"
                  @click="receiveConfirmOpen = true"
                >
                  Confirm received
                </button>
              </div>
              <button
                v-if="(selected.status || '').toLowerCase() === 'approved' && hasBorrowedItems(selected)"
                class="btn btn-sm btn-dark text-white mt-2"
                @click="printBorrowerForm(selected)"
              >
                Print Borrower's Form
              </button>

              <div
                v-if="
                  (selected.status || '').toLowerCase() === 'approved' &&
                  selected.approvalRemarks
                "
                class="mt-2"
              >
                <strong>Remarks:</strong> {{ selected.approvalRemarks }}
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
                class="btn btn-sm btn-secondary text-white"
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
  <div v-if="submitConfirmOpen" class="modal-backdrop-custom">
    <div class="modal-custom">
      <div class="modal-header">
        <h5 class="mb-0">Submit Request</h5>
        <button class="btn-close" @click="submitConfirmOpen = false"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2">
          Submit this request for
          <strong>{{ requestItems.length + requestUniforms.length }}</strong>
          line(s)?
        </p>
        <ul class="small mb-2">
          <li v-for="(it, idx) in requestItems" :key="'i' + idx">
            {{ it.qty }} x {{ it.itemName }}
          </li>
          <li v-for="(u, idx) in requestUniforms" :key="'u' + idx">
            {{ u.quantity }} x {{ u.uniformName || "Uniform" }}
            <span v-if="u.size">({{ u.size }})</span>
          </li>
        </ul>
        <p v-if="!uniformOnly" class="text-muted small mb-0">
          It will count toward your monthly request limit.
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" @click="submitConfirmOpen = false">
          Cancel
        </button>
        <button class="btn btn-success" @click="confirmSubmit">
          Confirm
        </button>
      </div>
    </div>
  </div>

  <div v-if="mixConflictOpen" class="modal-backdrop-custom">
    <div class="modal-custom" style="max-width: 440px">
      <div class="modal-header">
        <h5 class="mb-0">Uniforms Are Requested Separately</h5>
        <button class="btn-close" @click="mixConflictCancel"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2">
          A uniform request can't include office supplies or school
          equipment. Your current request already has
          <strong>{{ mixConflictHas === "uniforms" ? "uniforms" : "items" }}</strong>.
        </p>
        <p class="text-muted small mb-0">
          Submit the current request first, or discard it and start a new
          one.
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" @click="mixConflictCancel">
          Cancel
        </button>
        <button class="btn btn-danger" @click="mixConflictDiscard">
          Discard current request
        </button>
        <button class="btn btn-success" @click="mixConflictSubmit">
          Submit current request
        </button>
      </div>
    </div>
  </div>

  <div v-if="receiveConfirmOpen" class="modal-backdrop-custom">
    <div class="modal-custom" style="max-width: 420px">
      <div class="modal-header">
        <h5 class="mb-0">Confirm Received</h5>
        <button class="btn-close" @click="receiveConfirmOpen = false"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2">
          Confirm that you received the items for request
          <strong>#{{ selected?.id }}</strong>?
        </p>
        <p class="text-muted small mb-0">
          This is recorded with the date and time and can't be undone.
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" @click="receiveConfirmOpen = false">
          Cancel
        </button>
        <button class="btn btn-success" :disabled="receiveBusy" @click="doConfirmReceived">
          Confirm
        </button>
      </div>
    </div>
  </div>

  <div v-if="reportConfirmOpen" class="modal-backdrop-custom">
    <div class="modal-custom" style="max-width: 420px">
      <div class="modal-header">
        <h5 class="mb-0">Report Return</h5>
        <button class="btn-close" @click="reportConfirmOpen = false"></button>
      </div>

      <div class="modal-body">
        <p class="mb-2">
          Report
          <strong>{{ reportTargetQty }} x {{ reportTarget?.itemName }}</strong>
          as returned?
        </p>
        <p class="text-muted small mb-0">
          The administrator will be told and still has to confirm it. The item
          stays on your record until they do.
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" @click="reportConfirmOpen = false">
          Cancel
        </button>
        <button class="btn btn-success" @click="doReport">Confirm</button>
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

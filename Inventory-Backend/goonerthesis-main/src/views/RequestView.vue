<script setup>
import { printBorrowerForm, hasBorrowedItems } from "../services/borrowerForm";
import { ref, watch, computed, onMounted, nextTick } from "vue";
import { createRequestBatch, getRequests, confirmReceived } from "../services/requests";
import { fulfilmentState } from "../services/requestState";
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
// Instructors: skip the department head when they are away or there is none.
const sendDirect = ref(false);
const directReasonChoice = ref("");
const directReasonOther = ref("");
const DIRECT_REASONS = [
  "Department head is not present",
  "Department head is unavailable",
  "No department head assigned",
  "Other",
];
const directReason = computed(() =>
  directReasonChoice.value === "Other"
    ? directReasonOther.value.trim()
    : directReasonChoice.value,
);
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
  // Independent lists: load them together instead of one after another.
  await Promise.all([loadInventory(), loadUniforms(), loadMyRequests()]);

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

const studentName = ref("");
const studentNumber = ref("");
const studentPaid = ref(false);

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
      const stock = (Number(item.qty) || 0) - (Number(item.reservedQty) || 0);
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

// CASHIER: pick Course, Gender and Size one by one instead of one long list.
// Each choice only offers what exists for the choices before it; once all three
// are set, the matching variant is selected.
const pickCourse = ref("");
const pickGender = ref("");
const pickSize = ref("");
const genderOf = (v) => v.gender || "Unisex";
const courseOf = (v) => v.department?.dept_name || "N/A";
const courseChoices = computed(() => [
  ...new Set(uniformOptions.value.map(courseOf)),
].sort());
const genderChoices = computed(() => [
  ...new Set(
    uniformOptions.value
      .filter((v) => courseOf(v) === pickCourse.value)
      .map(genderOf),
  ),
].sort());
const sizeChoices = computed(() => {
  const seen = new Map();
  uniformOptions.value
    .filter(
      (v) =>
        courseOf(v) === pickCourse.value && genderOf(v) === pickGender.value,
    )
    .forEach((v) => seen.set(String(v.size), v));
  return [...seen.keys()];
});
function syncPickedVariant() {
  const v = uniformOptions.value.find(
    (x) =>
      courseOf(x) === pickCourse.value &&
      genderOf(x) === pickGender.value &&
      String(x.size) === String(pickSize.value),
  );
  selectedUniformVariantId.value = v ? String(v.idUnifvariant) : "";
}
watch(selectedUniformTypeId, () => {
  pickCourse.value = "";
  pickGender.value = "";
  pickSize.value = "";
});
watch(pickCourse, () => {
  pickGender.value = "";
  pickSize.value = "";
});
watch(pickGender, () => {
  pickSize.value = "";
});
watch([pickCourse, pickGender, pickSize], syncPickedVariant);

// SELECTED ITEM //

// The chosen uniform type, so its description can be shown under the picker.
const selectedUniformType = computed(() =>
  uniformTypes.value.find(
    (t) => String(t.idUniftype) === String(selectedUniformTypeId.value),
  ),
);

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
  const stock =
    (Number(selectedItem.value.qty) || 0) -
    (Number(selectedItem.value.reservedQty) || 0);
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

// Units the cashier can still request: on the shelf, minus what approved
// requests are holding, plus anything held for this cashier's own Ready
// reservations (the server counts those as available to them too).
function availableUniformStock(variant) {
  if (!variant) return 0;
  const held = uniformReservations.value
    .filter(
      (x) =>
        x.status === "Ready" &&
        String(x.uniformVariantId) === String(variant.idUnifvariant),
    )
    .reduce((sum, x) => sum + (Number(x.quantity) || 0), 0);
  return Math.max(
    0,
    (Number(variant.quantity) || 0) - (Number(variant.reserved_quantity) || 0) + held,
  );
}
const uniformMaxQty = computed(() =>
  availableUniformStock(selectedUniformVariant.value),
);
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
        directReason: request.directReason || "",
        coordReview: request.coordReview || "",
        coordReviewNote: request.coordReviewNote || "",
        request_date: request.request_date,
        status: request.status,
        date: request.request_date,
        borrowedAt: request.borrowedAt,
        releaseMethod: request.releaseMethod,
        releasedAt: request.releasedAt,
        releasedToStudent: request.releasedToStudent || "",
        releasedStudentId: request.releasedStudentId || "",
        studentName: request.studentName || "",
        studentNumber: request.studentNumber || "",
        receivedAt: request.receivedAt,
        paidAt: request.paidAt,
        stockHeld: !!request.stockHeld,
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
    // What is on the shelf goes out as a normal request line; anything
    // beyond it (or everything, when out of stock) becomes a reservation
    // line, held for the paid student once the AMO restocks. Both go out
    // with the same student name, ID and payment confirmation.
    const variant = selectedUniformVariant.value;
    const sameVariant = (u) =>
      String(u.uniformVariantId) === String(variant.idUnifvariant);
    const normalLine = requestUniforms.value.find((u) => sameVariant(u) && !u.reserve);
    const reserveLine = requestUniforms.value.find((u) => sameVariant(u) && u.reserve);
    const availLeft = Math.max(0, uniformMaxQty.value - Number(normalLine?.quantity || 0));
    const takeNow = Math.min(quantity, availLeft);
    const toReserve = quantity - takeNow;

    const base = () => ({
      uniformVariantId: variant.idUnifvariant,
      uniformName: variant.type?.uniform_name || "Uniform",
      department: variant.department?.dept_name || "N/A",
      gender: variant.gender || "Unisex",
      size: variant.size,
      price: Number(variant.price) || 0,
    });

    if (takeNow > 0) {
      if (normalLine) {
        normalLine.quantity = Number(normalLine.quantity) + takeNow;
      } else {
        // A Ready reservation already has the student and the payment.
        const held = uniformReservations.value.find(
          (x) =>
            x.status === "Ready" &&
            String(x.uniformVariantId) === String(variant.idUnifvariant),
        );
        if (held) {
          if (!studentName.value.trim() && held.studentName) studentName.value = held.studentName;
          if (!studentNumber.value.trim() && held.studentNumber) studentNumber.value = held.studentNumber;
          if (held.paidAt) studentPaid.value = true;
        }
        requestUniforms.value.push({ ...base(), quantity: takeNow, reserve: false });
      }
    }
    if (toReserve > 0) {
      if (reserveLine) {
        reserveLine.quantity = Number(reserveLine.quantity) + toReserve;
      } else {
        requestUniforms.value.push({ ...base(), quantity: toReserve, reserve: true });
      }
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
  pickCourse.value = "";
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
  if (uniformOnly.value && !studentName.value.trim()) {
    showWarning("Student Name Required", "Enter the full name of the student this uniform request is for.");
    return;
  }
  if (uniformOnly.value && !studentNumber.value.trim()) {
    showWarning("Student ID Required", "Enter the student ID number this uniform request is for.");
    return;
  }
  if (requestUniforms.value.length > 0 && !studentPaid.value) {
    showWarning("Payment Not Confirmed", "Tick the box to confirm the student already paid for the uniforms at the cashier.");
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
  if (userRole === "instructor" && sendDirect.value && directReason.value.length < 3) {
    showWarning("Reason Required", "Choose why this request is going straight to the AMO.");
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
      if (requestUniform.reserve) continue;
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

      const stock = availableUniformStock(uniform);

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

  const normalUniforms = requestUniforms.value.filter((u) => !u.reserve);
  const reserveLines = requestUniforms.value.filter((u) => u.reserve);

  const payload = {
    location: uniformOnly.value ? AMO_OFFICE : location.value,
    room: uniformOnly.value ? "N/A" : room.value,
    purpose: requestPurpose.value.trim(),
    ...(userRole === "instructor" && sendDirect.value ? { send_direct: true, direct_reason: directReason.value } : {}),
    ...(normalUniforms.length > 0 ? { paid: studentPaid.value } : {}),
    ...(uniformOnly.value ? { student_name: studentName.value.trim(), student_number: studentNumber.value.trim() } : {}),
    items: requestItems.value.map((item) => ({
      iditems: item.itemId,
      quantity: Number(item.qty),
    })),
    uniforms: normalUniforms.map((uniform) => ({
      idUnifvariant: uniform.uniformVariantId,
      quantity: Number(uniform.quantity),
    })),
  };

  submitting.value = true;
  try {
    let response = null;
    if (requestItems.value.length > 0 || normalUniforms.length > 0) {
      response = await createRequestBatch(payload);
      // Those lines are sent; only reservations stay in the list.
      requestUniforms.value = reserveLines;
    }

    // Out-of-stock lines become reservations for the same student.
    for (const line of [...reserveLines]) {
      await createUniformReservation(line.uniformVariantId, line.quantity, {
        name: studentName.value.trim(),
        number: studentNumber.value.trim(),
        paid: studentPaid.value,
      });
      requestUniforms.value = requestUniforms.value.filter((u) => u !== line);
    }
    if (reserveLines.length > 0) await loadReservations();

    await loadInventory();
    await loadMyRequests();

    if (response?.request) {
      lastReceipt.value = myRequests.value.find(
        (request) => String(request.id) === String(response.request.id),
      );
    }
    activeTab.value = response ? "mine" : "reservations";
    if (reserveLines.length > 0) {
      showWarning(
        "Reservation Added",
        "The out-of-stock uniform(s) were reserved. When the AMO restocks, a request is created for the AMO automatically.",
      );
    }
    requestItems.value = [];
    requestUniforms.value = [];
    studentName.value = "";
    studentNumber.value = "";
    studentPaid.value = false;
    requestPurpose.value = "";
    sendDirect.value = false;
    directReasonChoice.value = "";
    directReasonOther.value = "";
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
            <div v-if="selectedUniformType?.description" class="form-text">
              {{ selectedUniformType.description }}
            </div>
          </div>

          <!-- Uniform Variant -->
          <div v-if="userRole === 'cashier'" class="row g-2 mb-3">
            <div class="col-12 col-md-4">
              <label for="rq-pick-course" class="form-label">Course</label>
              <select v-model="pickCourse" class="form-select" id="rq-pick-course" :disabled="!selectedUniformTypeId">
                <option value="">Select course</option>
                <option v-for="c in courseChoices" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label for="rq-pick-gender" class="form-label">Gender</label>
              <select v-model="pickGender" class="form-select" id="rq-pick-gender" :disabled="!pickCourse">
                <option value="">Select gender</option>
                <option v-for="g in genderChoices" :key="g" :value="g">{{ g }}</option>
              </select>
            </div>
            <div class="col-6 col-md-4">
              <label for="rq-pick-size" class="form-label">Size</label>
              <select v-model="pickSize" class="form-select" id="rq-pick-size" :disabled="!pickGender">
                <option value="">Select size</option>
                <option v-for="z in sizeChoices" :key="z" :value="z">{{ z }}</option>
              </select>
            </div>
            <div v-if="selectedUniformVariant" class="form-text">
              Available: {{ uniformMaxQty }}
            </div>
          </div>

          <div v-else class="mb-3">
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
              v-model="qty"
              class="form-control" id="rq-qty-2" name="qty" />
            <div
              v-if="selectedUniformVariant && uniformMaxQty <= 0"
              class="text-danger small mt-1"
            >
              Out of stock. It will be added to the list as a reservation, held for
              the student once stock arrives.
            </div>

            <div
              v-else-if="selectedUniformVariant && Number(qty) > uniformMaxQty"
              class="text-info small mt-1"
            >
              Only {{ uniformMaxQty }} in stock. The other {{ Number(qty) - uniformMaxQty }} will be added as a reservation.
            </div>
          </div>
        </template>

        <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>
        <div class="text-end">
          <button
            class="btn btn-success me-2"
            :disabled="
              category === 'Uniforms'
                ? !selectedUniformVariant || qty <= 0
                : !selectedItem || qty <= 0 || qty > maxQty
            "
            @click="addToList"
          >
            {{ category === 'Uniforms' && selectedUniformVariant && uniformMaxQty <= 0 ? 'Add as Reservation' : category === 'Uniforms' && selectedUniformVariant && Number(qty) > uniformMaxQty ? 'Add (reserve the rest)' : 'Add Item' }}
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
                  :key="'uniform-' + uniform.uniformVariantId + (uniform.reserve ? '-r' : '')"
                >
                  <td>
                    <div>
                      <strong>{{ uniform.uniformName }}</strong>
                    </div>

                    <small class="text-muted">
                      {{ uniform.department }}
                      — {{ uniform.gender || "Unisex" }} — Size {{ uniform.size }}
                    </small>
                    <div v-if="uniform.reserve">
                      <span class="badge bg-warning text-dark">Reservation · out of stock</span>
                    </div>
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

        <div v-if="uniformOnly" class="mb-3">
          <label for="rq-student-name" class="form-label">Student's full name</label>
          <input
            id="rq-student-name"
            name="student_name"
            type="text"
            class="form-control"
            v-model="studentName"
            maxlength="255"
            autocomplete="off"
            placeholder="Who will collect the uniform?"
          />
        </div>

        <div v-if="uniformOnly" class="mb-3">
          <label for="rq-student-number" class="form-label">Student ID number</label>
          <input
            id="rq-student-number"
            name="student_number"
            type="text"
            class="form-control"
            v-model="studentNumber"
            maxlength="50"
            autocomplete="off"
            placeholder="Student ID"
          />
          <div class="form-text">The AMO will see this and check the student's ID at pickup.</div>
        </div>

        <div v-if="requestUniforms.length > 0" class="form-check mb-3">
          <input id="rq-student-paid" name="paid" type="checkbox" class="form-check-input" v-model="studentPaid" />
          <label for="rq-student-paid" class="form-check-label">
            The student has already paid for these uniforms at the cashier
          </label>
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

        <div v-if="userRole === 'instructor'" class="mb-3">
          <div class="form-check">
            <input id="rq-send-direct" name="send_direct" type="checkbox" class="form-check-input" v-model="sendDirect" />
            <label for="rq-send-direct" class="form-check-label">
              Send directly to the AMO (skip the department head)
            </label>
          </div>
          <div v-if="sendDirect" class="mt-2">
            <select v-model="directReasonChoice" class="form-select" id="rq-direct-reason" name="direct_reason_choice">
              <option value="">Why is the department head skipped?</option>
              <option v-for="r in DIRECT_REASONS" :key="r" :value="r">{{ r }}</option>
            </select>
            <input
              v-if="directReasonChoice === 'Other'"
              v-model="directReasonOther"
              id="rq-direct-reason-other"
              name="direct_reason_other"
              type="text"
              class="form-control mt-2"
              maxlength="200"
              placeholder="Type the reason"
            />
            <div class="form-text">The reason is recorded with the request and shown to the AMO.</div>
          </div>
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
          <button class="btn btn-sm btn-outline-secondary" title="Refresh" aria-label="Refresh" @click="loadReservations"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="alert alert-info py-2">
          Reserve uniforms the student has already paid for when they are out of stock. When the AMO restocks, the oldest reservations become requests to the AMO automatically (stock held, ready for pickup) and you get a notification. No need to submit a request yourself.
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr><th>Uniform</th><th>Student</th><th>Gender</th><th>Size</th><th>Qty</th><th>Stock</th><th>Status</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
              <tr v-for="reservation in uniformReservations" :key="reservation.id">
                <td>{{ reservation.uniformName }} <small class="text-muted">({{ reservation.department }})</small></td>
                <td>
                  <div>{{ reservation.studentName || "—" }}</div>
                  <small class="text-muted" v-if="reservation.studentNumber">ID {{ reservation.studentNumber }}</small>
                  <span v-if="reservation.paidAt" class="badge bg-success ms-1">Paid</span>
                </td>
                <td>{{ reservation.gender }}</td>
                <td>{{ reservation.size }}</td>
                <td>{{ reservation.quantity }}</td>
                <td>{{ reservation.stock }}</td>
                <td><span class="badge" :class="['Ready', 'Submitted'].includes(reservation.status) ? 'bg-success' : reservation.status === 'Waiting' ? 'bg-warning text-dark' : 'bg-secondary'">{{ reservation.status === 'Submitted' ? 'Sent to AMO' : reservation.status }}</span>
                  <div v-if="reservation.status === 'Submitted'" class="small text-success">Stock arrived — request created, ready for pickup</div>
                  <div v-else-if="reservation.status === 'Ready'" class="small text-success">Stock replenished — ready to request</div>
                </td>
                <td>{{ formatDate(reservation.createdAt) }}</td>
                <td><button v-if="['Waiting','Ready'].includes(reservation.status)" class="btn btn-sm btn-outline-danger" @click="cancelReservation(reservation)">Cancel</button></td>
              </tr>
              <tr v-if="uniformReservations.length === 0"><td colspan="9" class="text-center text-muted py-4">No uniform reservations yet.</td></tr>
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
                      <div v-if="fulfilmentState(r)" class="badge mt-1" :class="fulfilmentState(r).cls">
                        {{ fulfilmentState(r).text }}
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
              <div v-if="selected.directReason"><strong>Sent directly to the AMO:</strong> {{ selected.directReason }}<span v-if="selected.coordReview"> — Department head {{ selected.coordReview.toLowerCase() }}<span v-if="selected.coordReviewNote">: {{ selected.coordReviewNote }}</span></span></div>
              <div v-if="selected.studentName"><strong>Student:</strong> {{ selected.studentName }}<span v-if="selected.studentNumber"> (ID {{ selected.studentNumber }})</span></div>
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
                  <strong>Picked up by:</strong> {{ selected.releasedToStudent || "Not recorded" }}
                  <span v-if="selected.releasedStudentId">(ID {{ selected.releasedStudentId }})</span>
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
                Approved — awaiting release.<span v-if="(selected.uniforms || []).length > 0"> The AMO will hand it over to the student at the office.</span>
                <div v-if="fulfilmentState(selected)" class="badge mt-1" :class="fulfilmentState(selected).cls">
                  {{ fulfilmentState(selected).text }}
                </div>
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
            <span v-if="u.reserve" class="text-muted"> — reservation</span>
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
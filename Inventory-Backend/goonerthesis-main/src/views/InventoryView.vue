<script setup>
import { ref, computed, onMounted } from "vue";
import { getItems } from "../services/items";
import { getRequests } from "../services/requests";
import MaintenancePanel from "../components/MaintenancePanel.vue";
import MissingStockPanel from "../components/MissingStockPanel.vue";
import { disposeItem, DISPOSAL_METHODS } from "../services/disposals";
import AlertMessage from "../components/AlertMessage.vue";
import StockBadge from "../components/StockBadge.vue";
import DueBadge from "../components/DueBadge.vue";
import { isIssuedType, isReturnableType } from "../services/itemTypes";

const search = ref("");
const statusFilter = ref("ALL");
const borrowerFilter = ref("ALL");
const activeTab = ref("INVENTORY");
const locationFilter = ref("");
const roomFilter = ref("");
const LOCATIONS = ["SFB.Faculty", "SFB.Building 1", "SFB.Building 2", "SFB.Building 3"];
const ROOMS = ["Room 201", "Room 202", "Room 203", "N/A"];

const items = ref([]);
const requests = ref([]);

// "Item Locator" is a tab inside this same page (not a separate view) so
// that borrowed/location lookups live in one place rather than being
// duplicated across pages.
const isLocationView = computed(() => activeTab.value === "LOCATOR");
// The Status filter already had a "Damaged" option, but nothing ever set
// item.status to "Damaged" -- damaged items are tracked separately via
// damaged_quantity (from the Return Inspection feature). When that filter
// is selected, show a dedicated damaged-items table instead. Displayed as
// "Disposal" in the UI, matching the renamed inspection outcome.
const isDamagedView = computed(
  () => !isLocationView.value && statusFilter.value === "Damaged",
);

const damagedItems = computed(() =>
  items.value.filter((item) => Number(item.damaged_quantity) > 0),
);

// Items marked "Need Maintenance" during inspection. The Maintenance view is
// its own component (MaintenancePanel) with a record per batch sent for repair.
const isMaintenanceView = computed(
  () => !isLocationView.value && statusFilter.value === "Maintenance",
);
const isMissingView = computed(
  () => !isLocationView.value && statusFilter.value === "Missing",
);

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

// Disposal step: formally dispose of units flagged for disposal at inspection.
// Each disposal is saved as a permanent record (see Disposal Records).
const disposeTarget = ref(null);
const disposeForm = ref({ quantity: 1, method: "Discarded", disposed_on: "", notes: "" });
const disposeError = ref("");
const disposing = ref(false);

function todayISO() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
}

function openDispose(item) {
  disposeTarget.value = item;
  disposeForm.value = {
    quantity: Number(item.damaged_quantity) || 1,
    method: "Discarded",
    disposed_on: todayISO(),
    notes: "",
  };
  disposeError.value = "";
}

function closeDispose() {
  disposeTarget.value = null;
  disposeError.value = "";
}

async function confirmDispose() {
  const item = disposeTarget.value;
  const f = disposeForm.value;
  const qty = Number(f.quantity);
  const waiting = Number(item.damaged_quantity) || 0;

  if (!Number.isInteger(qty) || qty < 1) {
    disposeError.value = "Enter a quantity of at least 1.";
    return;
  }
  if (qty > waiting) {
    disposeError.value = `Only ${waiting} unit(s) are waiting for disposal.`;
    return;
  }
  if (f.method === "Other" && !f.notes.trim()) {
    disposeError.value = "Add a note explaining the disposal method.";
    return;
  }
  if (f.disposed_on && f.disposed_on > todayISO()) {
    disposeError.value = "The disposal date can't be in the future.";
    return;
  }

  disposing.value = true;
  try {
    await disposeItem(item.id, {
      quantity: qty,
      method: f.method,
      disposed_on: f.disposed_on || undefined,
      notes: f.notes.trim() || undefined,
    });
    notify("success", `${qty} × ${item.name} disposed of and recorded.`);
    closeDispose();
    await loadData();
  } catch (e) {
    disposeError.value = errMsg(e);
  } finally {
    disposing.value = false;
  }
}
function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function loadData() {
  try {
    const [itemData, requestData] = await Promise.all([
      getItems(),
      getRequests(),
    ]);

    items.value = itemData;
    requests.value = requestData;
  } catch (err) {
    console.error("Failed to load inventory data:", err);
  }
}

onMounted(loadData);


// BORROWED ITEMS

const borrowedRows = computed(() => {
  const rows = [];

  for (const request of requests.value) {

    // Only approved requests represent currently borrowed items
    if (
      String(request.status || "").toLowerCase() !==
      "approved"
    ) {
      continue;
    }

    for (const item of request.items || []) {

      const borrowedQty =
        Number(item.borrowedQty) || 0;

      // Ignore completely returned items
      if (borrowedQty <= 0) {
        continue;
      }

      // Issued items (Consumable / Type A) are never returned, so they are
      // not "borrowed".
      if (isIssuedType(item.itemType)) {
        continue;
      }

      rows.push({
        id:
          `${request.id}-${item.requestItemId}`,

        name:
          item.itemName || "Unknown Item",

        category:
          item.category || "N/A",

        itemType:
          item.itemType || "N/A",

        qty:
          borrowedQty,

        borrower:
          request.requester || "Unknown",

        location:
          request.location || "N/A",

        room:
          request.room || "N/A",

        requestId: request.id ?? request.idrequest ?? "N/A",
        purpose: request.purpose || "N/A",
        itemId: item.itemId ?? item.iditems ?? null,
        date:
          request.borrowedAt ||
          request.request_date,
        dueDate: item.dueDate || "",
        overdue: !!item.overdue,
        daysOverdue: item.daysOverdue || 0,
      });
    }
  }

  if (!isLocationView.value) return rows;

  const locatableNames = new Set(
    items.value
      .filter((item) => {
        const category = String(item.category || "").toLowerCase();
        const type = item.itemType || item.item_type || item.subCategory || "";
        return (category === "office supplies" || category === "school equipment") && isReturnableType(type);
      })
      .map((item) => String(item.name || "").toLowerCase()),
  );

  return rows.filter((row) => locatableNames.has(String(row.name || "").toLowerCase()));
});


// BORROWER OPTIONS

const borrowerOptions = computed(() => {
  const borrowers = borrowedRows.value
    .map((row) => row.borrower)
    .filter(Boolean);

  return [...new Set(borrowers)].sort();
});


// FILTERED INVENTORY

const filteredItems = computed(() => {

  const keyword =
    search.value.trim().toLowerCase();

  return items.value.filter((item) => {

    const name =
      String(item.name || "").toLowerCase();

    const category =
      String(item.category || "").toLowerCase();

    const itemType =
      String(item.subCategory || "").toLowerCase();

    const matchesSearch =
      !keyword ||
      name.includes(keyword) ||
      category.includes(keyword) ||
      itemType.includes(keyword);

    // Low Stock / Critical / Minimum / Out of Stock are driven by each
    // item's own stock thresholds; everything else matches item.status.
    const f = statusFilter.value;
    const matchesStatus =
      f === "ALL" ||
      (f === "Low Stock"
        ? ["Critical", "Minimum"].includes(item.stockLevel)
        : f === "Critical" || f === "Minimum" || f === "Out of Stock"
          ? item.stockLevel === f
          : item.status === f);

    return (
      matchesSearch &&
      matchesStatus
    );
  });
});


// FILTERED DAMAGED ITEMS

const filteredDamagedItems = computed(() => {
  const keyword = search.value.trim().toLowerCase();

  return damagedItems.value.filter((item) => {
    const name = String(item.name || "").toLowerCase();
    const category = String(item.category || "").toLowerCase();
    return !keyword || name.includes(keyword) || category.includes(keyword);
  });
});


// FILTERED BORROWED ROWS

const filteredBorrowedRows = computed(() => {

  const keyword =
    search.value.trim().toLowerCase();

  return borrowedRows.value.filter((row) => {

    const matchesSearch =
      !keyword ||
      String(row.name || "")
        .toLowerCase()
        .includes(keyword) ||

      String(row.category || "")
        .toLowerCase()
        .includes(keyword) ||

      String(row.borrower || "")
        .toLowerCase()
        .includes(keyword) ||

      String(row.location || "")
        .toLowerCase()
        .includes(keyword) ||

      String(row.room || "")
        .toLowerCase()
        .includes(keyword) ||
      String(row.purpose || "")
        .toLowerCase()
        .includes(keyword) ||
      String(row.requestId || "")
        .toLowerCase()
        .includes(keyword);

    const matchesLocation =
      !isLocationView.value ||
      ((!locationFilter.value || row.location === locationFilter.value) &&
       (!roomFilter.value || row.room === roomFilter.value));

    const matchesBorrower =
      borrowerFilter.value === "ALL" ||
      row.borrower === borrowerFilter.value;

    return (
      matchesSearch &&
      matchesBorrower &&
      matchesLocation
    );
  });
});


function clearLocationFilters() {
  search.value = "";
  locationFilter.value = "";
  roomFilter.value = "";
  borrowerFilter.value = "ALL";
}

// STATUS BADGE

function statusClass(status) {

  switch (String(status || "").toLowerCase()) {

    case "available":
      return "bg-success";

    case "borrowed":
      return "bg-primary";

    case "damaged":
      return "bg-danger";

    case "low stock":
      return "bg-warning text-dark";

    case "out of stock":
      return "bg-secondary";

    default:
      return "bg-secondary";
  }
}


// DATE FORMAT

function formatDate(date) {

  if (!date) {
    return "N/A";
  }

  return new Date(date).toLocaleString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}
</script>


<template>

  <div>

    <AlertMessage
      v-model:show="showAlert"
      :type="alertType"
      :message="alertMessage"
    />

    <h3 class="mb-3">Inventory Management</h3>

    <div class="d-flex gap-2 mb-3">
      <button
        type="button"
        class="btn"
        :class="activeTab === 'INVENTORY' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'INVENTORY'"
      >
        Inventory
      </button>
      <button
        type="button"
        class="btn"
        :class="activeTab === 'LOCATOR' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'LOCATOR'"
      >
        Item Locator
      </button>
    </div>

    <p v-if="isLocationView" class="text-muted mb-4">
      Search by item name or filter by building/room to find currently borrowed office supplies and school equipment.
    </p>

    <div v-if="isLocationView" class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="row g-2 align-items-end">
          <div class="col-md-5">
            <label class="form-label">Search by item name</label>
            <input v-model="search" class="form-control" placeholder="e.g. Projector, Chairs..." />
          </div>
          <div class="col-md-3">
            <label class="form-label">Building / Location</label>
            <select v-model="locationFilter" class="form-select">
              <option value="">All locations</option>
              <option v-for="loc in LOCATIONS" :key="loc" :value="loc">{{ loc }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Room</label>
            <select v-model="roomFilter" class="form-select">
              <option value="">All rooms</option>
              <option v-for="room in ROOMS" :key="room" :value="room">{{ room }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Borrower</label>
            <select v-model="borrowerFilter" class="form-select">
              <option value="ALL">All Borrowers</option>
              <option v-for="borrower in borrowerOptions" :key="borrower" :value="borrower">
                {{ borrower }}
              </option>
            </select>
          </div>
          <div class="col-md-1">
            <button type="button" class="btn btn-outline-secondary w-100" @click="clearLocationFilters">Clear filters</button>
          </div>
        </div>
      </div>
    </div>

    <!-- FILTERS (Inventory tab only -- Item Locator has its own search/filter card above) -->

    <div v-if="!isLocationView" class="row mb-3">

      <!-- SEARCH -->

      <div class="col">

        <input v-model="search" class="form-control"
          placeholder="Search item name, category, or type..." />

      </div>


      <!-- STATUS -->

      <div class="col-md-3">

        <select v-model="statusFilter" class="form-select">

          <option value="ALL">
            All Status
          </option>

          <option value="Available">
            Available
          </option>

          <option value="Damaged">
            Damaged
          </option>

          <option value="Maintenance">
            Maintenance
          </option>
          <option value="Missing">
            Missing / Lost / Stolen
          </option>

          <option value="Low Stock">
            Low Stock (Critical + Minimum)
          </option>

          <option value="Critical">
            Critical
          </option>

          <option value="Minimum">
            Minimum
          </option>

          <option value="Out of Stock">
            Out of Stock
          </option>

        </select>

      </div>


    </div>


    <!-- TABLE -->

    <div class="card shadow-sm">

      <div v-if="isDamagedView" class="alert alert-light border d-flex justify-content-between align-items-center flex-wrap gap-2 m-3 mb-0">
        <span>
          Units flagged for disposal at inspection are listed here. Use
          <strong>Dispose</strong> once they're actually gone: the disposal is
          recorded permanently.
        </span>
        <RouterLink to="/disposal-records" class="btn btn-outline-secondary btn-sm">
          View Disposal Records
        </RouterLink>
      </div>

      <MaintenancePanel v-if="isMaintenanceView" :search="search" @changed="loadData" />
      <MissingStockPanel v-if="isMissingView" :search="search" @changed="loadData" />

      <div v-if="!isMaintenanceView && !isMissingView" class="card-body table-scroll">

        <table class="table table-hover align-middle">

          <!-- TABLE HEADER -->

          <thead class="table-light">

            <tr>

              <th>
                Item Name
              </th>


              <!-- NORMAL VIEW -->

              <template v-if="
                !isLocationView &&
                !isDamagedView &&
                !isMaintenanceView &&
                !isMissingView
              ">

                <th>
                  Category
                </th>

                <th>
                  Status
                </th>

                <th>
                  Stock Level
                </th>

                <th>
                  Qty
                </th>

              </template>

              <!-- DAMAGED ITEMS VIEW -->

              <template v-if="isDamagedView">

                <th>
                  Category
                </th>

                <th>
                  Damaged Qty
                </th>

                <th>
                  Available Qty
                </th>

                <th style="width: 130px">
                  Action
                </th>

              </template>

              <!-- ITEM LOCATOR VIEW -->
              <template v-if="isLocationView">
                <th>Category</th><th>Qty Out</th><th>Requester</th><th>Request #</th>
                <th>Location</th><th>Room</th><th>Purpose</th><th>Borrowed On</th><th>Return By</th>
              </template>

            </tr>

          </thead>


          <!-- TABLE BODY -->

          <tbody>



            <!-- NORMAL INVENTORY -->              <template v-if="
                !isLocationView &&
                !isDamagedView &&
                !isMaintenanceView &&
                !isMissingView
              ">


              <tr v-for="item in filteredItems" :key="item.id">

                <td>
                  {{ item.name }}
                </td>

                <td>
                  {{ item.category }}
                </td>

                <td>

                  <span class="badge" :class="statusClass(
                    item.status
                  )
                    ">
                    {{ item.status }}
                  </span>

                </td>

                <td>
                  <StockBadge :level="item.stockLevel" />
                </td>

                <td>
                  {{ item.qty }}
                </td>

              </tr>


              <tr v-if="
                filteredItems.length === 0
              ">

                <td colspan="5" class="text-center text-muted py-4">
                  No items found.
                </td>

              </tr>

            </template>


            <!-- DAMAGED ITEMS VIEW -->

            <template v-else-if="isDamagedView">

              <tr v-for="item in filteredDamagedItems" :key="item.id">

                <td>
                  {{ item.name }}
                </td>

                <td>
                  {{ item.category }}
                </td>

                <td>
                  <span class="badge bg-danger">
                    {{ item.damaged_quantity }}
                  </span>
                </td>

                <td>
                  {{ item.qty }}
                </td>

                <td>
                  <button class="btn btn-danger text-white btn-sm" @click="openDispose(item)">
                    Dispose
                  </button>
                </td>

              </tr>

              <tr v-if="filteredDamagedItems.length === 0">
                <td colspan="5" class="text-center text-muted py-4">
                  No units are waiting for disposal.
                </td>
              </tr>

            </template>

            <!-- ITEM LOCATOR VIEW -->
            <template v-else-if="isLocationView">
              <tr v-for="row in filteredBorrowedRows" :key="row.id">
                <td>{{ row.name }}</td><td>{{ row.category }}</td><td>{{ row.qty }}</td>
                <td>{{ row.borrower }}</td><td>{{ row.requestId }}</td>
                <td>{{ row.location }}</td><td>{{ row.room }}</td><td>{{ row.purpose }}</td>
                <td>{{ formatDate(row.date) }}</td>
                <td>
                  <DueBadge
                    :due-date="row.dueDate"
                    :overdue="row.overdue"
                    :days-overdue="row.daysOverdue"
                  />
                </td>
              </tr>
              <tr v-if="filteredBorrowedRows.length === 0">
                <td colspan="10" class="text-center text-muted py-4">No matching borrowed items found.</td>
              </tr>
            </template>

          </tbody>

        </table>

      </div>

    </div>

  </div>

    <!-- DISPOSE -->
    <div v-if="disposeTarget" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Dispose of units</h5>
          <button type="button" class="btn-close" @click="closeDispose"></button>
        </div>

        <div class="modal-body">
          <p class="mb-3">
            <strong>{{ disposeTarget.name }}</strong>
            <span class="text-muted">
              — {{ disposeTarget.damaged_quantity }} unit(s) waiting for disposal
            </span>
          </p>

          <label class="form-label" for="disp-qty">Quantity to dispose</label>
          <input
            id="disp-qty"
            v-model.number="disposeForm.quantity"
            type="number"
            min="1"
            :max="disposeTarget.damaged_quantity"
            class="form-control mb-3"
          />

          <label class="form-label" for="disp-method">Method</label>
          <select id="disp-method" v-model="disposeForm.method" class="form-select mb-3">
            <option v-for="m in DISPOSAL_METHODS" :key="m" :value="m">{{ m }}</option>
          </select>

          <label class="form-label" for="disp-date">Date disposed</label>
          <input
            id="disp-date"
            v-model="disposeForm.disposed_on"
            type="date"
            :max="todayISO()"
            class="form-control mb-3"
          />

          <label class="form-label" for="disp-notes">
            Notes<span v-if="disposeForm.method === 'Other'"> (required)</span>
          </label>
          <textarea
            id="disp-notes"
            v-model="disposeForm.notes"
            rows="2"
            maxlength="255"
            class="form-control"
            placeholder="e.g. Beyond repair, hauled away by the junk dealer"
          ></textarea>

          <div v-if="disposeError" class="alert alert-danger py-2 mt-3 mb-0">
            {{ disposeError }}
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" :disabled="disposing" @click="closeDispose">
            Cancel
          </button>
          <button type="button" class="btn btn-danger" :disabled="disposing" @click="confirmDispose">
            {{ disposing ? "Saving..." : "Record disposal" }}
          </button>
        </div>
      </div>
    </div>

</template>


<style scoped>
.table-scroll {
  max-height: 70vh;
  overflow-y: auto;
}

.table-scroll thead th {
  position: sticky;
  top: 0;
  z-index: 1;
}
</style>

<style scoped>
</style>

<script setup>
import { ref, computed, onMounted } from "vue";
import { getItems } from "../services/items";
import { getRequests } from "../services/requests";
import { returnToService } from "../services/inspections";
import AlertMessage from "../components/AlertMessage.vue";

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

// Items marked "Need Maintenance" during inspection. Unlike Disposal,
// these can be moved back into available stock later via "Return to
// Service" once repaired.
const isMaintenanceView = computed(
  () => !isLocationView.value && statusFilter.value === "Maintenance",
);

const maintenanceItems = computed(() =>
  items.value.filter((item) => Number(item.maintenance_quantity) > 0),
);

const returnQtyInputs = ref({}); // { [itemId]: quantity }

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

const confirmModalOpen = ref(false);
const pendingReturnItem = ref(null);
const pendingReturnQty = ref(0);

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function handleReturnToService(item) {
  const qty = Number(returnQtyInputs.value[item.id]) || 0;
  if (qty < 1) {
    notify("warning", "Enter a quantity of at least 1.");
    return;
  }
  if (qty > Number(item.maintenance_quantity)) {
    notify("warning", `Only ${item.maintenance_quantity} unit(s) are currently under maintenance.`);
    return;
  }
  pendingReturnItem.value = item;
  pendingReturnQty.value = qty;
  confirmModalOpen.value = true;
}

function closeConfirm() {
  confirmModalOpen.value = false;
  pendingReturnItem.value = null;
  pendingReturnQty.value = 0;
}

async function confirmReturnToService() {
  const item = pendingReturnItem.value;
  const qty = pendingReturnQty.value;
  closeConfirm();

  if (!item || qty < 1) return;

  try {
    await returnToService(item.id, qty);
    returnQtyInputs.value[item.id] = "";
    await loadData();
  } catch (e) {
    notify("danger", errMsg(e));
  }
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

        date:
          request.borrowedAt ||
          request.request_date,
      });
    }
  }

  return rows;
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

    const matchesStatus =
      statusFilter.value === "ALL" ||
      item.status === statusFilter.value;

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


// FILTERED MAINTENANCE ITEMS

const filteredMaintenanceItems = computed(() => {
  const keyword = search.value.trim().toLowerCase();

  return maintenanceItems.value.filter((item) => {
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
        .includes(keyword);

    const matchesBorrower =
      borrowerFilter.value === "ALL" ||
      row.borrower === borrowerFilter.value;

    return (
      matchesSearch &&
      matchesBorrower
    );
  });
});


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
            <button type="button" class="btn btn-outline-secondary w-100" @click="clearLocationFilters">Clear</button>
          </div>
        </div>
      </div>
    </div>

    <!-- FILTERS (Inventory tab only -- Item Locator has its own search/filter card above) -->

    <div class="row mb-3">

      <!-- SEARCH -->

      <div class="col">

        <input v-model="search" class="form-control"
          placeholder="Search item name, category, borrower, or location..." />

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

          <option value="Low Stock">
            Low Stock
          </option>

          <option value="Out of Stock">
            Out of Stock
          </option>

        </select>

      </div>


    </div>


    <!-- TABLE -->

    <div class="card shadow-sm">

      <div class="card-body table-scroll">

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
                !isMaintenanceView
              ">

                <th>
                  Category
                </th>

                <th>
                  Status
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

              </template>

              <!-- MAINTENANCE ITEMS VIEW -->

              <template v-if="isMaintenanceView">

                <th>
                  Category
                </th>

                <th>
                  Under Maintenance
                </th>

                <th>
                  Available Qty
                </th>

                <th style="width: 220px">
                  Return to Service
                </th>

              </template>


              <!-- ITEM LOCATIONS VIEW -->
              <template v-if="isLocationView">

                <th>
                  Available Qty
                </th>

                <th style="width: 220px">
                  Return to Service
                </th>

                <th>
                  Qty
                </th>

                <th>
                  Date
                </th>

              <!-- ITEM LOCATOR VIEW -->
              <template v-if="isLocationView">
                <th>Category</th><th>Qty Out</th><th>Requester</th><th>Request #</th>
                <th>Location</th><th>Room</th><th>Purpose</th><th>Borrowed On</th>
              </template>

            </tr>

          </thead>


          <!-- TABLE BODY -->

          <tbody>



            <!-- NORMAL INVENTORY -->


            <template v-if="
              !isLocationView &&
              !isDamagedView &&
              !isMaintenanceView
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
                  {{ item.qty }}
                </td>

              </tr>


              <tr v-if="
                filteredItems.length === 0
              ">

                <td colspan="4" class="text-center text-muted py-4">
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

              </tr>

              <tr v-if="filteredDamagedItems.length === 0">
                <td colspan="4" class="text-center text-muted py-4">
                  No damaged items on record.
                </td>
              </tr>

            </template>

            <!-- MAINTENANCE ITEMS VIEW -->

            <template v-else-if="isMaintenanceView">

              <tr v-for="item in filteredMaintenanceItems" :key="item.id">

                <td>
                  {{ item.name }}
                </td>

                <td>
                  {{ item.category }}
                </td>

                <td>
                  <span class="badge bg-warning text-dark">
                    {{ item.maintenance_quantity }}
                  </span>

                </td>

                <td>
                  {{ row.borrower }}
                </td>

                <td>
                  {{ item.qty }}
                </td>

                <td>
                  <div class="d-flex gap-2">
                    <input
                      type="number"
                      min="1"
                      :max="item.maintenance_quantity"
                      class="form-control form-control-sm"
                      style="width: 80px"
                      v-model="returnQtyInputs[item.id]"
                      placeholder="Qty"
                    />
                    <button
                      class="btn btn-sm btn-success"
                      @click="handleReturnToService(item)"
                    >
                      Return to Service
                    </button>
                  </div>
                </td>

              </tr>

              <tr v-if="filteredMaintenanceItems.length === 0">
                <td colspan="5" class="text-center text-muted py-4">
                  No items currently under maintenance.
                </td>
              </tr>

            </template>



            <!-- ITEM LOCATOR VIEW -->
            <template v-else-if="isLocationView">

              <tr v-for="row in filteredBorrowedRows" :key="row.id">

                <td>
                  {{ row.name }}
                </td>

                <td>
                  {{ row.borrower }}
                </td>

                <td>

                  {{ row.location }}

                  <small v-if="row.room" class="text-muted d-block">
                    {{ row.room }}
                  </small>

                </td>

                <td>
                  {{ row.qty }}
                </td>

                <td>
                  {{ formatDate(row.date) }}
                </td>

              </tr>


              <tr v-if="
                filteredBorrowedRows.length === 0
              ">

                <td colspan="5" class="text-center text-muted py-4">
                  No item locations found.
                </td>

              </tr>

            </template>

          </tbody>

        </table>

      </div>

    </div>

  </div>

    <div v-if="confirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm Return to Service</h5>
          <button
            type="button"
            class="btn-close"
            @click="closeConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p class="mb-0">
            Return {{ pendingReturnQty }}x
            "{{ pendingReturnItem?.name }}"
            to available stock from maintenance?
          </p>
        </div>

        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-secondary"
            @click="closeConfirm"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-success"
            @click="confirmReturnToService"
          >
            Confirm
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
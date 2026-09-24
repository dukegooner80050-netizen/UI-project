<script setup>
import { ref, computed, onMounted } from "vue";
import { getItems } from "../services/items";
import { getRequests } from "../services/requests";
import { returnToService } from "../services/inspections";

const search = ref("");
const statusFilter = ref("ALL");
const borrowerFilter = ref("ALL");
const viewMode = ref("ALL_ITEMS");

const items = ref([]);
const requests = ref([]);

const isBorrowedView = computed(() => viewMode.value === "BORROWED");
const isLocationView = computed(() => viewMode.value === "LOCATIONS");
// The Status filter already had a "Damaged" option, but nothing ever set
// item.status to "Damaged" -- damaged items are tracked separately via
// damaged_quantity (from the Return Inspection feature). When that filter
// is selected, show a dedicated damaged-items table instead. Displayed as
// "Disposal" in the UI, matching the renamed inspection outcome.
const isDamagedView = computed(
  () => !isBorrowedView.value && !isLocationView.value && statusFilter.value === "Damaged",
);

const damagedItems = computed(() =>
  items.value.filter((item) => Number(item.damaged_quantity) > 0),
);

// Items marked "Need Maintenance" during inspection. Unlike Disposal,
// these can be moved back into available stock later via "Return to
// Service" once repaired.
const isMaintenanceView = computed(
  () => !isBorrowedView.value && !isLocationView.value && statusFilter.value === "Maintenance",
);

const maintenanceItems = computed(() =>
  items.value.filter((item) => Number(item.maintenance_quantity) > 0),
);

const returnQtyInputs = ref({}); // { [itemId]: quantity }

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function handleReturnToService(item) {
  const qty = Number(returnQtyInputs.value[item.id]) || 0;
  if (qty < 1) {
    alert("Enter a quantity of at least 1.");
    return;
  }
  if (qty > Number(item.maintenance_quantity)) {
    alert(`Only ${item.maintenance_quantity} unit(s) are currently under maintenance.`);
    return;
  }
  if (
    !confirm(
      `Return ${qty}x "${item.name}" to available stock from maintenance?`,
    )
  )
    return;

  try {
    await returnToService(item.id, qty);
    returnQtyInputs.value[item.id] = "";
    await loadData();
  } catch (e) {
    alert(errMsg(e));
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

    <h3 class="mb-4">
      Inventory
    </h3>


    <!-- FILTERS -->

    <div class="row mb-3">

      <!-- SEARCH -->

      <div :class="isBorrowedView || isLocationView
        ? 'col-md-5'
        : 'col-md-6'
        ">

        <input v-model="search" class="form-control"
          placeholder="Search item name, category, borrower, or location..." />

      </div>


      <!-- STATUS -->

      <div v-if="!isBorrowedView && !isLocationView" class="col-md-3">

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


      <!-- BORROWER FILTER -->

      <div v-if="isBorrowedView || isLocationView" class="col-md-3">

        <select v-model="borrowerFilter" class="form-select">

          <option value="ALL">
            All Borrowers
          </option>

          <option v-for="borrower in borrowerOptions" :key="borrower" :value="borrower">
            {{ borrower }}
          </option>

        </select>

      </div>


      <!-- VIEW MODE -->

      <div :class="isBorrowedView || isLocationView
        ? 'col-md-4'
        : 'col-md-3'
        ">

        <select v-model="viewMode" class="form-select">

          <option value="ALL_ITEMS">
            All Items
          </option>

          <option value="BORROWED">
            Borrowed Items
          </option>

          <option value="LOCATIONS">
            Item Locations
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
                !isBorrowedView &&
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


              <!-- BORROWED VIEW -->

              <template v-if="isBorrowedView">

                <th>
                  Category
                </th>

                <th>
                  Borrowed Qty
                </th>

                <th>
                  Borrower
                </th>

                <th>
                  Location
                </th>

                <th>
                  Date
                </th>

              </template>


              <!-- LOCATION VIEW -->

              <template v-if="isLocationView">

                <th>
                  Borrower
                </th>

                <th>
                  Location
                </th>

                <th>
                  Room
                </th>

                <th>
                  Qty
                </th>

                <th>
                  Date
                </th>

              </template>

            </tr>

          </thead>


          <!-- TABLE BODY -->

          <tbody>



            <!-- NORMAL INVENTORY -->


            <template v-if="
              !isBorrowedView &&
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



            <!-- BORROWED VIEW -->


            <template v-else-if="isBorrowedView">

              <tr v-for="row in filteredBorrowedRows" :key="row.id">

                <td>
                  {{ row.name }}
                </td>

                <td>
                  {{ row.category }}
                </td>

                <td>

                  <span class="badge bg-primary">
                    {{ row.qty }}
                  </span>

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
                  {{ formatDate(row.date) }}
                </td>

              </tr>


              <tr v-if="
                filteredBorrowedRows.length === 0
              ">

                <td colspan="6" class="text-center text-muted py-4">
                  No borrowed items found.
                </td>

              </tr>

            </template>

            <!-- LOCATION VIEW -->

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

    <!-- DELETE BUTTON -->

    <div class="mt-3 text-end">

      <button class="btn btn-danger" disabled>
        Delete Inventory Data
        (Coming Soon)
      </button>

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
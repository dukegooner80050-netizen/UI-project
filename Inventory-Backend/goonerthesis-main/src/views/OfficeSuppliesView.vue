<script setup>
const modalOpen = ref(false);
const modalMode = ref("");
const modalQty = ref(1);

import { ref, computed, onMounted } from "vue";
import {
  listInventory,
  addItem,
  updateItem,
  autoStatus,
  restockOfficeSupplies,
  releaseOfficeConsumables,
  borrowNonConsumables,
} from "../services/inventory";
import { getRequests, returnEquipment } from "../services/requests";

const modalError = ref("");
const addError = ref("");

// STATES
const activeTab = ref("inventory");
const selectedSupply = ref(null);

const items = ref([]);
const currentItem = ref(null);
const addOpen = ref(false);
const addSubCategory = ref("Consumable");
const addName = ref("");
const addQty = ref(1);
const editName = ref("");
const confirmAction = ref(false);

function openAdd() {
  addSubCategory.value = "Consumable";
  addName.value = "";
  addQty.value = 1;
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

  try {
    await addItem({
      item_name: name,
      category: "Office Supplies",
      item_type: addSubCategory.value,
      quantity: qty,
    });

    await refresh();
    closeAdd();
  } catch (e) {
    alert(String(e.message || e));
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
        (i) => i.iditems === reqItem.itemId && i.item_type === "Non-Consumable",
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
      });
    });
  });
  items.value = officeInventory;
}

const borrowedSupplies = computed(() =>
  nonConsumables.value
    .filter((i) => Number(i.borrowedQty) > 0)
    .sort((a, b) => a.item_name.localeCompare(b.item_name)),
);

const supplyBorrowers = computed(
  () => selectedSupply.value?.requestItems || [],
);

const officeItems = computed(() =>
  items.value.filter((i) => i.category === "Office Supplies"),
);

const consumables = computed(() =>
  officeItems.value.filter((i) => i.item_type === "Consumable"),
);

const nonConsumables = computed(() =>
  officeItems.value.filter((i) => i.item_type === "Non-Consumable"),
);

function openQtyModal(mode, item) {
  modalMode.value = mode;
  currentItem.value = item;
  modalQty.value = 1;
  editName.value = item.item_name;
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

      await updateItem(currentItem.value.iditems, {
        item_name: editName.value.trim(),
        description: currentItem.value.description,
        category: currentItem.value.category,
        price: Number(currentItem.value.price),
        item_type: currentItem.value.item_type,
        quantity: Number(currentItem.value.quantity),
        status: currentItem.value.status,
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
    console.log(e.response?.data);
    alert(
      e.response?.data?.message ||
        JSON.stringify(e.response?.data?.errors) ||
        e.message,
    );
  }
}
</script>

<template>
  <div>
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
    <div v-if="activeTab === 'inventory'">
      <!-- CONSUMABLES -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div
            class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"
          >
            <h5 class="mb-0">Consumables</h5>
          </div>

          <div class="table-responsive table-scroll">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Status</th>
                  <th style="width: 120px">Qty</th>
                  <th style="width: 180px; text-align: center">Acton</th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="i in consumables" :key="i.iditems">
                  <td>{{ i.item_name }}</td>
                  <td>{{ i.status }}</td>
                  <td>{{ i.quantity }}</td>
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
                    </div>
                  </td>
                </tr>

                <tr v-if="consumables.length === 0">
                  <td colspan="4" class="text-center text-muted">
                    No consumables found.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- NON-CONSUMABLES -->
      <div class="card shadow-sm">
        <div class="card-body">
          <div
            class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"
          >
            <h5 class="mb-0">Non-Consumables</h5>
          </div>

          <div class="table-responsive table-scroll">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Name</th>
                  <th>Status</th>
                  <th style="width: 120px">Qty</th>
                  <th style="width: 120px">Borrowed</th>
                  <th style="width: 180px; text-align: center">Acton</th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="i in nonConsumables" :key="i.iditems">
                  <td>{{ i.item_name }}</td>
                  <td>{{ i.status }}</td>
                  <td>{{ i.quantity }}</td>
                  <td>{{ Number(i.borrowedQty) || 0 }}</td>
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
                    </div>
                  </td>
                </tr>

                <tr v-if="nonConsumables.length === 0">
                  <td colspan="5" class="text-center text-muted">
                    No non-consumables found.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
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
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in borrowedSupplies" :key="item.iditems">
                <td>{{ item.item_name }}</td>
                <td>{{ item.status }}</td>
                <td>{{ item.borrowedQty }}</td>
                <td>
                  <button
                    class="btn btn-success btn-sm"
                    @click="selectedSupply = item"
                  >
                    View Borrowers
                  </button>
                </td>
              </tr>
              <tr v-if="borrowedSupplies.length === 0">
                <td colspan="4" class="text-center text-muted">
                  No borrowed office supplies.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <!-- RIGHT -->
      <div class="card shadow-sm" style="flex: 1">
        <div class="card-body">
          <h5>Borrower Details</h5>
          <div v-if="selectedSupply">
            <div
              v-for="borrower in supplyBorrowers"
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
                <strong>Borrowed:</strong>
                {{ borrower.borrowedQty }}
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
          <div v-else class="text-center text-muted mt-5">
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
            <label class="form-label">Item Name</label>
            <input class="form-control mb-3" v-model="editName" />
          </template>
          <template v-else>
            <label class="form-label">Quantity</label>
            <input
              type="number"
              min="1"
              :max="modalMax || undefined"
              class="form-control"
              v-model="modalQty"
            />
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
        <label class="form-label">Type</label>
        <select class="form-select mb-3" v-model="addSubCategory">
          <option value="Consumable">Consumables</option>
          <option value="Non-Consumable">Non-Consumables</option>
        </select>

        <label class="form-label">Name</label>
        <input
          class="form-control mb-3"
          v-model="addName"
          placeholder="e.g. Bond Paper"
        />

        <label class="form-label">Quantity</label>
        <input type="number" min="1" class="form-control" v-model="addQty" />
        <div class="small text-muted mt-2">
          Consumables will auto-update status depending on qty.
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
              Rename
              <strong class="highlight">{{ currentItem.item_name }}</strong>
              to
              <strong class="highlight">{{ editName }}</strong
              >?
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
  width: 100%;
  max-width: 520px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
  overflow: hidden;
}
.modal-header,
.modal-footer {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.modal-body {
  padding: 16px;
}
.highlight {
  text-decoration: underline;
}
</style>

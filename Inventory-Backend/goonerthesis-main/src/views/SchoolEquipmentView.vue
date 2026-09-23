<script setup>
import { ref, computed, onMounted } from "vue";
import {
  getItems,
  createItem,
  updateItem,
  restockItem,
} from "../services/items";
import { getRequests, returnEquipment } from "../services/requests";

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
const confirmAction = ref(false);

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

  try {
    await createItem({
      item_name: name,
      category: "School Equipment",
      item_type: "Equipment",
      quantity: qty,
      status: "Available",
    });

    await load();
    closeAdd();
  } catch (e) {
    alert(String(e.message || e));
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
      });
    });
  });
  items.value = equipments;
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

function openModal(mode, item) {
  modalMode.value = mode;
  currentItem.value = item;
  modalQty.value = 1;
  editName.value = item.name;
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
      await updateItem(currentItem.value.id, {
        item_name: editName.value,
        description: currentItem.value.description,
        category: currentItem.value.category,
        price: currentItem.value.price,
        item_type: currentItem.value.item_type,
        quantity: currentItem.value.qty,
        status: currentItem.value.status,
      });
    }
    await load();
    closeModal();
  } catch (e) {
    alert(String(e.message || e));
  }
}
</script>

<template>
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
              <th style="width: 120px">Available</th>
              <th style="width: 120px">Borrowed</th>
              <th style="width: 220px; text-align: center">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="e in equipments" :key="e.iditems">
              <td>{{ e.name }}</td>
              <td>{{ e.status }}</td>
              <td>{{ e.qty }}</td>
              <td>{{ Number(e.borrowedQty) || 0 }}</td>
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
                </div>
              </td>
            </tr>

            <tr v-if="!equipments.length">
              <td colspan="5" class="text-center text-muted">
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
              <th style="width: 160px">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="equipment in borrowedEquipments" :key="equipment.id">
              <td>{{ equipment.name }}</td>
              <td>{{ equipment.status }}</td>
              <td>{{ equipment.borrowedQty }}</td>
              <td>
                <button
                  class="btn btn-success btn-sm"
                  @click="selectedEquipment = equipment"
                >
                  View Borrowers
                </button>
              </td>
            </tr>
            <tr v-if="borrowedEquipments.length === 0">
              <td colspan="4" class="text-center text-muted py-4">
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
                : `Return: ${currentItem?.name}`
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
              Rename
              <strong class="highlight">{{ currentItem.name }}</strong>
              to
              <strong class="highlight">{{ editName }}</strong
              >?
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

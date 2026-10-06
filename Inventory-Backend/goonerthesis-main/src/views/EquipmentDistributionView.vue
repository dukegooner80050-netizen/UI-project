<script setup>
import { ref, computed, onMounted } from "vue";
import {
  getBuildings,
  createBuilding,
  updateBuilding,
  deleteBuilding,
} from "../services/buildings";
import { getRooms, createRoom, updateRoom, deleteRoom } from "../services/rooms";
import {
  getRoomEquipment,
  assignRoomEquipment,
  returnRoomEquipment,
  removeRoomEquipment,
} from "../services/roomEquipment";
import { listInventory } from "../services/inventory";
import AlertMessage from "../components/AlertMessage.vue";

const loading = ref(true);
const buildings = ref([]);
const rooms = ref([]);
const equipmentItems = ref([]); // all School Equipment items (for the "add" dropdown)
const roomLoadout = ref([]); // equipment currently assigned to the selected room

const selectedBuildingId = ref(null);
const selectedRoomId = ref(null);

const newBuildingName = ref("");
const editingBuildingId = ref(null);
const editingBuildingName = ref("");

const newRoomName = ref("");
const editingRoomId = ref(null);
const editingRoomName = ref("");

const addItemId = ref("");
const addItemQty = ref(1);

// Return modal (take some or all of an assignment back out of the room)
const returnOpen = ref(false);
const returnEntry = ref(null);
const returnQty = ref(1);
const returnStep = ref("form"); // "form" -> "confirm"
const returning = ref(false);

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

const confirmModalOpen = ref(false);
const confirmActionType = ref("");
const confirmActionMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}

function errMsg(e) {
  return e?.response?.data?.message || e?.message || "Something went wrong.";
}

async function loadBuildings() {
  buildings.value = await getBuildings();
}

async function loadRoomsForBuilding(buildingId) {
  rooms.value = buildingId ? await getRooms(buildingId) : [];
}

async function loadEquipmentItems() {
  const inventory = await listInventory();
  equipmentItems.value = inventory.filter((i) => i.category === "School Equipment");
}

async function loadRoomLoadout(roomId) {
  roomLoadout.value = roomId ? await getRoomEquipment(roomId) : [];
}

onMounted(async () => {
  loading.value = true;
  try {
    await Promise.all([loadBuildings(), loadEquipmentItems()]);
  } catch (e) {
    notify("danger", errMsg(e));
  } finally {
    loading.value = false;
  }
});

async function selectBuilding(building) {
  selectedBuildingId.value = building.idbuilding;
  selectedRoomId.value = null;
  roomLoadout.value = [];
  try {
    await loadRoomsForBuilding(building.idbuilding);
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

async function selectRoom(room) {
  selectedRoomId.value = room.idroom;
  try {
    await loadRoomLoadout(room.idroom);
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

/* ===== BUILDING CRUD ===== */
function addBuilding() {
  const name = newBuildingName.value.trim();
  if (!name) return;
  openConfirm("addBuilding", `Add building "${name}"?`);
}

async function addBuildingConfirmed() {
  try {
    await createBuilding({ building_name: newBuildingName.value.trim() });
    newBuildingName.value = "";
    await loadBuildings();
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

function startEditBuilding(building) {
  editingBuildingId.value = building.idbuilding;
  editingBuildingName.value = building.building_name;
}

function saveEditBuilding() {
  const name = editingBuildingName.value.trim();
  if (!name) {
    notify("warning", "Building name cannot be empty.");
    return;
  }
  openConfirm("saveBuilding", `Rename this building to "${name}"?`);
}

async function saveEditBuildingConfirmed() {
  try {
    await updateBuilding(editingBuildingId.value, {
      building_name: editingBuildingName.value.trim(),
    });
    editingBuildingId.value = null;
    await loadBuildings();
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

function openConfirm(type, message) {
  confirmActionType.value = type;
  confirmActionMessage.value = message;
  confirmModalOpen.value = true;
}

function closeConfirm() {
  confirmModalOpen.value = false;
  confirmActionType.value = "";
  confirmActionMessage.value = "";
}

async function confirmAction() {
  const type = confirmActionType.value;
  closeConfirm();

  if (type === "deleteBuilding") {
    await deleteBuildingConfirmed();
  } else if (type === "deleteRoom") {
    await deleteRoomConfirmed();
  } else if (type === "removeEquipment") {
    await removeEquipmentConfirmed();
  } else if (type === "assignEquipment") {
    await assignEquipmentConfirmed();
  } else if (type === "addBuilding") {
    await addBuildingConfirmed();
  } else if (type === "saveBuilding") {
    await saveEditBuildingConfirmed();
  } else if (type === "addRoom") {
    await addRoomConfirmed();
  } else if (type === "saveRoom") {
    await saveEditRoomConfirmed();
  }
}

// Only the destructive actions get a red confirm button.
const confirmIsDanger = computed(() =>
  ["deleteBuilding", "deleteRoom", "removeEquipment"].includes(
    confirmActionType.value,
  ),
);

async function removeBuilding(building) {
  openConfirm(
    "deleteBuilding",
    `Delete building "${building.building_name}"?`,
  );
  pendingBuilding.value = building;
}

const pendingBuilding = ref(null);
const pendingRoom = ref(null);
const pendingEquipment = ref(null);

async function deleteBuildingConfirmed() {
  const building = pendingBuilding.value;
  pendingBuilding.value = null;
  if (!building) return;
  try {
    await deleteBuilding(building.idbuilding);
    if (selectedBuildingId.value === building.idbuilding) {
      selectedBuildingId.value = null;
      selectedRoomId.value = null;
      rooms.value = [];
      roomLoadout.value = [];
    }
    await loadBuildings();
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

/* ===== ROOM CRUD ===== */
function addRoom() {
  const name = newRoomName.value.trim();
  if (!name || !selectedBuildingId.value) return;
  openConfirm(
    "addRoom",
    `Add room "${name}" to ${selectedBuilding.value?.building_name || "this building"}?`,
  );
}

async function addRoomConfirmed() {
  try {
    await createRoom({
      idbuilding: selectedBuildingId.value,
      room_name: newRoomName.value.trim(),
    });
    newRoomName.value = "";
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

function startEditRoom(room) {
  editingRoomId.value = room.idroom;
  editingRoomName.value = room.room_name;
}

function saveEditRoom() {
  const name = editingRoomName.value.trim();
  if (!name) {
    notify("warning", "Room name cannot be empty.");
    return;
  }
  openConfirm("saveRoom", `Rename this room to "${name}"?`);
}

async function saveEditRoomConfirmed() {
  try {
    await updateRoom(editingRoomId.value, {
      idbuilding: selectedBuildingId.value,
      room_name: editingRoomName.value.trim(),
    });
    editingRoomId.value = null;
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

async function removeRoom(room) {
  pendingRoom.value = room;
  openConfirm(
    "deleteRoom",
    `Delete room "${room.room_name}"?`,
  );
}

async function deleteRoomConfirmed() {
  const room = pendingRoom.value;
  pendingRoom.value = null;
  if (!room) return;
  try {
    await deleteRoom(room.idroom);
    if (selectedRoomId.value === room.idroom) {
      selectedRoomId.value = null;
      roomLoadout.value = [];
    }
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

/* ===== ROOM EQUIPMENT (LOADOUT) ===== */
// Step 1: validate, then ask the user to confirm.
function addEquipmentToRoom() {
  const qty = Number(addItemQty.value) || 0;

  if (!addItemId.value) {
    notify("warning", "Select an item to assign.");
    return;
  }
  if (qty < 1) {
    notify("warning", "Quantity must be at least 1.");
    return;
  }
  if (addItemAvailable.value !== null && qty > addItemAvailable.value) {
    notify("warning", `Only ${addItemAvailable.value} available in inventory.`);
    return;
  }

  const item = equipmentItems.value.find(
    (i) => String(i.iditems) === String(addItemId.value),
  );
  const roomName = selectedRoom.value?.room_name || "this room";

  openConfirm(
    "assignEquipment",
    `Assign ${qty} x "${item?.item_name || "item"}" to ${roomName}? It will be taken out of available inventory.`,
  );
}

// Step 2: the user confirmed, save it.
async function assignEquipmentConfirmed() {
  try {
    await assignRoomEquipment(selectedRoomId.value, {
      iditems: addItemId.value,
      quantity: Number(addItemQty.value),
    });
    addItemId.value = "";
    addItemQty.value = 1;
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
    notify("success", "Equipment assigned to room.");
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

/* ----- RETURN (replaces the old Edit button) ----- */

function openReturn(entry) {
  returnEntry.value = entry;
  returnQty.value = 1;
  returnStep.value = "form";
  returnOpen.value = true;
}

function closeReturn() {
  if (returning.value) return;
  returnOpen.value = false;
  returnEntry.value = null;
  returnStep.value = "form";
}

// Step 1: check the amount, then show the confirmation screen.
function requestReturn() {
  const qty = Number(returnQty.value) || 0;
  const max = Number(returnEntry.value?.quantity) || 0;

  if (qty < 1) {
    notify("warning", "Quantity must be at least 1.");
    return;
  }
  if (qty > max) {
    notify("warning", `Only ${max} unit(s) are assigned to this room.`);
    return;
  }
  returnStep.value = "confirm";
}

// Step 2: the user confirmed, send it to Pending Inspection.
async function confirmReturn() {
  const entry = returnEntry.value;
  if (!entry) return;

  returning.value = true;
  try {
    await returnRoomEquipment(
      selectedRoomId.value,
      entry.idroomequipment,
      Number(returnQty.value),
    );
    returning.value = false;
    closeReturn();
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
    notify("success", "Returned and sent to Pending Inspection.");
  } catch (e) {
    returning.value = false;
    returnStep.value = "form";
    notify("danger", errMsg(e));
  }
}

function removeEquipmentFromRoom(entry) {
  pendingEquipment.value = entry;
  openConfirm(
    "removeEquipment",
    `Remove all ${entry.quantity} x "${entry.item_name}" from this room? It will be sent to Pending Inspection before it goes back into inventory.`,
  );
}

async function removeEquipmentConfirmed() {
  const entry = pendingEquipment.value;
  pendingEquipment.value = null;
  if (!entry) return;
  try {
    await removeRoomEquipment(selectedRoomId.value, entry.idroomequipment);
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
    notify("success", "Removed from room and sent to Pending Inspection.");
  } catch (e) {
    notify("danger", errMsg(e));
  }
}

const selectedBuilding = computed(() =>
  buildings.value.find((b) => b.idbuilding === selectedBuildingId.value),
);
const selectedRoom = computed(() =>
  rooms.value.find((r) => r.idroom === selectedRoomId.value),
);

// Available stock for the item currently picked in the "add equipment" dropdown
const addItemAvailable = computed(() => {
  const item = equipmentItems.value.find(
    (i) => String(i.iditems) === String(addItemId.value),
  );
  return item ? Number(item.qty) || 0 : null;
});
</script>

<template>
  <div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

    <h3 class="mb-1">Equipment Distribution</h3>
    <p class="text-muted mb-4">
      Manage buildings and rooms, and distribute School Equipment to a room.
      Distributing equipment here reserves it from the general available stock.
    </p>

    <div v-if="loading" class="text-center text-muted py-4">Loading...</div>

    <div v-else class="row g-3">
      <!-- BUILDINGS PANEL -->
      <div class="col-md-3">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-3">Buildings</h6>

            <div class="list-group mb-3">
              <div
                v-for="b in buildings"
                :key="b.idbuilding"
                class="list-group-item d-flex justify-content-between align-items-center"
                :class="{ active: selectedBuildingId === b.idbuilding }"
                style="cursor: pointer"
              >
                <template v-if="editingBuildingId === b.idbuilding">
                  <input
                    class="form-control form-control-sm me-2"
                    v-model="editingBuildingName"
                    @keyup.enter="saveEditBuilding"
                  />
                  <button class="btn btn-sm btn-success" @click="saveEditBuilding">
                    Save
                  </button>
                </template>
                <template v-else>
                  <span @click="selectBuilding(b)" class="flex-grow-1">{{
                    b.building_name
                  }}</span>
                  <div class="d-flex gap-1">
                    <button
                      class="btn btn-sm btn-outline-secondary"
                      @click.stop="startEditBuilding(b)"
                    >
                      Edit
                    </button>
                    <button
                      class="btn btn-sm btn-outline-danger"
                      @click.stop="removeBuilding(b)"
                    >
                      &times;
                    </button>
                  </div>
                </template>
              </div>

              <div v-if="buildings.length === 0" class="list-group-item text-muted">
                No buildings yet.
              </div>
            </div>

            <div class="input-group input-group-sm">
              <input
                class="form-control"
                v-model="newBuildingName"
                placeholder="New building name"
                @keyup.enter="addBuilding"
              />
              <button class="btn btn-primary" @click="addBuilding">Add</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ROOMS PANEL -->
      <div class="col-md-3">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-3">
              Rooms
              <span v-if="selectedBuilding" class="text-muted small">
                in {{ selectedBuilding.building_name }}
              </span>
            </h6>

            <div v-if="!selectedBuildingId" class="text-muted small">
              Select a building to see its rooms.
            </div>

            <template v-else>
              <div class="list-group mb-3">
                <div
                  v-for="r in rooms"
                  :key="r.idroom"
                  class="list-group-item d-flex justify-content-between align-items-center"
                  :class="{ active: selectedRoomId === r.idroom }"
                  style="cursor: pointer"
                >
                  <template v-if="editingRoomId === r.idroom">
                    <input
                      class="form-control form-control-sm me-2"
                      v-model="editingRoomName"
                      @keyup.enter="saveEditRoom"
                    />
                    <button class="btn btn-sm btn-success" @click="saveEditRoom">
                      Save
                    </button>
                  </template>
                  <template v-else>
                    <span @click="selectRoom(r)" class="flex-grow-1">{{
                      r.room_name
                    }}</span>
                    <div class="d-flex gap-1">
                      <button
                        class="btn btn-sm btn-outline-secondary"
                        @click.stop="startEditRoom(r)"
                      >
                        Edit
                      </button>
                      <button
                        class="btn btn-sm btn-outline-danger"
                        @click.stop="removeRoom(r)"
                      >
                        &times;
                      </button>
                    </div>
                  </template>
                </div>

                <div v-if="rooms.length === 0" class="list-group-item text-muted">
                  No rooms in this building yet.
                </div>
              </div>

              <div class="input-group input-group-sm">
                <input
                  class="form-control"
                  v-model="newRoomName"
                  placeholder="New room name"
                  @keyup.enter="addRoom"
                />
                <button class="btn btn-primary" @click="addRoom">Add</button>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- ROOM LOADOUT PANEL -->
      <div class="col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <h6 class="mb-3">
              Equipment Distribution
              <span v-if="selectedRoom" class="text-muted small">
                &mdash; {{ selectedRoom.room_name }}
              </span>
            </h6>

            <div v-if="!selectedRoomId" class="text-muted small">
              Select a room to view and manage its assigned equipment.
            </div>

            <template v-else>
              <div class="table-responsive mb-3">
                <table class="table table-striped table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Item</th>
                      <th style="width: 140px">Quantity</th>
                      <th style="width: 160px"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="entry in roomLoadout" :key="entry.idroomequipment">
                      <td>{{ entry.item_name }}</td>
                      <td>{{ entry.quantity }}</td>
                      <td>
                        <div class="d-flex gap-1">
                          <button
                            class="btn btn-sm btn-outline-primary"
                            @click="openReturn(entry)"
                          >
                            Return
                          </button>
                          <button
                            class="btn btn-sm btn-outline-danger"
                            @click="removeEquipmentFromRoom(entry)"
                          >
                            Remove
                          </button>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="roomLoadout.length === 0">
                      <td colspan="3" class="text-center text-muted py-4">
                        No equipment assigned to this room yet.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="border rounded p-3">
                <div class="fw-semibold mb-2 small">Assign Equipment</div>
                <div class="row g-2 align-items-end">
                  <div class="col-6">
                    <select class="form-select form-select-sm" v-model="addItemId">
                      <option value="" disabled>Select item...</option>
                      <option
                        v-for="item in equipmentItems"
                        :key="item.iditems"
                        :value="item.iditems"
                      >
                        {{ item.item_name }} ({{ item.qty }} available)
                      </option>
                    </select>
                  </div>
                  <div class="col-3">
                    <input
                      type="number"
                      min="1"
                      :max="addItemAvailable ?? undefined"
                      class="form-control form-control-sm"
                      v-model.number="addItemQty"
                      placeholder="Qty"
                    />
                  </div>
                  <div class="col-3">
                    <button
                      class="btn btn-sm btn-primary w-100"
                      @click="addEquipmentToRoom"
                    >
                      Assign
                    </button>
                  </div>
                </div>
                <div
                  v-if="addItemAvailable !== null"
                  class="text-muted small mt-1"
                >
                  {{ addItemAvailable }} currently available in inventory.
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </div>


    <!-- RETURN MODAL -->
    <div v-if="returnOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Return: {{ returnEntry?.item_name }}</h5>
          <button
            type="button"
            class="btn-close"
            :disabled="returning"
            @click="closeReturn"
          ></button>
        </div>

        <div class="modal-body">
          <template v-if="returnStep === 'form'">
            <label class="form-label">Return Quantity</label>
            <input
              type="number"
              min="1"
              :max="returnEntry?.quantity"
              class="form-control"
              v-model.number="returnQty"
              @keyup.enter="requestReturn"
            />
            <small class="text-muted">
              Max: {{ returnEntry?.quantity }}. Returned items go to Pending
              Inspection first.
            </small>
          </template>

          <!-- CONFIRMATION SCREEN -->
          <div v-else class="alert alert-warning mb-0">
            <h6 class="mb-3">Confirm Action</h6>
            Return
            <strong>{{ returnQty }}</strong>
            x
            <strong>{{ returnEntry?.item_name }}</strong>
            from
            <strong>{{ selectedRoom?.room_name }}</strong>
            and send it to Pending Inspection?
          </div>
        </div>

        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-secondary"
            :disabled="returning"
            @click="returnStep === 'confirm' ? (returnStep = 'form') : closeReturn()"
          >
            {{ returnStep === "confirm" ? "Back" : "Cancel" }}
          </button>
          <button
            v-if="returnStep === 'form'"
            type="button"
            class="btn btn-primary"
            @click="requestReturn"
          >
            Continue
          </button>
          <button
            v-else
            type="button"
            class="btn btn-success"
            :disabled="returning"
            @click="confirmReturn"
          >
            {{ returning ? "Returning..." : "Confirm" }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="confirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm Action</h5>
          <button
            type="button"
            class="btn-close"
            @click="closeConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p class="mb-0">{{ confirmActionMessage }}</p>
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
            class="btn"
            :class="confirmIsDanger ? 'btn-danger' : 'btn-primary'"
            @click="confirmAction"
          >
            Confirm
          </button>
        </div>
      </div>
    </div>
</template>


<style scoped>
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1050;
}

.modal-custom {
  width: min(500px, calc(100% - 2rem));
  background: #fff;
  border-radius: 0.5rem;
  box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.modal-custom .modal-header,
.modal-custom .modal-footer {
  padding: 1rem;
}

.modal-custom .modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #dee2e6;
}

.modal-custom .modal-body {
  padding: 1rem;
}

.modal-custom .modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  border-top: 1px solid #dee2e6;
}
</style>

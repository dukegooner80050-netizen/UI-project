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
  updateRoomEquipment,
  removeRoomEquipment,
} from "../services/roomEquipment";
import { listInventory } from "../services/inventory";

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

const editingEquipmentId = ref(null);
const editingEquipmentQty = ref(1);

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
    alert(errMsg(e));
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
    alert(errMsg(e));
  }
}

async function selectRoom(room) {
  selectedRoomId.value = room.idroom;
  try {
    await loadRoomLoadout(room.idroom);
  } catch (e) {
    alert(errMsg(e));
  }
}

/* ===== BUILDING CRUD ===== */
async function addBuilding() {
  if (!newBuildingName.value.trim()) return;
  try {
    await createBuilding({ building_name: newBuildingName.value.trim() });
    newBuildingName.value = "";
    await loadBuildings();
  } catch (e) {
    alert(errMsg(e));
  }
}

function startEditBuilding(building) {
  editingBuildingId.value = building.idbuilding;
  editingBuildingName.value = building.building_name;
}

async function saveEditBuilding() {
  try {
    await updateBuilding(editingBuildingId.value, {
      building_name: editingBuildingName.value.trim(),
    });
    editingBuildingId.value = null;
    await loadBuildings();
  } catch (e) {
    alert(errMsg(e));
  }
}

async function removeBuilding(building) {
  if (!confirm(`Delete building "${building.building_name}"?`)) return;
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
    alert(errMsg(e));
  }
}

/* ===== ROOM CRUD ===== */
async function addRoom() {
  if (!newRoomName.value.trim() || !selectedBuildingId.value) return;
  try {
    await createRoom({
      idbuilding: selectedBuildingId.value,
      room_name: newRoomName.value.trim(),
    });
    newRoomName.value = "";
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    alert(errMsg(e));
  }
}

function startEditRoom(room) {
  editingRoomId.value = room.idroom;
  editingRoomName.value = room.room_name;
}

async function saveEditRoom() {
  try {
    await updateRoom(editingRoomId.value, {
      idbuilding: selectedBuildingId.value,
      room_name: editingRoomName.value.trim(),
    });
    editingRoomId.value = null;
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    alert(errMsg(e));
  }
}

async function removeRoom(room) {
  if (!confirm(`Delete room "${room.room_name}"?`)) return;
  try {
    await deleteRoom(room.idroom);
    if (selectedRoomId.value === room.idroom) {
      selectedRoomId.value = null;
      roomLoadout.value = [];
    }
    await loadRoomsForBuilding(selectedBuildingId.value);
  } catch (e) {
    alert(errMsg(e));
  }
}

/* ===== ROOM EQUIPMENT (LOADOUT) ===== */
async function addEquipmentToRoom() {
  if (!addItemId.value || !addItemQty.value || addItemQty.value < 1) return;
  try {
    await assignRoomEquipment(selectedRoomId.value, {
      iditems: addItemId.value,
      quantity: addItemQty.value,
    });
    addItemId.value = "";
    addItemQty.value = 1;
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
  } catch (e) {
    alert(errMsg(e));
  }
}

function startEditEquipment(entry) {
  editingEquipmentId.value = entry.idroomequipment;
  editingEquipmentQty.value = entry.quantity;
}

async function saveEditEquipment(entry) {
  try {
    await updateRoomEquipment(selectedRoomId.value, entry.idroomequipment, {
      quantity: editingEquipmentQty.value,
    });
    editingEquipmentId.value = null;
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
  } catch (e) {
    alert(errMsg(e));
  }
}

async function removeEquipmentFromRoom(entry) {
  if (!confirm(`Remove "${entry.item_name}" from this room? This restores its stock.`))
    return;
  try {
    await removeRoomEquipment(selectedRoomId.value, entry.idroomequipment);
    await Promise.all([
      loadRoomLoadout(selectedRoomId.value),
      loadEquipmentItems(),
    ]);
  } catch (e) {
    alert(errMsg(e));
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
              Select a room to view and edit its assigned equipment.
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
                      <td>
                        <input
                          v-if="editingEquipmentId === entry.idroomequipment"
                          type="number"
                          min="1"
                          class="form-control form-control-sm"
                          v-model.number="editingEquipmentQty"
                        />
                        <span v-else>{{ entry.quantity }}</span>
                      </td>
                      <td>
                        <div
                          v-if="editingEquipmentId === entry.idroomequipment"
                          class="d-flex gap-1"
                        >
                          <button
                            class="btn btn-sm btn-success"
                            @click="saveEditEquipment(entry)"
                          >
                            Save
                          </button>
                          <button
                            class="btn btn-sm btn-outline-secondary"
                            @click="editingEquipmentId = null"
                          >
                            Cancel
                          </button>
                        </div>
                        <div v-else class="d-flex gap-1">
                          <button
                            class="btn btn-sm btn-outline-secondary"
                            @click="startEditEquipment(entry)"
                          >
                            Edit
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
</template>

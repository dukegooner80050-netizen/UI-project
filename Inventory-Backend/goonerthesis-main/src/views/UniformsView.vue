<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useRoute } from "vue-router";

import { listUniformTypes, listUniformVariants, createUniformType, updateUniformType, deleteUniformType,
  createUniformVariant, updateUniformVariant, deleteUniformVariant, restockUniformVariant, releaseUniformVariant,} from "../services/uniforms";
import { getDepartments } from "../services/departments";
const route = useRoute();

/* PAGE / FILTER */

const course = computed(() => route.query.course || "ALL");
const isAllView = computed(() => {
  return course.value === "ALL";
});

/* DATA */

const uniformTypes = ref([]);
const uniformVariants = ref([]);
const departments = ref([]);

const loading = ref(false);

/* FILTERED VARIANTS*/

const filteredVariants = computed(() => {
  if (isAllView.value) {
    return uniformVariants.value;
  }
  return uniformVariants.value.filter((variant) => {
    const departmentName = variant.department?.dept_name || "";

    return departmentName === course.value;
  });
});

/* LOAD DATA */

onMounted(async () => {
  await loadAll();
});

watch(
  () => route.query.course,
  async () => {
    await loadAll();
  },
);

async function loadAll() {
  loading.value = true;
  try {
    const [types, variants, departmentList] = await Promise.all([
      listUniformTypes(),
      listUniformVariants(),
      getDepartments(),
    ]);
    uniformTypes.value = types || [];
    uniformVariants.value = variants || [];
    departments.value = departmentList || [];
  } catch (e) {
    console.error("Failed to load uniform data:", e);
    alert(
      e?.response?.data?.message ||
        e?.message ||
        "Failed to load uniform data.",
    );
  } finally {
    loading.value = false;
  }
}

/* HELPER */

function getTypeName(variant) {
  return variant.type?.uniform_name || "Unknown Uniform";
}
function getDepartmentName(variant) {
  return variant.department?.dept_name || "N/A";
}
function getStatus(quantity) {
  const qty = Number(quantity) || 0;
  if (qty <= 0) {
    return "Out of Stock";
  }
  if (qty <= 5) {
    return "Low Stock";
  }
  return "Available";
}

function statusClass(quantity) {
  const status = getStatus(quantity);
  if (status === "Available") {
    return "bg-success";
  }
  if (status === "Low Stock") {
    return "bg-warning text-dark";
  }
  return "bg-danger";
}

/* ADD UNIFORM TYPE */

const typeModalOpen = ref(false);
const typeName = ref("");
const typeDescription = ref("");

/* CONFIMATION */

const typeConfirmModalOpen = ref(false);

function openAddType() {
  typeName.value = "";
  typeDescription.value = "";

  typeModalOpen.value = true;
}

function closeAddType() {
  typeModalOpen.value = false;
}

async function confirmAddType() {
  const name = typeName.value.trim();
  const description = typeDescription.value.trim();

  if (!name) {
    alert("Please enter a uniform name.");
    return;
  }

  typeConfirmModalOpen.value = true;
}

function closeTypeConfirm() {
  typeConfirmModalOpen.value = false;
}

async function proceedAddType() {
  const name = typeName.value.trim();
  const description = typeDescription.value.trim();

  if (!name) {
    alert("Please enter a uniform name.");
    closeTypeConfirm();
    return;
  }

  try {
    await createUniformType({
      uniform_name: name,
      description: description,
    });

    await loadAll();

    closeTypeConfirm();
    closeAddType();
  } catch (e) {
    console.error("Failed to create uniform type:", e);

    alert(
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to create uniform type.",
    );
  }
}

/* EDIT UNIFORM TYPE */

const editTypeModalOpen = ref(false);
const editingType = ref(null);

const editTypeName = ref("");
const editTypeDescription = ref("");

function openEditType(type) {
  editingType.value = type;
  editTypeName.value = type.uniform_name || "";
  editTypeDescription.value = type.description || "";
  editTypeModalOpen.value = true;
}

function closeEditType() {
  editTypeModalOpen.value = false;
  editingType.value = null;
}

async function saveTypeEdit() {
  if (!editingType.value) {
    return;
  }
  const name = editTypeName.value.trim();
  const description = editTypeDescription.value.trim();
  if (!name) {
    alert("Uniform name is required.");
    return;
  }

  try {
    await updateUniformType(editingType.value.idUniftype, {
      uniform_name: name,
      description: description,
    });
    await loadAll();
    closeEditType();
  } catch (e) {
    console.error("Failed to update uniform type:", e);
    alert(
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to update uniform type.",
    );
  }
}

/* DELETE UNIFORM TYPE */

async function removeUniformType(type) {
  const typeName = type.uniform_name || "this uniform";
  const hasVariants = uniformVariants.value.some(
    (variant) => String(variant.idUniftype) === String(type.idUniftype),
  );
  if (hasVariants) {
    alert(
      `Cannot delete "${typeName}" because it still has uniform variants. Delete its variants first.`,
    );
    return;
  }

  if (!confirm(`Delete uniform type "${typeName}"?`)) {
    return;
  }
  try {
    await deleteUniformType(type.idUniftype);
    await loadAll();
  } catch (e) {
    console.error("Failed to delete uniform type:", e);
    alert(
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to delete uniform type.",
    );
  }
}

/* ADD VARIANT */

const variantModalOpen = ref(false);
const variantConfirmModalOpen = ref(false);
const variantTypeId = ref("");
const variantDepartmentId = ref("");
const variantSize = ref("");
const variantPrice = ref(0);
const variantQuantity = ref(1);

function openAddVariant() {
  variantTypeId.value = "";
  variantDepartmentId.value = "";
  variantSize.value = "";
  variantPrice.value = 0;
  variantQuantity.value = 1;
  variantModalOpen.value = true;
}

function closeAddVariant() {
  variantModalOpen.value = false;
}

function getSelectedVariantTypeName() {
  const type = uniformTypes.value.find(
    (type) => String(type.idUniftype) === String(variantTypeId.value),
  );
  return type?.uniform_name || "N/A";
}

function getSelectedVariantDepartmentName() {
  const department = departments.value.find(
    (department) =>
      String(department.iddept) === String(variantDepartmentId.value),
  );
  return department?.dept_name || "N/A";
}

function closeVariantConfirmModal() {
  variantConfirmModalOpen.value = false;
}
function confirmAddVariant() {
  const typeId = variantTypeId.value;
  const departmentId = variantDepartmentId.value;
  const size = variantSize.value.trim();
  const price = Number(variantPrice.value) || 0;
  const quantity = Number(variantQuantity.value) || 0;

  if (!typeId) {
    alert("Please select a uniform type.");
    return;
  }
  if (!departmentId) {
    alert("Please select a department.");
    return;
  }
  if (!size) {
    alert("Please enter a uniform size.");
    return;
  }
  if (price < 0) {
    alert("Price cannot be negative.");
    return;
  }
  if (quantity < 0) {
    alert("Quantity cannot be negative.");
    return;
  }
  variantConfirmModalOpen.value = true;
}
async function createConfirmedVariant() {
  try {
    await createUniformVariant({
      idUniftype: Number(variantTypeId.value),
      iddept: Number(variantDepartmentId.value),
      size: variantSize.value.trim(),
      price: Number(variantPrice.value) || 0,
      quantity: Number(variantQuantity.value) || 0,
    });

    await loadAll();

    variantConfirmModalOpen.value = false;
    closeAddVariant();
  } catch (e) {
    console.error("Failed to create uniform variant:", e);
    variantConfirmModalOpen.value = false;
    alert(
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to create uniform variant.",
    );
  }
}

/* EDIT VARIANT */

const editVariantModalOpen = ref(false);
const editingVariant = ref(null);
const editVariantPrice = ref(0);

/* const editVariantTypeId = ref("");
const editVariantDepartmentId = ref("");
const editVariantSize = ref("");
const editVariantQuantity = ref(0); */

function openEditVariant(variant) {
  editingVariant.value = variant;
  editVariantPrice.value = Number(variant.price) || 0;
  editVariantModalOpen.value = true;
}

function closeEditVariant() {
  editVariantModalOpen.value = false;
  editingVariant.value = null;
  editVariantPrice.value = 0;
}

function saveVariantEdit() {
  if (!editingVariant.value) {
    return;
  }
  const price = Number(editVariantPrice.value) || 0;
  if (price < 0) {
    alert("Price cannot be negative.");
    return;
  }

  // Open the existing confirmation modal
  openActionConfirm("edit", editingVariant.value);
}

/* DELETE VARIANT */

function removeVariant(variant) {
  openActionConfirm("delete", variant);
}

/*RESTOCK / RELEASE*/
const stockModalOpen = ref(false);
const stockMode = ref("");
const stockVariant = ref(null);
const stockQuantity = ref(1);
/*ACTION CONFIRMATION MODAL*/
const actionConfirmModalOpen = ref(false);
const actionConfirmType = ref("");
const actionConfirmVariant = ref(null);

function openActionConfirm(type, variant) {
  actionConfirmType.value = type;
  actionConfirmVariant.value = variant;
  actionConfirmModalOpen.value = true;
}
function closeActionConfirm() {
  actionConfirmModalOpen.value = false;
  actionConfirmType.value = "";
  actionConfirmVariant.value = null;

}
function getActionConfirmTitle() {
  if (actionConfirmType.value === "release") {
    return "Confirm Release";
  }
  if (actionConfirmType.value === "restock") {
    return "Confirm Restock";
  }
  if (actionConfirmType.value === "edit") {
    return "Confirm Edit";
  }
  if (actionConfirmType.value === "delete") {
    return "Confirm Delete";
  }
  return "Confirm Action";
}
function getActionConfirmMessage() {
  if (!actionConfirmVariant.value) {
    return "";
  }
  const variant = actionConfirmVariant.value;
  const name = getTypeName(variant);
  const department = getDepartmentName(variant);
  const size = variant.size || "N/A";
  if (actionConfirmType.value === "release") {
    return `Are you sure you want to release ${stockQuantity.value} unit(s) of ${name} (${department} - ${size})?`;
  }
  if (actionConfirmType.value === "restock") {
    return `Are you sure you want to restock ${stockQuantity.value} unit(s) of ${name} (${department} - ${size})?`;
  }
  if (actionConfirmType.value === "edit") {
    return `Are you sure you want to save the changes made to ${name} (${department} - ${size})?`;
  }
  if (actionConfirmType.value === "delete") {
    return `Are you sure you want to delete ${name} (${department} - ${size})?`;
  }
  return "";
}
async function confirmAction() {
  if (!actionConfirmVariant.value) {
    closeActionConfirm();
    return;
  }
  const variant = actionConfirmVariant.value;
  try {
    /* RELEASE */

    if (actionConfirmType.value === "release") {
      const quantity = Number(stockQuantity.value) || 0;
      if (quantity <= 0) {
        alert("Quantity must be at least 1.");
        return;
      }
      await releaseUniformVariant(variant.idUnifvariant, quantity);
    } else if (actionConfirmType.value === "restock") {

    /* RESTOCK */
      const quantity = Number(stockQuantity.value) || 0;
      if (quantity <= 0) {
        alert("Quantity must be at least 1.");
        return;
      }
      await restockUniformVariant(variant.idUnifvariant, quantity);
    } else if (actionConfirmType.value === "edit") {

    /* EDIT */
      const newPrice = Number(editVariantPrice.value);
      if (isNaN(newPrice)) {
        alert("Please enter a valid price.");
        return;
      }
      if (newPrice < 0) {
        alert("Price cannot be negative.");
        return;
      }
      await updateUniformVariant(variant.idUnifvariant, {
        idUniftype: variant.idUniftype,
        iddept: variant.iddept,
        size: variant.size,
        price: newPrice,
        quantity: variant.quantity,
      });
    } else if (actionConfirmType.value === "delete") {

    /* DELETE */
      await deleteUniformVariant(variant.idUnifvariant);
    }

    /* REFRESH */

    await loadAll();

    const completedAction = actionConfirmType.value;
    closeActionConfirm();
    if (completedAction === "release" || completedAction === "restock") {
      closeStockModal();
    }
    if (completedAction === "edit") {
      closeEditVariant();
    }
  } catch (e) {
    console.error("Uniform action failed:", e);
    alert(
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to perform action.",
    );
  }
}

function openStockModal(mode, variant) {
  stockMode.value = mode;
  stockVariant.value = variant;
  stockQuantity.value = 1;
  stockModalOpen.value = true;
}

function closeStockModal() {
  stockModalOpen.value = false;
  stockMode.value = "";
  stockVariant.value = null;
  stockQuantity.value = 1;
}

function confirmStockAction() {
  if (!stockVariant.value) {
    alert("No uniform selected.");
    return;
  }

  const quantity = Number(stockQuantity.value) || 0;
  if (quantity <= 0) {
    alert("Quantity must be at least 1.");
    return;
  }

  /* Prevents releasing more than available stock */
  if (
    stockMode.value === "release" &&
    quantity > Number(stockVariant.value.quantity)
  ) {
    alert(
      `Cannot release ${quantity} unit(s). Only ${stockVariant.value.quantity} unit(s) are available.`,
    );
    return;
  }

  openActionConfirm(stockMode.value, stockVariant.value);
}

/* TYPE FILTER */

const visibleTypes = computed(() => {
  if (isAllView.value) {
    return uniformTypes.value;
  }

  return uniformTypes.value.filter((type) => {
    return uniformVariants.value.some(
      (variant) =>
        String(variant.idUniftype) === String(type.idUniftype) &&
        getDepartmentName(variant) === course.value,
    );
  });
});
</script>

<template>
  <div>
    <!-- PAGE TITLE -->

    <h3 class="mb-4">
      {{ isAllView ? "All Uniforms" : course + " Uniforms" }}
    </h3>

    <!-- ACTION BAR -->

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="text-muted">
        {{ isAllView ? "Manage all uniform variants" : "Manage uniforms for " + course }}
      </div>

      <div class="d-flex gap-2" v-if="!isAllView">
        <!-- ADD TYPE -->

        <button class="btn btn-outline-primary" @click="openAddType">
          + Add Uniform Type
        </button>

        <!-- ADD VARIANT -->

        <button class="btn btn-primary" @click="openAddVariant">
          + Add Variant
        </button>
      </div>
    </div>

    <!-- LOADING -->

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="mt-2 text-muted">Loading uniforms...</div>
    </div>

    <!-- INVENTORY TABLE -->

    <div v-else class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Uniform</th>
              <th>Department</th>
              <th>Size</th>
              <th style="width: 120px">Price</th>
              <th>Status</th>
              <th style="width: 100px">Qty</th>
              <th style="width: 260px" class="text-center">Action</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="variant in filteredVariants" :key="variant.idUnifvariant">
              <!-- UNIFORM -->
              <td>
                <strong>
                  {{ getTypeName(variant) }}
                </strong>
              </td>

              <!-- DEPARTMENT -->
              <td>
                {{ getDepartmentName(variant) }}
              </td>

              <!-- SIZE -->
              <td>
                {{ variant.size }}
              </td>

              <!-- PRICE -->
              <td>₱{{ Number(variant.price || 0).toFixed(2) }}</td>

              <!-- STATUS -->
              <td>
                <span class="badge" :class="statusClass(variant.quantity)">
                  {{ getStatus(variant.quantity) }}
                </span>
              </td>

              <!-- QUANTITY -->
              <td>
                {{ variant.quantity }}
              </td>

              <!-- ACTIONS -->

              <td class="text-center">
                <div class="d-flex justify-content-center gap-2 flex-wrap">
                  <!-- RELEASE -->
                  <button v-if="isAllView" class="btn btn-primary btn-sm"
                    @click="openStockModal('release', variant)">
                    Release</button>

                  <!-- RESTOCK -->
                  <button v-else class="btn btn-success btn-sm"
                    @click="openStockModal('restock', variant)">
                    Restock</button>

                  <!-- EDIT -->
                  <button class="btn btn-warning btn-sm" @click="openEditVariant(variant)">
                    Edit
                  </button>

                  <!-- DELETE -->
                  <button class="btn btn-danger btn-sm" @click="removeVariant(variant)">
                    Delete
                  </button>
                </div>
              </td>
            </tr>

            <!-- EMPTY -->

            <tr v-if="!filteredVariants.length">
              <td colspan="7" class="text-center text-muted py-4">
                No uniforms found.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ADD UNIFORM TYPE MODAL -->

    <div v-if="typeModalOpen" class="modal-backdrop-custom">
      <form class="modal-custom" @submit.prevent="confirmAddType">
        <div class="modal-header">
          <h5 class="mb-0">Add Uniform Type</h5>
          <button type="button" class="btn-close" @click="closeAddType"></button>
        </div>

        <div class="modal-body">
          <label class="form-label"> Uniform Name </label>
          <input class="form-control mb-3" v-model="typeName" placeholder="e.g. PE Uniform"/>

          <label class="form-label"> Description </label>

          <textarea class="form-control" rows="3" v-model="typeDescription" placeholder="Enter uniform description"></textarea>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeAddType">
            Cancel
          </button>

          <button type="submit" class="btn btn-primary">Add Type</button>
        </div>
      </form>
    </div>

    <!-- CONFIRM ADD UNIFORM TYPE -->
    <div v-if="typeConfirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm New Uniform Type</h5>
          <button type="button" class="btn-close" @click="closeTypeConfirm"></button>
        </div>

        <div class="modal-body">
          <p class="mb-3">
            Please review the information before creating this uniform type.
          </p>

          <!-- UNIFORM NAME -->
          <div class="mb-3">
            <label class="form-label"> Uniform Name </label>

            <div class="form-control bg-light">
              {{ typeName }}
            </div>
          </div>

          <!-- DESCRIPTION -->
          <div class="mb-3">
            <label class="form-label"> Description </label>
            <div class="form-control bg-light" style="min-height: 80px; white-space: pre-wrap">
              {{ typeDescription || "No description provided." }}
            </div>
          </div>

          <div class="alert alert-warning mb-0">
            Are you sure you want to create this uniform type?
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeTypeConfirm">
            Cancel
          </button>

          <button type="button" class="btn btn-primary" @click="proceedAddType">
            Confirm
          </button>
        </div>
      </div>
    </div>

    <!-- EDIT UNIFORM TYPE MODAL -->

    <div v-if="editTypeModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Edit Uniform Type</h5>

          <button type="button" class="btn-close" @click="closeEditType"></button>
        </div>

        <div class="modal-body">
          <label class="form-label"> Uniform Name </label>
          <input class="form-control mb-3" v-model="editTypeName" />
          <label class="form-label"> Description </label>
          <textarea class="form-control" rows="3" v-model="editTypeDescription"></textarea>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="closeEditType">
            Cancel
          </button>

          <button class="btn btn-primary" @click="saveTypeEdit">
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- ADD VARIANT MODAL -->

    <div v-if="variantModalOpen" class="modal-backdrop-custom">
      <form class="modal-custom" @submit.prevent="confirmAddVariant">
        <div class="modal-header">
          <h5 class="mb-0">Add Uniform Variant</h5>
          <button type="button" class="btn-close" @click="closeAddVariant"></button>
        </div>

        <div class="modal-body">
          <!-- TYPE -->

          <label class="form-label"> Uniform Type </label>
          <select class="form-select mb-3" v-model="variantTypeId">
            <option value="">Select uniform type</option>

            <option v-for="type in uniformTypes" :key="type.idUniftype" :value="String(type.idUniftype)">
              {{ type.uniform_name }}
            </option>
          </select>

          <!-- DEPARTMENT -->

          <label class="form-label"> Department </label>
          <select class="form-select mb-3" v-model="variantDepartmentId">
            <option value="">Select department</option>
            <option v-for="department in departments" :key="department.iddept" :value="String(department.iddept)">
              {{ department.dept_name }}
            </option>
          </select>

          <!-- SIZE -->

          <label class="form-label"> Size </label>
          <input class="form-control mb-3" v-model="variantSize" placeholder="e.g. Small, Medium, Large"/>

          <!-- PRICE -->

          <label class="form-label"> Price </label>
          <input type="number" min="0" step="0.01" class="form-control mb-3" v-model.number="variantPrice"/>

          <!-- QUANTITY -->

          <label class="form-label"> Quantity </label>
          <input type="number" min="0" class="form-control" v-model.number="variantQuantity"/>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeAddVariant">
            Cancel
          </button>

          <button type="submit" class="btn btn-primary">Add Variant</button>
        </div>
      </form>
    </div>

    <!-- ADD VARIANT CONFIRMATION MODAL -->

    <div v-if="variantConfirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm New Uniform Variant</h5>
          <button type="button" class="btn-close" @click="closeVariantConfirmModal"></button>
        </div>

        <div class="modal-body">
          <p class="mb-3">
            Please review the information below before creating this uniform
            variant.
          </p>

          <div class="mb-2">
            <strong>Uniform Type:</strong>{{ getSelectedVariantTypeName() }}</div>
          <div class="mb-2">
            <strong>Department:</strong>{{ getSelectedVariantDepartmentName() }}</div>
          <div class="mb-2">
            <strong>Size:</strong>{{ variantSize }}</div>
          <div class="mb-2">
            <strong>Price:</strong>₱{{ Number(variantPrice || 0).toFixed(2) }}</div>
          <div class="mb-3"><strong>Quantity:</strong>{{ variantQuantity }}</div>
          <div class="alert alert-warning mb-0">
            <strong>Please confirm.</strong>
            Once this uniform variant is created, make sure that the uniform
            type, department, size, price, and quantity are correct.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeVariantConfirmModal">
            Cancel
          </button>

          <button type="button" class="btn btn-primary" @click="createConfirmedVariant">
            Confirm & Add Variant
          </button>
        </div>
      </div>
    </div>

    <!-- EDIT VARIANT MODAL -->

    <div v-if="editVariantModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Edit Uniform Variant</h5>

          <button type="button" class="btn-close" @click="closeEditVariant"></button>
        </div>

        <div class="modal-body">
          <!-- TYPE -->
          <label class="form-label"> Uniform Type </label>
          <input class="form-control mb-3" :value="editingVariant ? getTypeName(editingVariant) : ''" disabled/>

          <!-- DEPARTMENT -->
          <label class="form-label"> Department </label>
          <input class="form-control mb-3" :value="editingVariant ? getDepartmentName(editingVariant) : ''"disabled/>

          <!-- SIZE -->
          <label class="form-label"> Size </label>
          <input class="form-control mb-3" :value="editingVariant ? editingVariant.size : ''" disabled/>

          <!-- PRICE -->
          <label class="form-label"> Price </label>
          <input type="number" min="0" step="0.01" class="form-control mb-3" v-model.number="editVariantPrice"/>

          <!-- QUANTITY -->
          <label class="form-label"> Quantity </label>
          <input type="number" class="form-control" :value="editingVariant ? editingVariant.quantity : 0" disabled/>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeEditVariant">
            Cancel
          </button>

          <button type="button" class="btn btn-primary" @click="saveVariantEdit">
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- RESTOCK / RELEASE MODAL -->

    <div v-if="stockModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ stockMode === "restock" ? "Restock Uniform" : "Release Uniform" }}
          </h5>

          <button type="button" class="btn-close" @click="closeStockModal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-3">
            <strong>{{ stockVariant ? getTypeName(stockVariant) : "" }}</strong>
            <div class="text-muted">
              {{ stockVariant ? getDepartmentName(stockVariant) : "" }}
              — Size
              {{ stockVariant ? stockVariant.size : "" }}
            </div>
          </div>

          <div class="alert" :class="stockMode === 'restock' ? 'alert-success' : 'alert-warning'">
            {{
              stockMode === "restock"
                ? "Enter the quantity to add to the uniform inventory."
                : "Enter the quantity to release from the uniform inventory."
            }}
          </div>

          <label class="form-label"> Quantity </label>
          <input type="number" min="1" :max=" stockMode === 'release' && stockVariant
                ? stockVariant.quantity : undefined" class="form-control" v-model.number="stockQuantity"/>
          <div v-if="stockMode === 'release' && stockVariant" class="text-muted small mt-2">
            Current stock:
            {{ Number(stockVariant.quantity) || 0 }}
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="closeStockModal">
            Cancel
          </button>

          <button class="btn" :class="stockMode === 'restock' ? 'btn-success' : 'btn-primary'"  @click="confirmStockAction">
            {{ stockMode === "restock" ? "Restock" : "Release" }}
          </button>
        </div>
      </div>
    </div>
    <!-- ACTION CONFIRMATION MODAL -->

    <div v-if="actionConfirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">
            {{ getActionConfirmTitle() }}
          </h5>
          <button
            type="button"
            class="btn-close"
            @click="closeActionConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <!-- VARIANT INFORMATION -->
          <div v-if="actionConfirmVariant" class="mb-3">
            <div class="mb-2">
              <strong> Uniform: </strong>
              {{ getTypeName(actionConfirmVariant) }}
            </div>

            <div class="mb-2">
              <strong> Department: </strong>
              {{ getDepartmentName(actionConfirmVariant) }}
            </div>
            <div class="mb-2">
              <strong> Size: </strong>
              {{ actionConfirmVariant.size }}
            </div>
          </div>

          <!-- RELEASE / RESTOCK -->
          <div
            v-if="
              actionConfirmType === 'release' || actionConfirmType === 'restock'
            "
          >
            <div class="mb-2">
              <strong> Quantity: </strong>
              {{ stockQuantity }}
            </div>
          </div>

          <!-- EDIT -->

          <div v-if="actionConfirmType === 'edit'" class="mb-3">
            <div class="mb-2">
              <strong>Current Price:</strong>
              ₱{{ Number(actionConfirmVariant?.price || 0).toFixed(2) }}
            </div>
            <div class="mb-3">
              <strong>New Price:</strong>
              ₱{{ Number(editVariantPrice || 0).toFixed(2) }}
            </div>
            <div class="alert alert-warning mb-0">
              Only the price will be changed. Uniform type, department, size,
              and quantity will remain unchanged.
            </div>
          </div>

          <!-- DELETE -->
          <div v-if="actionConfirmType === 'delete'" class="alert alert-danger">
            <strong> Warning: </strong>
            This action will permanently delete this uniform variant.
          </div>

          <!-- GENERAL CONFIRMATION -->

          <p class="mb-0">
            {{ getActionConfirmMessage() }}
          </p>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" @click="closeActionConfirm">
            Cancel
          </button>

          <button type="button" class="btn" :class="actionConfirmType === 'delete' ? 'btn-danger' : 'btn-primary'" @click="confirmAction">
            {{ actionConfirmType === "delete" ? "Confirm Delete" : "Confirm" }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.table-scroll {
  max-height: 520px;
  overflow-y: auto;
}
.table-scroll thead th {
  position: sticky;
  top: 0;
  background: #f8f9fa;
  z-index: 2;
}

/* MODALS */
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
  background: #fff;
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.25);
}
.modal-header,
.modal-footer {
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.modal-header {
  border-bottom: 1px solid #dee2e6;
}
.modal-footer {
  border-top: 1px solid #dee2e6;
  justify-content: flex-end;
  gap: 8px;
}
.modal-body {
  padding: 16px;
  overflow-y: auto;
}
</style>

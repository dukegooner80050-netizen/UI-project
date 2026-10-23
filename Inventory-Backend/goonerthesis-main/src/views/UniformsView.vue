<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useRoute } from "vue-router";

import { listUniformTypes, listUniformVariants, createUniformType, updateUniformType, deleteUniformType,
  createUniformVariant, updateUniformVariant, deleteUniformVariant, restockUniformVariant, releaseUniformVariant,} from "../services/uniforms";
import { getDepartments } from "../services/departments";
import AlertMessage from "../components/AlertMessage.vue";

const showAlert = ref(false);
const alertType = ref("success");
const alertMessage = ref("");

function notify(type, message) {
  alertType.value = type;
  alertMessage.value = message;
  showAlert.value = true;
}
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
    notify(
      "danger",
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
    notify("warning", "Please enter a uniform name.");
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
    notify("warning", "Please enter a uniform name.");
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

    notify(
      "danger",
      e?.response?.data?.message ||
        JSON.stringify(e?.response?.data?.errors) ||
        e?.message ||
        "Failed to create uniform type.",
    );
  }
}

/* EDIT UNIFORM TYPE */

const editTypeConfirm = ref(false);
const editingType = ref(null);

const editTypeName = ref("");
const editTypeDescription = ref("");

// Step 1: validate, then show the confirmation screen.
function requestTypeEdit() {
  if (!editingType.value) {
    return;
  }
  if (!editTypeName.value.trim()) {
    notify("warning", "Uniform name is required.");
    return;
  }
  editTypeConfirm.value = true;
}

// Step 2: the user confirmed, save it.
async function saveTypeEdit() {
  if (!editingType.value) {
    return;
  }
  const name = editTypeName.value.trim();
  const description = editTypeDescription.value.trim();

  try {
    await updateUniformType(editingType.value.idUniftype, {
      uniform_name: name,
      description: description,
    });
    await loadAll();
    closeEditVariant();
  } catch (e) {
    console.error("Failed to update uniform type:", e);
    editTypeConfirm.value = false;
    notify(
      "danger",
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
    notify(
      "warning",
      `Cannot delete "${typeName}" because it still has uniform variants. Delete its variants first.`,
    );
    return;
  }

  pendingDeleteType.value = type;
  deleteTypeConfirmModalOpen.value = true;
}

function closeDeleteTypeConfirm() {
  deleteTypeConfirmModalOpen.value = false;
  pendingDeleteType.value = null;
}

async function confirmDeleteUniformType() {
  const type = pendingDeleteType.value;
  closeDeleteTypeConfirm();

  if (!type) {
    return;
  }

  try {
    await deleteUniformType(type.idUniftype);
    await loadAll();
  } catch (e) {
    console.error("Failed to delete uniform type:", e);
    notify(
      "danger",
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

// The type picked in the Add Variant dialog, to show its description.
const variantType = computed(() =>
  uniformTypes.value.find((t) => String(t.idUniftype) === String(variantTypeId.value)),
);
const variantDepartmentId = ref("");
const variantSize = ref("");
const variantGender = ref("Unisex");
const variantPrice = ref(0);
const variantQuantity = ref(1);

// On a course page (e.g. BSMT) a new variant can only go to that course's
// department; the "All Uniforms" page still offers every department.
const addableDepartments = computed(() =>
  isAllView.value
    ? departments.value
    : departments.value.filter((d) => d.dept_name === course.value),
);

function openAddVariant() {
  variantTypeId.value = "";
  variantDepartmentId.value =
    !isAllView.value && addableDepartments.value.length === 1
      ? String(addableDepartments.value[0].iddept)
      : "";
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
  const gender = variantGender.value;
  const price = Number(variantPrice.value) || 0;
  const quantity = Number(variantQuantity.value) || 0;

  if (!typeId) {
    notify("warning", "Please select a uniform type.");
    return;
  }
  if (!departmentId) {
    notify("warning", "Please select a department.");
    return;
  }
  if (!size) {
    notify("warning", "Please enter a uniform size.");
    return;
  }
  if (price < 0) {
    notify("warning", "Price cannot be negative.");
    return;
  }
  if (quantity < 0) {
    notify("warning", "Quantity cannot be negative.");
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
      gender: variantGender.value,
      price: Number(variantPrice.value) || 0,
      quantity: Number(variantQuantity.value) || 0,
    });

    await loadAll();

    variantConfirmModalOpen.value = false;
    closeAddVariant();
  } catch (e) {
    console.error("Failed to create uniform variant:", e);
    variantConfirmModalOpen.value = false;
    notify(
      "danger",
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
const editVariantGender = ref("Unisex");

/* const editVariantTypeId = ref("");
const editVariantDepartmentId = ref("");
const editVariantSize = ref("");
const editVariantQuantity = ref(0); */

// One Edit dialog for both: the "Variant" tab edits this size/department's
// price, the "Type" tab edits the uniform type it belongs to (name and
// description, shared by every variant of that type).
const editTab = ref("variant"); // "variant" | "type"

function openEditVariant(variant) {
  editingVariant.value = variant;
  editVariantPrice.value = Number(variant.price) || 0;
  editVariantGender.value = variant.gender || "Unisex";

  editTab.value = "variant";
  editingType.value = variant.type || null;
  editTypeName.value = variant.type?.uniform_name || "";
  editTypeDescription.value = variant.type?.description || "";
  editTypeConfirm.value = false;

  editVariantModalOpen.value = true;
}

function closeEditVariant() {
  editVariantModalOpen.value = false;
  editingVariant.value = null;
  editVariantPrice.value = 0;
  editVariantGender.value = "Unisex";

  editTab.value = "variant";
  editingType.value = null;
  editTypeConfirm.value = false;
}

function switchEditTab(tab) {
  editTab.value = tab;
  editTypeConfirm.value = false;
}

// A dot on a tab means it has changes that are not saved yet.
const variantDirty = computed(
  () =>
    !!editingVariant.value &&
    Number(editVariantPrice.value) !== Number(editingVariant.value.price || 0),
);

const typeDirty = computed(
  () =>
    !!editingType.value &&
    (editTypeName.value.trim() !== (editingType.value.uniform_name || "") ||
      editTypeDescription.value.trim() !== (editingType.value.description || "")),
);

// How many variants share the type being edited (the change applies to all).
const typeVariantCount = computed(() =>
  editingType.value
    ? uniformVariants.value.filter(
        (v) => String(v.idUniftype) === String(editingType.value.idUniftype),
      ).length
    : 0,
);

function saveVariantEdit() {
  if (!editingVariant.value) {
    return;
  }
  const price = Number(editVariantPrice.value) || 0;
  if (price < 0) {
    notify("warning", "Price cannot be negative.");
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

const deleteTypeConfirmModalOpen = ref(false);
const pendingDeleteType = ref(null);

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
        notify("warning", "Quantity must be at least 1.");
        return;
      }
      await releaseUniformVariant(variant.idUnifvariant, quantity);
    } else if (actionConfirmType.value === "restock") {

    /* RESTOCK */
      const quantity = Number(stockQuantity.value) || 0;
      if (quantity <= 0) {
        notify("warning", "Quantity must be at least 1.");
        return;
      }
      await restockUniformVariant(variant.idUnifvariant, quantity);
    } else if (actionConfirmType.value === "edit") {

    /* EDIT */
      const newPrice = Number(editVariantPrice.value);
      if (isNaN(newPrice)) {
        notify("warning", "Please enter a valid price.");
        return;
      }
      if (newPrice < 0) {
        notify("warning", "Price cannot be negative.");
        return;
      }
      await updateUniformVariant(variant.idUnifvariant, {
        idUniftype: variant.idUniftype,
        iddept: variant.iddept,
        size: variant.size,
        gender: editVariantGender.value,
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
    notify(
      "danger",
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
    notify("warning", "No uniform selected.");
    return;
  }

  const quantity = Number(stockQuantity.value) || 0;
  if (quantity <= 0) {
    notify("warning", "Quantity must be at least 1.");
    return;
  }

  /* Prevents releasing more than available stock */
  if (
    stockMode.value === "release" &&
    quantity > Math.max(0, Number(stockVariant.value.quantity) - Number(stockVariant.value.reserved_quantity || 0))
  ) {
    notify(
      "warning",
      `Cannot release ${quantity} unit(s). Only ${Math.max(0, Number(stockVariant.value.quantity) - Number(stockVariant.value.reserved_quantity || 0))} unreserved unit(s) are available.`,
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
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />

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

        <button class="btn btn-primary text-white" @click="openAddType">
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
      <div class="text-muted">Loading uniforms...</div>
    </div>

    <!-- INVENTORY TABLE -->

    <div v-else class="card shadow-sm">
      <div class="card-body table-scroll">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Uniform</th>
              <th>Department</th>
              <th>Gender</th>
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
                <div
                  v-if="variant.type?.description"
                  class="small text-muted type-description"
                >
                  {{ variant.type.description }}
                </div>
              </td>

              <!-- DEPARTMENT -->
              <td>
                {{ getDepartmentName(variant) }}
              </td>

              <!-- GENDER -->
              <td>{{ variant.gender || "Unisex" }}</td>

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
                  <!-- RESTOCK (uniforms go out through requests, so there is no manual release) -->
                  <button class="btn btn-success btn-sm"
                    @click="openStockModal('restock', variant)">
                    Restock</button>

                  <!-- EDIT -->
                  <button class="btn btn-warning btn-sm" @click="openEditVariant(variant)">
                    Edit
                  </button>

                  <!-- DELETE -->
                  <button class="btn btn-danger btn-sm" @click="removeVariant(variant)">
                    Remove
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
          <label for="un-type-name" class="form-label"> Uniform Name </label>
          <input class="form-control mb-3" v-model="typeName" placeholder="e.g. PE Uniform" id="un-type-name" name="type-name" />

          <label for="un-type-description" class="form-label"> Description </label>

          <textarea class="form-control" rows="3" v-model="typeDescription" placeholder="Enter uniform description" id="un-type-description" name="type-description"></textarea>
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

    <!-- ADD VARIANT MODAL -->

    <div v-if="variantModalOpen" class="modal-backdrop-custom">
      <form class="modal-custom" @submit.prevent="confirmAddVariant">
        <div class="modal-header">
          <h5 class="mb-0">Add Uniform Variant</h5>
          <button type="button" class="btn-close" @click="closeAddVariant"></button>
        </div>

        <div class="modal-body">
          <!-- TYPE -->

          <label for="un-variant-type-id" class="form-label"> Uniform Type </label>
          <select class="form-select mb-3" v-model="variantTypeId" id="un-variant-type-id" name="variant-type-id">
            <option value="">Select uniform type</option>

            <option v-for="type in uniformTypes" :key="type.idUniftype" :value="String(type.idUniftype)">
              {{ type.uniform_name }}
            </option>
          </select>
          <div v-if="variantType?.description" class="form-text mb-3">
            {{ variantType.description }}
          </div>

          <!-- DEPARTMENT -->

          <label for="un-variant-department-id" class="form-label"> Department </label>
          <select class="form-select mb-3" v-model="variantDepartmentId" id="un-variant-department-id" name="variant-department-id">
            <option value="">Select department</option>
            <option v-for="department in addableDepartments" :key="department.iddept" :value="String(department.iddept)">
              {{ department.dept_name }}
            </option>
          </select>

          <!-- SIZE -->

          <label for="un-variant-size" class="form-label"> Size </label>
          <input class="form-control mb-3" v-model="variantSize" placeholder="e.g. Small, Medium, Large" id="un-variant-size" name="variant-size" />

          <label for="un-variant-gender" class="form-label">Gender</label>
          <select class="form-select mb-3" v-model="variantGender" id="un-variant-gender" name="variant-gender">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Unisex">Unisex</option>
          </select>

          <!-- PRICE -->

          <label for="un-variant-price" class="form-label"> Price </label>
          <input type="number" min="0" step="0.01" class="form-control mb-3" v-model.number="variantPrice" id="un-variant-price" name="variant-price" />

          <!-- QUANTITY -->

          <label for="un-variant-quantity" class="form-label"> Quantity </label>
          <input type="number" min="0" class="form-control" v-model.number="variantQuantity" id="un-variant-quantity" name="variant-quantity" />
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
            <strong>Gender:</strong>{{ variantGender }}</div>
          <div class="mb-2">
            <strong>Price:</strong>₱{{ Number(variantPrice || 0).toFixed(2) }}</div>
          <div class="mb-3"><strong>Quantity:</strong>{{ variantQuantity }}</div>
          <div class="alert alert-warning mb-0">
            <strong>Please confirm.</strong>
            Once this uniform variant is created, make sure that the uniform
            type, department, size, gender, price, and quantity are correct.
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

    <!-- EDIT UNIFORM MODAL: "Variant" and "Type" tabs -->

    <div v-if="editVariantModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Edit Uniform</h5>

          <button type="button" class="btn-close" @click="closeEditVariant"></button>
        </div>

        <!-- TABS -->
        <div class="px-3 pt-3">
          <div class="btn-group w-100" role="tablist" aria-label="What to edit">
            <button
              type="button"
              class="btn"
              :class="editTab === 'variant' ? 'btn-primary' : 'btn-outline-primary'"
              role="tab"
              :aria-selected="editTab === 'variant'"
              @click="switchEditTab('variant')"
            >
              Variant<span v-if="variantDirty"> •</span>
            </button>
            <button
              type="button"
              class="btn"
              :class="editTab === 'type' ? 'btn-primary' : 'btn-outline-primary'"
              role="tab"
              :aria-selected="editTab === 'type'"
              :disabled="!editingType"
              @click="switchEditTab('type')"
            >
              Type<span v-if="typeDirty"> •</span>
            </button>
          </div>
        </div>

        <div class="modal-body">
          <!-- VARIANT TAB -->
          <template v-if="editTab === 'variant'">
          <!-- TYPE -->
          <label for="un-input" class="form-label"> Uniform Type </label>
          <input class="form-control mb-3" :value="editingVariant ? getTypeName(editingVariant) : ''" disabled id="un-input" name="input" />

          <!-- DEPARTMENT -->
          <label for="un-input-2" class="form-label"> Department </label>
          <input class="form-control mb-3" :value="editingVariant ? getDepartmentName(editingVariant) : ''"disabled id="un-input-2" name="input" />

          <!-- SIZE -->
          <label for="un-input-3" class="form-label"> Size </label>
          <input class="form-control mb-3" :value="editingVariant ? editingVariant.size : ''" disabled id="un-input-3" name="input" />

          <!-- PRICE -->
          <label for="un-edit-variant-price" class="form-label"> Price </label>
          <input type="number" min="0" step="0.01" class="form-control mb-3" v-model.number="editVariantPrice" id="un-edit-variant-price" name="edit-variant-price" />

          <!-- QUANTITY -->
          <label for="un-input-4" class="form-label"> Quantity </label>
          <input type="number" class="form-control" :value="editingVariant ? editingVariant.quantity : 0" disabled id="un-input-4" name="input" />
          </template>

          <!-- TYPE TAB -->
          <template v-else-if="!editTypeConfirm">
            <div class="alert alert-info py-2 small">
              This is the uniform type, shared by
              <strong>{{ typeVariantCount }}</strong>
              variant{{ typeVariantCount === 1 ? "" : "s" }}. Changes here apply to all of them.
            </div>

            <label for="un-edit-type-name" class="form-label"> Uniform Name </label>
            <input class="form-control mb-3" v-model="editTypeName" id="un-edit-type-name" name="edit-type-name" />

            <label for="un-edit-type-description" class="form-label"> Description </label>
            <textarea class="form-control" rows="3" maxlength="255" v-model="editTypeDescription" id="un-edit-type-description" name="edit-type-description"></textarea>
          </template>

          <!-- TYPE CONFIRMATION -->
          <div v-else class="alert alert-warning mb-0">
            <h6 class="mb-3">Confirm Action</h6>
            Save changes to
            <strong>{{ editingType?.uniform_name }}</strong>
            for all {{ typeVariantCount }} of its variants?
            <div v-if="editTypeName.trim() !== editingType?.uniform_name" class="mt-2">
              New name: <strong>{{ editTypeName.trim() }}</strong>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <!-- VARIANT TAB -->
          <template v-if="editTab === 'variant'">
            <button type="button" class="btn btn-secondary" @click="closeEditVariant">
              Cancel
            </button>

            <button type="button" class="btn btn-primary" @click="saveVariantEdit">
              Save Changes
            </button>
          </template>

          <!-- TYPE TAB -->
          <template v-else>
            <button
              type="button"
              class="btn btn-secondary"
              @click="editTypeConfirm ? (editTypeConfirm = false) : closeEditVariant()"
            >
              {{ editTypeConfirm ? "Back" : "Cancel" }}
            </button>

            <button
              v-if="!editTypeConfirm"
              type="button"
              class="btn btn-primary"
              @click="requestTypeEdit"
            >
              Save Changes
            </button>
            <button v-else type="button" class="btn btn-success" @click="saveTypeEdit">
              Confirm
            </button>
          </template>
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

          <label for="un-stock-quantity" class="form-label"> Quantity </label>
          <input type="number" min="1" :max=" stockMode === 'release' && stockVariant
                ? stockVariant.quantity : undefined" class="form-control" v-model.number="stockQuantity" id="un-stock-quantity" name="stock-quantity" />
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
    <!-- DELETE UNIFORM TYPE CONFIRMATION MODAL -->

    <div v-if="deleteTypeConfirmModalOpen" class="modal-backdrop-custom">
      <div class="modal-custom">
        <div class="modal-header">
          <h5 class="mb-0">Confirm Delete</h5>
          <button
            type="button"
            class="btn-close"
            @click="closeDeleteTypeConfirm"
          ></button>
        </div>

        <div class="modal-body">
          <p class="mb-0">
            Delete uniform type
            "{{ pendingDeleteType?.uniform_name || 'this uniform' }}"?
          </p>
        </div>

        <div class="modal-footer">
          <button
            type="button"
            class="btn btn-secondary"
            @click="closeDeleteTypeConfirm"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn btn-danger"
            @click="confirmDeleteUniformType"
          >
            Confirm Delete
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
            <div class="mb-2"><strong>Gender:</strong> {{ actionConfirmVariant.gender || "Unisex" }}</div>
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
              <label for="edit-variant-gender" class="form-label"><strong>Gender</strong></label>
              <select id="edit-variant-gender" class="form-select" v-model="editVariantGender">
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Unisex">Unisex</option>
              </select>
            </div>
            <div class="mb-3">
              <strong>New Price:</strong>
              ₱{{ Number(editVariantPrice || 0).toFixed(2) }}
            </div>
            <div class="alert alert-warning mb-0">
              Only the price and gender will be changed. Uniform type, department, size,
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
</style>

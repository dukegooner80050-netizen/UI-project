<script setup>
import { ref, watch, computed } from "vue"
import { useRouter } from "vue-router"
import { createRequest } from "../services/requests"
import { getCurrentUser } from "../services/storage"

const router = useRouter()
const user = getCurrentUser()

/* form state */
const category = ref("")
const itemType = ref("")
const itemName = ref("")
const qty = ref(1)
const purpose = ref("")
const error = ref("")

/* auto-lock item type based on category */
watch(category, (val) => {
  if (val === "School Equipment") {
    itemType.value = "Non-Consumable"
  } else if (val === "Office Supplies") {
    itemType.value = ""
  } else {
    itemType.value = ""
  }
})

const isItemTypeLocked = computed(() => category.value === "School Equipment")

/* submit */
function submitRequest() {
  error.value = ""

  try {
    createRequest({
      itemName: itemName.value.trim(),
      category: category.value,
      itemType: itemType.value,
      qty: Number(qty.value),
      purpose: purpose.value.trim(),
      requester: user?.name || user?.username,
      role: user?.role
    })

    alert("Request submitted successfully")
    router.push("/pending-requests")
  } catch (e) {
    error.value = "Please complete all fields correctly."
  }
}
</script>

<template>
  <div class="card shadow-sm p-4" style="max-width: 600px">
    <h4 class="mb-4">Request Item</h4>

    <!-- Category -->
    <div class="mb-3">
      <label class="form-label">Category</label>
      <select v-model="category" class="form-select">
        <option value="">Select category</option>
        <option value="Office Supplies">Office Supplies</option>
        <option value="School Equipment">School Equipment</option>
      </select>
    </div>

    <!-- Item Type -->
    <div class="mb-3">
      <label class="form-label">
        Item Type
        <small class="text-muted">(auto-set for School Equipment)</small>
      </label>

      <select
        v-model="itemType"
        class="form-select"
        :disabled="isItemTypeLocked"
      >
        <option value="">Select item type</option>
        <option value="Consumable">Consumable</option>
        <option value="Non-Consumable">Non-Consumable</option>
      </select>
    </div>

    <!-- Item name -->
    <div class="mb-3">
      <label class="form-label">Item Name</label>
      <input v-model="itemName" class="form-control" />
    </div>

    <!-- Quantity -->
    <div class="mb-3">
      <label class="form-label">Quantity</label>
      <input
        type="number"
        min="1"
        v-model="qty"
        class="form-control"
      />
    </div>

    <!-- Purpose -->
    <div class="mb-3">
      <label class="form-label">Purpose</label>
      <textarea
        v-model="purpose"
        class="form-control"
        rows="3"
      ></textarea>
    </div>

    <!-- Error -->
    <div v-if="error" class="alert alert-danger py-2">
      {{ error }}
    </div>

    <!-- Submit -->
    <div class="text-end">
      <button class="btn btn-primary" @click="submitRequest">
        Submit Request
      </button>
    </div>
  </div>
</template>

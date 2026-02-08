<script setup>
import { ref, watch, computed } from "vue"
import { useRouter } from "vue-router"
import { createRequest, listRequests } from "../services/requests"
import { getCurrentUser } from "../services/storage"

const router = useRouter()
const user = getCurrentUser()

const activeTab = ref("create") // "create" | "mine"
const lastReceipt = ref(null)
const myRequests = ref([])
const selected = ref(null)
const category = ref("")
const itemType = ref("")
const itemName = ref("")
const qty = ref(1)
const purpose = ref("")
const error = ref("")

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

function loadMyRequests() {
  const all = listRequests()
  const key = (user?.username || user?.name || "").toLowerCase()
  myRequests.value = all
    .filter(r => (r.requester || "").toLowerCase() === key)
    .slice()
    .reverse()
}

function submitRequest() {
  error.value = ""

  try {
    const req = createRequest({
      itemName: itemName.value.trim(),
      category: category.value,
      itemType: itemType.value,
      qty: Number(qty.value),
      purpose: purpose.value.trim(),
      requester: user?.name || user?.username,
      role: user?.role
    })

    lastReceipt.value = req
    loadMyRequests()

    activeTab.value = "mine"

    category.value = ""
    itemType.value = ""
    itemName.value = ""
    qty.value = 1
    purpose.value = ""

    if ((user?.role || "").toLowerCase() === "admin") {
    router.push("/pending-requests")
    }
  } catch (e) {
    error.value = "Please complete all fields correctly."
  }
}

</script>

<template>
  <div style="max-width: 900px">

    <!-- Tabs -->
    <div class="d-flex gap-2 mb-3">
      <button
        class="btn"
        :class="activeTab === 'create' ? 'btn-primary' : 'btn-outline-primary'"
        @click="activeTab = 'create'"
      >
        Create Request
      </button>

      <button
        class="btn"
        :class="activeTab === 'mine' ? 'btn-primary' : 'btn-outline-primary'"
        @click="loadMyRequests(); activeTab = 'mine'"
      >
        My Requests
      </button>
    </div>

    <!-- CREATE REQUEST FORM -->
    <div v-if="activeTab === 'create'" class="card shadow-sm p-4">
    <h4 class="mb-4">Request Item</h4>

    <div class="mb-3">
      <label class="form-label">Category</label>
      <select v-model="category" class="form-select">
        <option value="">Select category</option>
        <option value="Office Supplies">Office Supplies</option>
        <option value="School Equipment">School Equipment</option>
      </select>
    </div>

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

    <div class="mb-3">
      <label class="form-label">Item Name</label>
      <input v-model="itemName" class="form-control" />
    </div>

    <div class="mb-3">
      <label class="form-label">Quantity</label>
        <input type="number" min="1" v-model="qty" class="form-control" />
    </div>

    <div class="mb-3">
      <label class="form-label">Purpose</label>
        <textarea v-model="purpose" class="form-control" rows="3"></textarea>
    </div>

    <div v-if="error" class="alert alert-danger py-2">
      {{ error }}
    </div>
    
    <div class="text-end">
      <button class="btn btn-primary" @click="submitRequest">
        Submit Request
      </button>
    </div>
  </div>

    <!-- MY REQUESTS + RECEIPT -->
    <div v-else>
      <!-- Receipt -->
      <div v-if="lastReceipt" class="alert alert-success">
        <div class="fw-semibold mb-1">Request Receipt</div>
        <div><strong>ID:</strong> {{ lastReceipt.id }}</div>
        <div><strong>Date:</strong> {{ lastReceipt.date }}</div>
        <div><strong>Status:</strong> {{ lastReceipt.status }}</div>
        <div><strong>Item:</strong> {{ lastReceipt.itemName }}</div>
        <div><strong>Qty:</strong> {{ lastReceipt.qty }}</div>
        <div><strong>Purpose:</strong> {{ lastReceipt.purpose }}</div>
      </div>

      <!-- Table -->
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="mb-3">My Requests</h5>

          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>Item</th>
                  <th>Qty</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th style="width: 120px">Details</th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="r in myRequests" :key="r.id">
                  <td>{{ r.itemName }}</td>
                  <td>{{ r.qty }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="(r.status||'').toLowerCase()==='approved'
                        ? 'bg-success'
                        : (r.status||'').toLowerCase()==='rejected'
                        ? 'bg-danger'
                        : 'bg-warning text-dark'"
                    >
                      {{ r.status }}
                    </span>
                  </td>
                  <td>{{ r.date }}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-secondary" @click="selected = r">
                      View
                    </button>
                  </td>
                </tr>

                <tr v-if="myRequests.length === 0">
                  <td colspan="5" class="text-center text-muted py-4">
                    No requests yet.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Details panel -->
          <div v-if="selected" class="mt-3 border-top pt-3">
            <h6 class="mb-2">Request Details</h6>
            <div><strong>ID:</strong> {{ selected.id }}</div>
            <div><strong>Category:</strong> {{ selected.category }}</div>
            <div><strong>Type:</strong> {{ selected.itemType }}</div>
            <div><strong>Item:</strong> {{ selected.itemName }}</div>
            <div><strong>Qty:</strong> {{ selected.qty }}</div>
            <div><strong>Purpose:</strong> {{ selected.purpose }}</div>
            <div><strong>Status:</strong> {{ selected.status }}</div>

            <div
              v-if="(selected.status||'').toLowerCase()==='rejected' && selected.rejectReason"
              class="text-danger mt-2"
            >
              <strong>Rejection Reason:</strong> {{ selected.rejectReason }}
            </div>

            <div class="mt-2">
              <button class="btn btn-sm btn-outline-dark" @click="selected = null">
                Close
              </button>
            </div>
          </div>

        </div>
      </div>
    </div>

  </div>
</template>


<script setup>
import { ref, onMounted } from 'vue'
import axios from '../axios'
import AlertMessage from '../components/AlertMessage.vue'

const requests = ref([])
const loading = ref(false)
const busyId = ref(null)
const showAlert = ref(false)
const alertType = ref('success')
const alertMessage = ref('')
function notify(type, message) { alertType.value = type; alertMessage.value = message; showAlert.value = true }
async function refresh() {
  loading.value = true
  try {
    const { data } = await axios.get('/requests/coordinator-queue')
    requests.value = Array.isArray(data) ? data : []
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not load requests waiting for coordinator review.') }
  finally { loading.value = false }
}
// Asks first (app modal, not the browser's native confirm), then forwards.
const confirmTarget = ref(null)
function forward(r) { confirmTarget.value = r }
async function doForward() {
  const r = confirmTarget.value
  confirmTarget.value = null
  if (!r) return
  busyId.value = r.id
  try {
    const { data } = await axios.put(`/requests/${r.id}/forward-to-inventory`)
    notify('success', data.message || 'Request forwarded.')
    await refresh()
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not forward request.') }
  finally { busyId.value = null }
}
function summary(r) {
  const names = [...(r.items || []).map(i => `${i.itemName} × ${i.qty}`), ...(r.uniforms || []).map(u => `${u.uniformName || 'Uniform'} (${u.size || 'size n/a'}) × ${u.quantity}`)]
  return names.length ? names.join(', ') : 'No line items found'
}
onMounted(refresh)
</script>

<template>
  <div class="container-fluid py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div><h2 class="mb-1">Coordinator Request Review</h2><p class="text-muted mb-0">Review requests and forward them to Inventory Management. This step does not approve the request or change stock.</p></div>
      <button class="btn btn-outline-success" @click="refresh" :disabled="loading">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
    </div>
    <AlertMessage v-model:show="showAlert" :type="alertType" :message="alertMessage" />
    <div v-if="loading && !requests.length" class="text-muted py-4">Loading coordinator queue…</div>
    <div v-else-if="!requests.length" class="alert alert-success">No requests are currently waiting for coordinator review.</div>
    <div v-else class="table-responsive bg-body rounded shadow-sm">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Request</th><th>Requester</th><th>Date</th><th>Items / uniforms</th><th>Purpose / location</th><th>Status</th><th>Action</th></tr></thead>
        <tbody><tr v-for="r in requests" :key="r.id">
          <td class="fw-semibold">#{{ r.id }}</td><td>{{ r.requester || 'Unknown' }}</td><td>{{ r.request_date ? new Date(r.request_date).toLocaleString('en-PH') : '—' }}</td>
          <td class="request-summary">{{ summary(r) }}</td><td><div>{{ r.purpose || '—' }}</div><small class="text-muted">{{ r.location || '—' }} / {{ r.room || '—' }}</small></td>
          <td><span class="badge text-bg-warning">With Coordinator</span></td>
          <td><button class="btn btn-sm btn-success" :disabled="busyId === r.id" @click="forward(r)">{{ busyId === r.id ? 'Forwarding…' : 'Forward to Inventory' }}</button></td>
        </tr></tbody>
      </table>
    </div>

    <div v-if="confirmTarget" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 420px">
        <div class="modal-header">
          <h5 class="mb-0">Forward Request</h5>
          <button class="btn-close" @click="confirmTarget = null"></button>
        </div>
        <div class="modal-body">
          <p class="mb-0">Forward request #{{ confirmTarget.id }} to Inventory Management?</p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="confirmTarget = null">Cancel</button>
          <button class="btn btn-success" @click="doForward">Forward</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.request-summary { min-width: 220px; max-width: 330px; white-space: normal; }
</style>

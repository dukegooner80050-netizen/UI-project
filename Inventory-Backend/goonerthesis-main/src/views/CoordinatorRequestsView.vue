<script setup>
import { ref, onMounted } from 'vue'
import axios from '../axios'
import AlertMessage from '../components/AlertMessage.vue'

const requests = ref([])
const directRequests = ref([])
const tab = ref('queue')
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
    const d = await axios.get('/requests/direct-queue')
    directRequests.value = Array.isArray(d.data) ? d.data : []
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
// Review of requests an instructor sent straight to the AMO. Neither action
// blocks the AMO; Flag also tells the AMO.
const flagTarget = ref(null)
const flagNote = ref('')
async function review(r, action, note = '') {
  busyId.value = r.id
  try {
    const { data } = await axios.put(`/requests/${r.id}/coordinator-review`, { action, note })
    notify('success', data.message || 'Saved.')
    flagTarget.value = null
    flagNote.value = ''
    await refresh()
  } catch (e) { notify('danger', e.response?.data?.message || 'Could not save your review.') }
  finally { busyId.value = null }
}
function openFlag(r) { flagTarget.value = r; flagNote.value = '' }
const unreviewedCount = () => directRequests.value.filter(r => !r.coordReview).length
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
    <div class="btn-group mb-3">
      <button class="btn" :class="tab === 'queue' ? 'btn-success' : 'btn-outline-success'" @click="tab = 'queue'">Waiting for review ({{ requests.length }})</button>
      <button class="btn" :class="tab === 'direct' ? 'btn-success' : 'btn-outline-success'" @click="tab = 'direct'">Sent directly to AMO ({{ unreviewedCount() }} to review)</button>
    </div>

    <template v-if="tab === 'direct'">
      <p class="text-muted small">Instructors can skip the department head when you are away. These already went to the AMO. Acknowledge them, or flag a concern; flagging notifies the AMO. Neither action blocks or undoes the request.</p>
      <div v-if="!directRequests.length" class="alert alert-success">No requests were sent directly to the AMO.</div>
      <div v-else class="table-responsive bg-body rounded shadow-sm">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Request</th><th>Instructor</th><th>Date</th><th>Items / uniforms</th><th>Why skipped</th><th>AMO status</th><th>Your review</th><th>Action</th></tr></thead>
          <tbody><tr v-for="r in directRequests" :key="r.id">
            <td class="fw-semibold">#{{ r.id }}</td><td>{{ r.requester || 'Unknown' }}</td>
            <td>{{ r.request_date ? new Date(r.request_date).toLocaleString('en-PH') : '—' }}</td>
            <td class="request-summary">{{ summary(r) }}</td>
            <td class="request-summary">{{ r.directReason }}</td>
            <td><span class="badge text-bg-secondary">{{ r.status }}</span></td>
            <td>
              <span v-if="!r.coordReview" class="badge text-bg-warning">Not reviewed</span>
              <span v-else class="badge" :class="r.coordReview === 'Flagged' ? 'text-bg-danger' : 'text-bg-success'">{{ r.coordReview }}</span>
              <div v-if="r.coordReviewNote" class="small text-muted mt-1">{{ r.coordReviewNote }}</div>
            </td>
            <td class="text-nowrap">
              <button v-if="r.coordReview !== 'Acknowledged'" class="btn btn-sm btn-success me-1" :disabled="busyId === r.id" @click="review(r, 'acknowledge')">Acknowledge</button>
              <button v-if="r.coordReview !== 'Flagged'" class="btn btn-sm btn-outline-danger" :disabled="busyId === r.id" @click="openFlag(r)">Flag</button>
            </td>
          </tr></tbody>
        </table>
      </div>
    </template>

    <template v-else>
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
    </template>

    <div v-if="flagTarget" class="modal-backdrop-custom">
      <div class="modal-custom" style="max-width: 460px">
        <div class="modal-header">
          <h5 class="mb-0">Flag request #{{ flagTarget.id }}</h5>
          <button class="btn-close" @click="flagTarget = null"></button>
        </div>
        <div class="modal-body">
          <label for="flag-note" class="form-label">What is your concern?</label>
          <textarea id="flag-note" name="flag_note" v-model="flagNote" class="form-control" rows="3" maxlength="500" placeholder="The AMO will see this note."></textarea>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="flagTarget = null">Cancel</button>
          <button class="btn btn-danger" :disabled="busyId === flagTarget.id || flagNote.trim().length < 3" @click="review(flagTarget, 'flag', flagNote.trim())">Flag and notify AMO</button>
        </div>
      </div>
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

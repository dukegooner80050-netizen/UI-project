/**
 * Turns an axios/Laravel error into a message a person can act on.
 *
 * axios's own e.message is just "Request failed with status code 422",
 * which hides the real reason. Laravel puts that in response.data:
 *   - response.data.errors  -> { field: ["The price field is required."] }
 *   - response.data.message -> summary of the above (or a plain error)
 */
export function getErrorMessage(e, fallback = "Something went wrong.") {
  const data = e?.response?.data;

  const errors = data?.errors;
  if (errors && typeof errors === "object") {
    const first = Object.values(errors).flat()[0];
    if (first) return String(first);
  }

  if (data?.message) return String(data.message);

  if (e?.response && !e.response.data) {
    return `${fallback} (status ${e.response.status})`;
  }

  return e?.message ? String(e.message) : fallback;
}

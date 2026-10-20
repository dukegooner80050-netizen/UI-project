// Roadmap #17: printable Borrower's Form (QF-AMO-03-07-01July.13), built
// from an existing request. No new data entry -- items, purpose and
// location already come from the request. REMARKS is left blank on
// purpose: on paper it is written by hand at return time.
// "Recommending Approval" and "Approved by" are paper-only sign-offs the
// software does not model, so they print as blank signature lines.

import { isReturnableType } from "./itemTypes";

// The form is for items that come back. Consumables, issued (Type A)
// items and uniforms are handed over for good, so they are not listed.
export function borrowedLines(request) {
  return (request?.items || []).filter((i) => isReturnableType(i.itemType));
}

export function hasBorrowedItems(request) {
  return borrowedLines(request).length > 0;
}

function esc(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatDate(value) {
  if (!value) return "";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

function sign(title, role, name) {
  return `
    <div class="sign">
      <div class="sign-title">${esc(title)}</div>
      <div class="sign-line">${esc(name || "")}</div>
      <div class="sign-role">${esc(role)}</div>
      <div class="sign-hint">Signature over printed name</div>
    </div>`;
}

export function buildBorrowerFormHtml(request) {
  const rows = borrowedLines(request).map((i) => ({
    qty: i.qty,
    desc: i.itemName,
  }));

  // Always print at least 10 lines so the table looks like the paper form.
  while (rows.length < 10) rows.push({ qty: "", desc: "" });

  const body = rows
    .map(
      (r) => `<tr>
        <td class="c">${esc(r.qty)}</td>
        <td class="c"></td>
        <td>${esc(r.desc)}</td>
        <td></td>
      </tr>`,
    )
    .join("");

  const location = [request.location, request.room].filter(Boolean).join(" - ");

  return `<!doctype html>
<html><head><meta charset="utf-8"><title>Borrower's Form - Request #${esc(request.id)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; margin: 0; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; }
  .code { font-size: 10px; }
  .head { text-align: center; margin: 4px 0 14px; }
  .head .school { font-size: 15px; font-weight: bold; }
  .head .office { font-size: 12px; }
  .head .title { font-size: 16px; font-weight: bold; margin-top: 8px; letter-spacing: 1px; }
  .fields { display: flex; gap: 14px; margin-bottom: 10px; }
  .field { flex: 1; }
  .field span.l { font-weight: bold; }
  .field span.v { display: block; border-bottom: 1px solid #000; min-height: 18px; padding: 1px 2px; }
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table.items th, table.items td { border: 1px solid #000; padding: 4px 6px; height: 24px; }
  table.items th { background: #eee; }
  .c { text-align: center; }
  .purpose { margin-bottom: 22px; }
  .purpose .box { border: 1px solid #000; min-height: 48px; padding: 4px 6px; margin-top: 3px; }
  .signs { display: grid; grid-template-columns: 1fr 1fr; gap: 26px 40px; }
  .sign-title { font-weight: bold; margin-bottom: 26px; }
  .sign-line { border-bottom: 1px solid #000; min-height: 18px; text-align: center; padding: 1px 2px; }
  .sign-role { text-align: center; font-weight: bold; margin-top: 2px; }
  .sign-hint { text-align: center; font-size: 9px; color: #444; }
  .meta { margin-top: 16px; font-size: 10px; color: #444; }
</style></head>
<body>
  <div class="top"><div></div><div class="code">QF-AMO-03-07-01July.13</div></div>
  <div class="head">
    <div class="school">Central Luzon Technological College</div>
    <div class="office">Asset Management Office</div>
    <div class="title">BORROWER'S FORM</div>
  </div>

  <div class="fields">
    <div class="field"><span class="l">Date of Request:</span><span class="v">${esc(formatDate(request.request_date))}</span></div>
    <div class="field"><span class="l">Department:</span><span class="v">&nbsp;</span></div>
    <div class="field"><span class="l">Location:</span><span class="v">${esc(location)}</span></div>
  </div>

  <table class="items">
    <thead><tr>
      <th style="width:90px">Quantity/ies</th>
      <th style="width:80px">Units</th>
      <th>ITEM / DESCRIPTION</th>
      <th style="width:150px">REMARKS</th>
    </tr></thead>
    <tbody>${body}</tbody>
  </table>

  <div class="purpose"><strong>Purpose:</strong><div class="box">${esc(request.purpose || "")}</div></div>

  <div class="signs">
    ${sign("Requested by:", "Borrower", request.requesterName || request.requester)}
    ${sign("Noted by:", "Department Head", request.notedBy)}
    ${sign("Recommending Approval:", "VP Administration / VPAA", "")}
    ${sign("Approved by:", "Pres. / EVP / SVP / VP", "")}
  </div>

  <div class="meta">CIMS Request #${esc(request.id)}${
    request.releaseMethod ? ` &middot; ${esc(request.releaseMethod)}` : ""
  }</div>
</body></html>`;
}

// Prints through a hidden iframe so popup blockers never get in the way.
export function printBorrowerForm(request) {
  const iframe = document.createElement("iframe");
  iframe.style.position = "fixed";
  iframe.style.right = "0";
  iframe.style.bottom = "0";
  iframe.style.width = "0";
  iframe.style.height = "0";
  iframe.style.border = "0";
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow.document;
  doc.open();
  doc.write(buildBorrowerFormHtml(request));
  doc.close();

  setTimeout(() => {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => iframe.remove(), 1000);
  }, 250);
}

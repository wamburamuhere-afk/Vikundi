# Vikundi — Frontend UI Constants (always loaded)

Apply these on every file touched. No exceptions, no need to be told each time.

---

## §UI-0. Scope & Enforcement (mandatory — read first)

These constants are **binding on every page in the system**, not only the part you were
asked to change.

1. **Whole-page compliance.** Whenever you touch a page, bring the **entire** page into full
   compliance with §UI-1 … §UI-8. Fix **every** violation you find — colors, buttons, badges,
   DataTables, Select2, SweetAlert2, gear-dropdown actions, reference numbers, mobile cards,
   icons. **Leave nothing behind.** Do not fix only the lines you edited.
2. **No partial passes.** A page is "done" only when every interactive element and every visual
   element on it satisfies this file. If a rule cannot apply, say why in your summary.
3. **Lead with this file.** Every file you touch must reference this standard at the top
   (`// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)`), and you must read this file
   before editing so the change is measured against it.
4. **Bilingual.** All user-facing text is English + Swahili via
   `$_SESSION['preferred_language']` (`en` / `sw`). Never hard-code a single language.

---

## §UI-1. Color Scheme

| Role | Value |
|---|---|
| **Page background** | White `#fff` |
| **Primary action color** | Blue — Bootstrap `#0d6efd` / `btn-primary` / `bg-primary` / `text-primary` |
| **Modal headers** | `bg-primary text-white` — always blue, never green, yellow, warning |
| **Save / Submit buttons** | `btn-primary` — never `btn-success` or `btn-warning` |
| **Cancel buttons** | `btn-secondary` |
| **Delete buttons** | `btn-outline-danger` in tables, `btn-danger` in confirm dialogs |
| **Section icons / accents** | `text-primary` |
| **Card borders / highlights** | `border-primary` or `#b6ccfe` |
| **Stat card backgrounds** | `#e7f0ff` with `border: 1px solid #b6ccfe` |

**Status badges — blue scale only (no green, no yellow):**

| Status | Background | Text color |
|---|---|---|
| `draft` | `#e9ecef` | `#495057` |
| `pending` | `#e9ecef` | `#495057` |
| `submitted` | `#cfe2ff` | `#084298` |
| `reviewed` | `#bfdbfe` | `#1e3a8a` |
| `approved` | `#0d6efd` | `#fff` |
| `paid` / `posted` | `#052c65` | `#fff` |
| `rejected` / `void` | `#dc3545` | `#fff` |
| `cancelled` | `#6c757d` | `#fff` |
| `active` | `#0d6efd` | `#fff` |
| `inactive` | `#6c757d` | `#fff` |

---

## §UI-2. DataTable — Standard Initialisation

Every `<table>` backed by a database must be a DataTable. Use this exact pattern:

```js
const table = $('#myTable').DataTable({
    responsive: false,
    scrollX: true,
    pageLength: 25,
    order: [[1, 'asc']],
    dom: 'rtipB',
    buttons: [
        { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
    ],
    drawCallback: function () {
        renderCards(this.api().rows({ page: 'current' }).data().toArray());
    }
});
```

**Reloading data (AJAX-backed tables):** never `location.reload()` — always clear and redraw:
```js
table.clear().rows.add(newData).draw();
```

**Empty state text:**
```js
language: { emptyTable: 'No records found.', zeroRecords: 'No matching records.' }
```

---

## §UI-3. Select2 — DB-backed Dropdowns

Every `<select>` populated from the database must use Select2. Never a plain `<select>`.

**Static (options pre-loaded in PHP):**
```html
<select class="form-select select2-static" name="field_name" required>
    <option value="">-- Select --</option>
    <?php foreach ($rows as $r): ?>
    <option value="<?= $r['id'] ?>"><?= safe_output($r['name']) ?></option>
    <?php endforeach; ?>
</select>
```
```js
// Init inside shown.bs.modal:
$('#myModal').on('shown.bs.modal', function () {
    $(this).find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#myModal'),
                placeholder: 'Select...',
                allowClear: true,
                width: '100%'
            });
        }
    });
});
```

**AJAX (large datasets — search by typing):**
```js
$('#mySelect').select2({
    theme: 'bootstrap-5',
    dropdownParent: $('#myModal'),
    placeholder: 'Type to search...',
    allowClear: true,
    width: '100%',
    minimumInputLength: 1,
    ajax: {
        url: '<?= buildUrl('api/search_items.php') ?>',
        dataType: 'json',
        delay: 300,
        data: params => ({ q: params.term }),
        processResults: data => ({ results: data.results }),
        cache: true
    }
});
```

**Destroy before repopulate (dynamic selects):**
```js
if ($('#mySelect').hasClass('select2-hidden-accessible')) $('#mySelect').select2('destroy');
$('#mySelect').empty().append('<option value="">-- Select --</option>');
// ... append new options ...
$('#mySelect').select2({ theme: 'bootstrap-5', dropdownParent: $('#myModal'), ... });
```

---

## §UI-4. SweetAlert2 — All Alerts & Confirmations

Never `alert()`, `confirm()`, or `prompt()`. Always SweetAlert2.

```js
// Delete confirmation
Swal.fire({
    title: 'Delete?',
    text: 'This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#dc3545',
    confirmButtonText: 'Yes, Delete'
}).then(r => { if (r.isConfirmed) { /* delete */ } });

// Loading spinner during async
Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

// Success
Swal.fire({ icon: 'success', title: 'Done!', text: res.message, timer: 2000, showConfirmButton: false });

// Error
Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
```

**After save (modal form):** always hide modal + reload list BEFORE SweetAlert — never inside `.then()`:
```js
success: function (res) {
    if (res.success) {
        bootstrap.Modal.getInstance(document.getElementById('myModal')).hide();
        loadData();   // reload list immediately
        Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
    } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
    }
}
```

---

## §UI-5. Action Buttons — Gear Dropdown (mandatory)

Every table row's Actions column must use a single gear+caret dropdown. Never individual icon buttons side by side.

```js
// JS template literal pattern (inside DataTable render or drawCallback)
function actionButtons(row) {
    let items = '';
    items += `<li><a class="dropdown-item py-2 rounded" href="${VIEW_URL}?id=${row.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>`;
    if (CAN_EDIT)   items += `<li><button class="dropdown-item py-2 rounded" onclick="editRow(${row.id})"><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>`;
    if (CAN_DELETE) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="confirmDelete(${row.id})"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>`;

    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear-fill me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}
```

---

## §UI-6. Auto-generated Reference Numbers

Pattern: `PREFIX-YYYY-NNNN` (e.g. `INV-2026-0001`, `PO-2026-0042`)

```html
<!-- Always input-group with refresh button -->
<div class="input-group">
    <input type="text" class="form-control" name="ref_no" id="f-ref" placeholder="Auto-generating..." required>
    <button type="button" class="btn btn-outline-secondary" id="btnRefresh" onclick="generateRef()" title="Regenerate"><i class="bi bi-arrow-clockwise"></i></button>
</div>
```
```js
// Refresh button: visible on Add modal, hidden on Edit modal
function generateRef() {
    $.getJSON(API_URL, { action: 'get_next_ref' }, function (res) {
        if (res.success) $('#f-ref').val(res.ref);
    });
}
// On modal open:
$('#myModal').on('shown.bs.modal', function () {
    const isEdit = !!$('#f-id').val();
    $('#btnRefresh').toggleClass('d-none', isEdit);
    if (!isEdit) generateRef();
});
```

---

## §UI-7. Mobile Card View

On every list page, at `max-width: 767px`:
- Table hidden, card view shown
- Each card has action buttons in a single non-wrapping row (icon-only, `flex:1`)
- Page header sticky (`position:sticky; top:0; z-index:1020`)

```js
function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No records found</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${safeOutput(row.name)}</div>
                    <small class="text-muted">${safeOutput(row.status)}</small>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        <button class="btn btn-sm btn-outline-primary" onclick="editRow(${row.id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger"  onclick="confirmDelete(${row.id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
```

---

## §UI-8. Icons

Bootstrap Icons only: `bi bi-*`. Never Font Awesome.

Common: `bi-plus-circle`, `bi-pencil`, `bi-trash`, `bi-eye`, `bi-gear-fill`, `bi-check-circle`, `bi-x-circle`, `bi-arrow-clockwise`, `bi-receipt`, `bi-printer`, `bi-download`, `bi-paperclip`, `bi-people`, `bi-building`

# AGENTS.md — RIVO Clinic

## Project

RIVO is the clinical management platform for Clinique Saint Georges.

Official CDC repository:

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Mandatory reading

Before implementing, modifying, refactoring or reviewing business logic:

1. Read `docs/CDC_REFERENCE.md`.
2. Read `docs/DECISIONS.md`.
3. Read `docs/AI_CONTEXT.md`.
4. Inspect the official CDC:
   `https://github.com/GasyCoder/cdc-clinic-george`
5. Check `docs/ROADMAP.md`.
6. Inspect the existing implementation.

Do not invent business rules.

If the CDC cannot be accessed, explicitly report it before making assumptions.

---

# Source priority

Use this order:

```text
1. Explicit current requirement from the user/project owner
2. docs/DECISIONS.md
3. Official CDC repository
4. docs/AI_CONTEXT.md
5. Existing code
```

If sources conflict, report the conflict.

Do not silently choose one.

---

# Architecture

Two independent operational sites:

```text
Mampikony
Ambondromamy
```

Databases:

```text
DB_MAMPIKONY
DB_AMBONDROMAMY
```

One shared codebase.

Never create direct SQL/database communication between the two sites.

All inter-site communication must use REST API.

---

# Domains

```text
Public website:
https://cliniquesaintgeorges.mg

Mampikony:
https://clinique-m.rivo.mg

Ambondromamy:
https://clinique-a.rivo.mg

Super Admin:
https://admin.rivo.mg
```

---

# Stack

```text
Laravel 13
Vue.js
Inertia.js
Tailwind CSS
DashWind
MySQL / MariaDB
REST API
Laravel Queue / Jobs
```

---

# Backend rules

Keep controllers thin.

Prefer business logic in:

```text
Actions
Services
Policies
Form Requests
DTOs
Enums
Jobs
Events
Listeners
```

Never put critical business rules only in Vue components.

Backend Laravel is the source of authorization truth.

Frontend checks are UX only.

---

# Main roles

```text
SUPER_ADMIN
ADMINISTRATION
RECEPTION
MEDICINE
SURGERY
PHARMACY
LABORATORY
```

Roles are not sufficient for authorization.

Use granular dynamic permissions.

---

# Permission convention

Use:

```text
resource.action
```

or when necessary:

```text
module.resource.action
```

Examples:

```text
patients.view
patients.create
patients.update
patients.delete
patients.restore

laboratory.results.validate

pharmacy.stock.transfer

payments.create
```

---

# Permission actions

Supported base actions include:

```text
view
create
update
delete
restore
view_deleted

validate
approve
reject
cancel

activate
deactivate

print
export
import

open
close

assign
transfer

archive
unarchive

sync
manage

force_delete
```

The permission catalog must remain dynamic.

---

# Financial invariant — CRITICAL

There is exactly ONE functional cash desk per operational site.

The only module allowed to collect payments is:

```text
RECEPTION / CASH
```

All payments must be handled by Reception / Cash.

Never implement payment collection in:

```text
PHARMACY
LABORATORY
MEDICINE
SURGERY
ADMINISTRATION
```

Other modules may create billable items.

They cannot collect money.

---

# Pharmacy invariant — CRITICAL

Pharmacy manages:

```text
prescriptions
medicines
dispensing
stock
lots
expiration
inventory
returns
stock transfers
```

Pharmacy must never manage:

```text
cash desk
payment collection
cash opening
cash closing
financial refunds
payment receipts
```

Never add `payments.*` or `cash.*` behavior to Pharmacy unless an explicit future decision changes the CDC.

---

# Laboratory invariant

Laboratory may:

```text
read payment status when required
```

Laboratory may not:

```text
create payment
update payment
collect payment
close cash
```

---

# Medicine invariant

Medicine may create medical and billable activities.

Medicine may not collect payment.

---

# Surgery invariant

Surgery may create surgical and billable activities.

Surgery may not collect payment.

---

# Soft Delete

Normal delete means:

```text
Soft Delete
```

Where applicable support:

```text
delete
restore
view_deleted
force_delete
```

Critical clinical and financial records must not normally be physically deleted.

Prefer:

```text
cancel
correct
reverse
archive
```

All sensitive deletions/restorations must be audited.

---

# Audit

Audit sensitive operations.

Examples:

```text
create
update
delete
restore
force_delete

validate
approve
reject
cancel

payment creation
payment cancellation
refund

stock adjustment
stock transfer

laboratory validation

role assignment
permission assignment

API transactions
```

---

# API rules

Base API:

```text
/api/v1
```

For distributed operations use:

```text
UUID
request UUID
idempotency key
authentication
authorization
validation
audit
queue
retry
backoff
timeout
```

A remote API failure must not corrupt local state.

Never assume the remote site is always available.

---

# Distributed IDs

Local database IDs remain local.

Entities exchanged between sites must have UUIDs.

Example:

```text
id   = local database ID
uuid = distributed identifier
```

---

# Super Admin

`admin.rivo.mg` communicates with both operational sites using API.

Never connect the Super Admin directly to:

```text
DB_MAMPIKONY
DB_AMBONDROMAMY
```

All reads and writes must go through site APIs and authorization.

---

# UI

DashWind is the UI foundation.

Use:

```text
Vue.js
Inertia.js
DashWind
Tailwind CSS
```

Reuse existing DashWind components.

Do not introduce another design system without explicit approval.

---

# Before coding a feature

Perform this checklist:

```text
1. Identify affected module.
2. Read docs/DECISIONS.md.
3. Read docs/AI_CONTEXT.md.
4. Inspect CDC repository.
5. Inspect existing code.
6. Identify required permissions.
7. Identify business rules.
8. Identify validation rules.
9. Identify audit requirements.
10. Identify Soft Delete requirements.
11. Identify API impact.
12. Identify tests.
```

---

# After implementation

Verify:

```text
authorization
validation
business rules
permissions
audit
soft delete
tests
API failure behavior
unrelated regressions
```

Run relevant tests.

Do not modify unrelated functionality.

Never commit:

```text
.env
API secrets
database passwords
tokens
private keys
```

---

# CDC

Always keep this reference available:

```text
https://github.com/GasyCoder/cdc-clinic-george
```

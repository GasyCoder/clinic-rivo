---
name: clinic-cdc
description: Use this skill for any RIVO Clinic task involving patients, reception, cash, medicine, surgery, laboratory, pharmacy, stock, administration, permissions, API, transfers, audit, Soft Delete or Super Admin.
---

# RIVO Clinic — CDC Skill

Official CDC repository:

```text
https://github.com/GasyCoder/cdc-clinic-george
```

---

# Purpose

Use this skill whenever a task touches RIVO business logic.

Examples:

```text
Patient
Reception
Cash
Billing
Payments
Medicine
Care
Laboratory
Pharmacy
Stock
Surgery
Administration
Roles
Permissions
Soft Delete
Audit
API
Transfers
Super Admin
Reports
```

---

# STEP 1 — Read local decisions

Read:

```text
docs/DECISIONS.md
```

These contain the most recent accepted technical decisions.

---

# STEP 2 — Read project context

Read:

```text
docs/AI_CONTEXT.md
```

---

# STEP 3 — Read CDC reference

Read:

```text
docs/CDC_REFERENCE.md
```

---

# STEP 4 — Inspect official CDC

When GitHub/network access is available, inspect:

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Find the section relevant to the requested module.

Do not read unrelated sections unless necessary.

---

# STEP 5 — Inspect existing implementation

Before editing code, identify:

```text
Models
Migrations
Controllers
Actions
Services
Policies
Requests
Routes
Vue pages
Tests
Permissions
Existing database constraints
```

---

# STEP 6 — Resolve conflicts

Priority:

```text
Current explicit project instruction
        ↓
docs/DECISIONS.md
        ↓
Official CDC
        ↓
docs/AI_CONTEXT.md
        ↓
Existing code
```

If two sources conflict:

```text
DO NOT SILENTLY CHOOSE.
```

Report:

```text
Source A
Source B
Conflict
Affected behavior
Recommended resolution
```

---

# STEP 7 — Identify permissions

For every operation identify required permission.

Example:

```text
patients.view
patients.create
patients.update
patients.delete
patients.restore
```

Do not authorize solely by role name.

---

# STEP 8 — Identify business invariants

Always verify the following.

## Database

```text
DB_MAMPIKONY
DB_AMBONDROMAMY
```

No direct cross-database communication.

---

## API

All inter-site communication uses REST API.

---

## Cash

One cash desk per site.

Only Reception / Cash collects payments.

---

## Pharmacy

No cash.

No payment.

No financial refund.

No payment receipt.

---

## Laboratory

No payment collection.

---

## Medicine

No payment collection.

---

## Surgery

No payment collection.

---

## Delete

Normal delete means Soft Delete.

---

## Audit

Sensitive actions must be audited.

---

# STEP 9 — Implement backend first

Preferred flow:

```text
Request
   ↓
Form Request
   ↓
Policy / Permission
   ↓
Action / Service
   ↓
Business Logic
   ↓
Model
   ↓
Database
```

Keep Controllers thin.

---

# STEP 10 — Vue / Inertia

Frontend may hide unavailable actions for UX.

Example:

```text
can('patients.create')
```

But frontend checks are never sufficient for security.

Laravel must always enforce authorization.

Use DashWind UI components whenever appropriate.

---

# STEP 11 — Soft Delete

For a deletable entity verify:

```text
SoftDeletes trait
deleted_at
permission delete
permission restore
permission view_deleted
audit
```

If `force_delete` is available, verify that the entity is allowed to be physically deleted.

---

# STEP 12 — Distributed/API operation

For inter-site operations verify:

```text
UUID
request UUID
idempotency
API authentication
API authorization
payload validation
transaction
queue
retry
backoff
timeout
audit
error handling
```

Do not assume the remote site is online.

---

# STEP 13 — Tests

Add relevant tests.

At minimum consider:

```text
authorized user
unauthorized user
invalid payload
business rule violation
Soft Delete
restore
audit
API success
API failure
API retry
idempotency
```

---

# STEP 14 — Final verification

Before completing the task verify:

```text
CDC respected
DECISIONS respected
Permissions correct
Validation correct
Audit correct
Soft Delete correct
API architecture respected
Financial invariant respected
Tests passing
No unrelated modification
```

---

# Critical reminder

Never implement:

```text
Pharmacy → Payment
Laboratory → Payment
Medicine → Payment
Surgery → Payment
```

Payment authority is:

```text
Reception / Cash only
```

---

# If CDC cannot be accessed

Do not invent requirements.

State clearly that the external CDC could not be read.

Continue only with rules explicitly supported by:

```text
docs/DECISIONS.md
docs/AI_CONTEXT.md
```

and request clarification for missing business rules.

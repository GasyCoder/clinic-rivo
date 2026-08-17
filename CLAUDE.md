# CLAUDE.md — RIVO Clinic

@docs/CDC_REFERENCE.md
@docs/DECISIONS.md
@docs/AI_CONTEXT.md
@docs/ROADMAP.md

# Official CDC

The official Cahier des Charges is maintained here:

```text
https://github.com/GasyCoder/cdc-clinic-george
```

Before implementing business logic, inspect the relevant CDC section whenever repository access is available.

If the CDC repository is unavailable, do not invent missing business rules.

---

# Project

RIVO is the clinical management platform for Clinique Saint Georges.

Sites:

```text
Mampikony
Ambondromamy
```

Architecture:

```text
Mampikony
→ Laravel
→ DB_MAMPIKONY

Ambondromamy
→ Laravel
→ DB_AMBONDROMAMY
```

There is one shared codebase but two independent operational databases.

---

# Inter-site communication

Never connect the two databases directly.

Use REST API for all inter-site operations.

Examples:

```text
patient transfers
stock transfers
remote patient lookup
authorized medical data exchange
Super Admin operations
reports
statistics
```

---

# Super Admin

Domain:

```text
https://admin.rivo.mg
```

Super Admin communicates with both sites exclusively through their APIs.

Never perform direct remote database access.

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

# Financial rule — CRITICAL

There is one cash desk per site.

Only:

```text
Reception / Cash
```

handles payments.

Never implement payment collection in:

```text
Pharmacy
Laboratory
Medicine
Surgery
Administration
```

Other modules generate billable items only.

---

# Pharmacy rule — CRITICAL

Pharmacy handles:

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

Pharmacy never handles:

```text
cash
payment
financial refund
cash closing
payment receipts
```

---

# Permissions

Authorization is dynamic.

Main roles:

```text
SUPER_ADMIN
ADMINISTRATION
RECEPTION
MEDICINE
SURGERY
PHARMACY
LABORATORY
```

Do not rely only on hardcoded role names.

Use granular permissions.

Convention:

```text
resource.action
```

---

# Delete

Normal delete uses Soft Delete.

Use when appropriate:

```text
delete
restore
view_deleted
force_delete
```

Critical medical and financial records should normally use:

```text
cancel
correct
reverse
archive
```

instead of physical deletion.

---

# Audit

Sensitive operations must be auditable.

Never bypass audit because the actor is Super Admin.

---

# Laravel architecture

Keep Controllers thin.

Prefer:

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

Use Laravel Policies or equivalent backend authorization.

Do not place critical business logic only in Vue.

---

# API

Version API endpoints:

```text
/api/v1
```

Critical distributed operations must consider:

```text
UUID
request UUID
idempotency
authentication
authorization
validation
queue
retry
backoff
timeout
audit
```

---

# UI

Use DashWind as the main UI foundation.

Do not introduce another UI framework/design system without explicit approval.

---

# Mandatory workflow

Before implementing a business feature:

```text
1. Read imported project documentation.
2. Inspect docs/DECISIONS.md.
3. Inspect the CDC repository.
4. Inspect the current implementation.
5. Identify business rules.
6. Identify permissions.
7. Identify validation.
8. Identify audit requirements.
9. Identify Soft Delete requirements.
10. Identify API implications.
11. Implement.
12. Add/update tests.
```

If a requirement is ambiguous:

```text
STOP
REPORT THE AMBIGUITY
DO NOT INVENT A BUSINESS RULE
```

If code conflicts with documentation:

Report:

```text
Current code behavior
CDC requirement
DECISIONS.md requirement
Affected files
Recommended correction
```

Do not silently change critical behavior.

# Customer Launch Hardening Checklist

Last updated: 2026-03-06
Project: Solterra Logistics Portal
Status: No-Go for customer licensing in current state

## Purpose

This document is the working checklist for moving the portal from internal/pilot use to a customer-licensable product.

Use this as the launch gate. A customer launch is approved only when all critical items are complete, verified, and documented.

## Current Release Decision

- Current recommendation: Do not license to customers yet.
- Current hosting recommendation: Do not keep the paid customer-facing production deployment on GoDaddy shared hosting.

## Production Layout Snapshot

Source: direct SSH inspection of the current production account on 2026-03-06.

### Hosting account shape

- Provider: GoDaddy shared cPanel hosting
- cPanel package observed: `Deluxe1`
- Server family observed: Apache + MariaDB on Linux
- PHP CLI observed: `8.2.30`
- cPanel account home: `~/`
- Shared web root: `~/public_html`

### Applications currently living under the same cPanel account

- `~/public_html/Solterra-Logistics-Portal`
  - Current production logistics portal
- `~/public_html/dev.solterrasol.com/Solterra-Logistics-Portal`
  - Dev copy of the logistics portal
- `~/public_html/crm`
  - Separate CRM application
- `~/public_html/Solterra-Site-Portal`
  - Separate site portal application

### Approximate footprint observed

- Production logistics portal: about `723M`
- Dev logistics portal: about `320M`
- CRM app: about `189M`

### Production app structure observed

- Main app path: `~/public_html/Solterra-Logistics-Portal`
- AI assistant path: `~/public_html/Solterra-Logistics-Portal/ai-assistant`
- Public upload folders inside app tree:
  - `uploads/`
  - `warehouse_documents/`
  - `ai-assistant/uploads/`
- Composer vendor directories inside served tree:
  - `vendor/`
  - `vendor_old/`

### Config and bootstrap layout observed

- Production shared config file: `~/public_html/config.php`
- Production secrets file loaded by config: `~/public_html/env.php`
- Dev config file also exists: `~/public_html/dev.solterrasol.com/config.php`
- Dev secrets file also exists: `~/public_html/dev.solterrasol.com/env.php`
- Production app-level `.user.ini` sets:
  - `auto_prepend_file=/home/dbos1p4y0di2/public_html/Solterra-Logistics-Portal/prepend.php`

### Apache rules observed

- `~/public_html/.htaccess`
  - Denies direct access to `config.php`, `config.dev.php`, and `env.php`
  - Sets some environment variables
  - Provides root-level friendly URL rewrite behavior
- `~/public_html/Solterra-Logistics-Portal/.htaccess`
  - Provides friendly URL rewrites only
  - Does not currently deny access to debug files, schema dumps, logs, or internal artifacts inside the app directory

## Confirmed Production Findings

These findings were confirmed directly on the server and should drive launch hardening priorities.

### Confirmed high-risk public artifacts in the production app tree

- `~/public_html/Solterra-Logistics-Portal/phpinfo.php`
- `~/public_html/Solterra-Logistics-Portal/portal_schema.sql`
- `~/public_html/Solterra-Logistics-Portal/apply_schema.php`
- `~/public_html/Solterra-Logistics-Portal/debug_schema.php`
- `~/public_html/Solterra-Logistics-Portal/ping.php`
- `~/public_html/Solterra-Logistics-Portal/error_log`
- `~/public_html/Solterra-Logistics-Portal/api/error_log`
- `~/public_html/Solterra-Logistics-Portal/ai-assistant/api/error_log`

### Confirmed architecture concerns

- Production, dev, CRM, and other web properties are deployed under the same shared cPanel account and same top-level served tree.
- Runtime dependencies and historical dependencies are present in the served app tree (`vendor/`, `vendor_old/`).
- Uploads and generated artifacts live under the served application tree.
- App-wide request behavior depends on `.user.ini` + `auto_prepend_file`, which is workable on shared hosting but fragile as a long-term production pattern.
- Root config and secrets are somewhat protected by root `.htaccess`, but the production app directory has no equivalent deny rules for its own debug and artifact files.

### Immediate implications

- The app can be improved in place, but the current production environment is not a clean boundary for customer licensing.
- Public-exposure cleanup in the app directory is a `P0` item.
- Production hosting migration remains the recommended medium-term path even if near-term hardening is done on GoDaddy first.

## Exit Criteria

The portal is considered launch-ready only when all are true:

1. No exposed secrets, debug files, schema dumps, or internal docs are publicly reachable.
2. All privileged write actions have authentication, authorization, and CSRF protection.
3. Session handling is centralized and hardened, including session ID rotation on login.
4. Tenant isolation is verified for all customer-facing pages and API endpoints.
5. File upload and download surfaces are validated and tested for abuse cases.
6. Production hosting, deployment, backup, rollback, logging, and incident response are documented.
7. Core workflows have repeatable test coverage and a signed UAT checklist.
8. Licensing, support, privacy, and customer onboarding materials exist in final form.

## Priority Model

- `P0`: Stop-ship blocker. Must be fixed before any customer licensing.
- `P1`: Must be complete before general availability.
- `P2`: Important hardening or operational maturity item.
- `P3`: Nice-to-have follow-up after launch.

## Phase 0 - Stop-Ship Security and Exposure

### Secret and artifact exposure

- [ ] `P0` Remove committed secrets from the repository and rotate any exposed credentials.
  - Known example: `dev.solterrasol.com/env.php`
  - Acceptance: exposed DB credentials, API keys, and mail credentials are rotated and removed from repo history going forward.
- [ ] `P0` Remove or block public debug and diagnostics files.
  - Known example: `phpinfo.php`
  - Acceptance: debug endpoints return `404` or are deleted.
- [ ] `P0` Block public access to internal artifacts in the web root.
  - Includes: `*.sql`, `migrations/`, `docs/`, deployment notes, AI prompt files, logs, local config variants.
  - Acceptance: direct web requests to these artifacts are denied.
- [ ] `P0` Audit the document root and move anything non-public outside the served tree where possible.
  - Acceptance: public root contains only runtime app assets and intended entrypoints.

### Hosting and infrastructure

- [ ] `P0` Decide the target production platform for licensed customers.
  - Preferred direction: VPS or managed app host with isolated runtime, controlled deploys, backups, and environment secrets.
  - Acceptance: written production architecture exists and is approved.
- [ ] `P0` If GoDaddy shared hosting remains temporary, document the compensating controls and launch limitations.
  - Acceptance: explicit risk sign-off exists, including scope, duration, and migration date.

## Phase 1 - App Security Hardening

### Auth and session management

- [ ] `P0` Centralize session bootstrap and auth checks.
  - Replace scattered per-file `session_start()` and ad hoc auth patterns with a shared guard.
  - Acceptance: all protected routes use the same bootstrap and authorization helpers.
- [ ] `P0` Regenerate session IDs on successful login.
  - Known gap: login flow does not currently rotate session ID after authentication.
  - Acceptance: session fixation test passes.
- [ ] `P1` Standardize secure cookie settings across all entrypoints.
  - Acceptance: `HttpOnly`, `Secure`, and `SameSite` are applied consistently in production.
- [ ] `P1` Add idle timeout and absolute session lifetime policy.
  - Acceptance: policy is enforced and documented.

### CSRF and write protection

- [ ] `P0` Inventory every state-changing endpoint.
  - Includes: `api/`, `process_*`, upload handlers, delete handlers, edit handlers, invite/reset flows.
  - Acceptance: route inventory exists with owner and risk level.
- [ ] `P0` Add CSRF protection to all authenticated write actions.
  - Known high-risk example: `api/admin_management.php`
  - Acceptance: every privileged POST/PUT/DELETE path verifies CSRF or equivalent origin-bound protection.
- [ ] `P1` Standardize JSON API auth rules.
  - Acceptance: JSON endpoints reject missing auth, wrong role, bad method, and invalid CSRF consistently.

### Authorization and tenant isolation

- [ ] `P0` Verify account scoping for every customer-visible page and API endpoint.
  - Acceptance: no cross-account reads or writes are possible in role-based testing.
- [ ] `P0` Review all endpoints that accept IDs from request input.
  - Acceptance: each endpoint verifies the current user can act on the referenced entity.
- [ ] `P1` Build and maintain a route-to-role matrix for both pages and utility endpoints.
  - Acceptance: route coverage is complete, not just page coverage.

### HTTP and browser security

- [ ] `P1` Add baseline security headers.
  - Minimum: `Content-Security-Policy`, `Strict-Transport-Security`, `X-Frame-Options` or CSP equivalent, `X-Content-Type-Options`, `Referrer-Policy`.
  - Acceptance: headers are present on authenticated app responses.
- [ ] `P1` Enforce HTTPS for all production traffic.
  - Acceptance: HTTP redirects to HTTPS and cookies are never downgraded.

## Phase 2 - File, Upload, and Data Surface Hardening

### Uploads and documents

- [ ] `P0` Inventory every file upload endpoint and document storage path.
  - Acceptance: upload matrix exists with max size, allowed types, destination, and auth model.
- [ ] `P1` Ensure uploads cannot become executable content.
  - Acceptance: uploaded files are stored outside executable paths or protected by server rules.
- [ ] `P1` Validate file type by content, size, extension, and business context everywhere.
  - Acceptance: shared validation helper is used consistently.
- [ ] `P1` Add malware and abuse handling policy for customer uploads.
  - Acceptance: operational procedure exists even if scanning is initially manual.

### Downloads and direct file access

- [ ] `P0` Confirm all document download/view endpoints enforce auth and account scope before serving files.
  - Acceptance: IDOR review passes for invoices, PODs, project docs, archives, and generated exports.
- [ ] `P1` Normalize content-disposition and content-type handling for served files.
  - Acceptance: download responses are safe and predictable across all document types.

### Data protection

- [ ] `P1` Classify data handled by the portal.
  - Categories: customer business data, personal data, internal admin data, AI/chat data, uploaded files.
  - Acceptance: retention and access rules exist per category.
- [ ] `P1` Add backup, restore, and data retention procedures.
  - Acceptance: backups are scheduled, tested, and documented.

## Phase 3 - Operations, Deployment, and Observability

### Deployment and config management

- [ ] `P0` Remove manual-only production deployment as the primary process.
  - Acceptance: documented release flow exists with rollback steps.
- [ ] `P1` Separate dev, staging, and production configuration cleanly.
  - Acceptance: no dev secrets or dev bootstrap behavior can leak into production.
- [ ] `P1` Replace global warning suppression with root-cause fixes.
  - Known example: `prepend.php`
  - Acceptance: session warnings are resolved by code changes, not masked globally.

### Logging and monitoring

- [ ] `P1` Define application logs, audit logs, and security logs.
  - Acceptance: auth failures, admin actions, invite/reset flows, and destructive actions are auditable.
- [ ] `P1` Ensure logs do not leak secrets, tokens, or sensitive customer data.
  - Acceptance: sampled log review passes.
- [ ] `P2` Add uptime/error monitoring and alerting.
  - Acceptance: critical failures generate notifications to the owner/operator.

### Incident readiness

- [ ] `P1` Create a simple incident response playbook.
  - Includes: credential rotation, customer notification path, rollback path, and evidence collection.
  - Acceptance: one-page runbook exists.

## Phase 4 - Quality, Testing, and Release Discipline

### Automated and manual test coverage

- [ ] `P0` Define the core customer workflows that must pass before every release.
  - Minimum candidates: login, password reset, dashboard access, project visibility, document upload/download, shipment creation, delivery updates, admin user invite.
  - Acceptance: release checklist references each workflow explicitly.
- [ ] `P1` Add repeatable tests for auth, authorization, and tenant isolation.
  - Acceptance: critical access-control regressions are caught before deploy.
- [ ] `P1` Add smoke tests for top entrypoints and APIs.
  - Acceptance: deploy verification can be run in minutes.
- [ ] `P1` Establish a staging or pre-production verification environment.
  - Acceptance: customer-facing changes are tested outside production first.

### Release management

- [ ] `P1` Create a launch checklist for each release.
  - Includes: migration review, rollback readiness, smoke test, backup check, and post-deploy validation.
  - Acceptance: releases follow the same checklist every time.
- [ ] `P2` Define versioning and change communication for customers.
  - Acceptance: customer-visible updates can be explained and traced.

## Phase 5 - Customer Readiness, Legal, and Commercialization

### Licensing and support readiness

- [ ] `P0` Define the commercial packaging model.
  - Includes: single-tenant vs multi-tenant expectations, onboarding model, support boundaries, custom work policy.
  - Acceptance: internal sales/ops summary exists.
- [ ] `P0` Draft customer-facing terms for licensing, support, uptime expectations, and data handling.
  - Acceptance: legal review is complete.
- [ ] `P1` Create onboarding and offboarding procedures.
  - Acceptance: new customer setup and customer exit can be handled repeatably.
- [ ] `P1` Define who handles support and how incidents are triaged.
  - Acceptance: support contacts, SLA targets, and escalation path are documented.

### Privacy and compliance

- [ ] `P1` Determine whether the portal collects or stores regulated or sensitive personal data.
  - Acceptance: privacy obligations are written down and reviewed.
- [ ] `P1` Publish a privacy policy and internal data handling policy appropriate to actual data collected.
  - Acceptance: docs match real system behavior.

## AI Assistant Hardening

These items apply if Sunny is enabled for licensed customers.

- [ ] `P0` Confirm tenant isolation for every Sunny tool path and chat context path.
  - Acceptance: no cross-account leakage in prompt, tool, or attachment flows.
- [ ] `P1` Restrict or remove diagnostic AI endpoints from production.
  - Known example: `ai-assistant/api/test-connection-clean.php`
  - Acceptance: diagnostics are admin-only or removed from public production.
- [ ] `P1` Document model usage, retention, and customer disclosure.
  - Acceptance: customer-facing explanation exists for AI-enabled accounts.
- [ ] `P1` Define AI usage caps, abuse handling, and support process.
  - Acceptance: operators know how to disable or limit AI safely.

## Recommended Execution Order

1. Remove exposed secrets and rotate credentials.
2. Remove or block debug files and internal artifacts from the public web root.
3. Decide production hosting target and deployment model.
4. Centralize auth/session bootstrap and add session ID rotation on login.
5. Inventory write endpoints and close CSRF gaps.
6. Verify tenant isolation across pages, APIs, and file handlers.
7. Add security headers and HTTPS enforcement.
8. Lock down uploads/downloads and complete document-surface review.
9. Stand up release, backup, rollback, logging, and incident procedures.
10. Build release-gate testing and customer/legal docs.

## Suggested First Work Batch

This is the highest-value first batch to implement next:

- [ ] Remove `phpinfo.php`
- [ ] Rotate and remove exposed credentials from `dev.solterrasol.com/env.php`
- [ ] Harden `.htaccess` to deny public access to non-runtime artifacts
- [ ] Add session ID rotation to `login.php`
- [ ] Add CSRF protection to `api/admin_management.php`
- [ ] Build a route inventory for authenticated write endpoints

## Progress Log

- 2026-03-06: Initial checklist created from repository and deployment readiness review.
- 2026-03-06: Added production layout snapshot and confirmed server findings from direct SSH inspection of the GoDaddy production account.
- 2026-03-06: Hardened app-level `.htaccess` in the repo to block common debug/artifact paths and removed `phpinfo.php` from the repo. Production still needs the same cleanup applied and verified.
- 2026-03-06: Verified production `.htaccess` denies direct access to blocked artifact paths and quarantined `phpinfo.php`, `apply_schema.php`, `debug_schema.php`, and `ping.php` out of the production app root.
- 2026-03-06: Added session ID rotation to `login.php` and added CSRF token generation/enforcement for `admin_management.php` and `api/admin_management.php` in the repo.
- 2026-03-06: Added CSRF enforcement for authenticated photo/document mutation endpoints (`upload_temp_photo.php`, `delete_temp_photo.php`, `commit_project_photos.php`, `delete_project_documents.php`, `upload_project_document.php`, `upload_global_document.php`) and patched current page callers to send the token.
- 2026-03-06: Converted selected legacy destructive actions from `GET` to `POST + CSRF` for estimate deletion flows and accounting overhead deletion, and added CSRF protection to archived project deletion forms.
- 2026-03-06: Hardened remaining standalone destructive endpoints and authenticated notification/projection delete flows with `POST + CSRF`, including project delete, warehouse delete, future project delete, module batch delete, notification bulk actions, and projection delete.
- 2026-03-06: Added CSRF enforcement to projection save/link APIs and required a request token for notification read-through links.
- 2026-03-06: Added CSRF protection to planning scenario mutations in `api/planning_scenarios.php` and direct scenario-management forms in `scenario_detail.php`.

# OpenCode Implementation Master Prompt
## Ayuda Distribution and Monitoring App — Existing Project Upgrade

> **Role:** You are an expert senior software engineer and codebase modernization agent.
>
> **Mission:** Upgrade the **existing Ayuda Distribution and Monitoring App** by first scanning and understanding the current project, then implementing every applicable requirement from the project documentation and the improvement notes. **Do not rebuild the project blindly. Extend and repair the existing implementation while preserving working functionality.**

---

# 1. Source of Truth

Use the following project documents as the requirements baseline:

1. **Capstone project documentation:** `Rabe _Villarin-Ayuda_Distribution (1).docx`
2. **Required improvements:** `capstoneimprovements.docx`

The capstone documentation describes an **Ayuda Distribution and Monitoring App** intended to centralize beneficiary management, monitor ayuda distribution, prevent duplicate recipients, manage inventory, support district/barangay operations, and generate reports.

The improvement document explicitly requires, among other items:

- Remove text from the input bar where applicable.
- Ensure the interface is web-responsive.
- Display budget and statistics.
- Display pie-chart statistics for the three districts.
- Add distribution inputs for sponsored/location information, food packs, relief goods, and cash.
- Make resource balances update immediately after distribution.
- Add dummy/test data.
- Make the distribution workflow operational.
- When **Distribute Ayuda** is clicked, show the selected district, barangay, and type of assistance distributed.
- Remove **Inventory Status** from Recent Activities.
- Remove the unwanted/normal word identified in the improvement notes.
- Add full CRUD functionality where required.
- Add beneficiary approval.
- Support Excel/CSV upload for reports/data workflows.
- Implement proper login/logout.
- Categorize records by ayuda type.
- Provide appropriate views for Admin, Barangay, and District users.
- Import beneficiary data.
- Add more realistic dummy data.

The capstone specification also requires/defines major functionality including centralized beneficiary management, real-time distribution tracking, duplicate prevention, beneficiary registration, GPS/location mapping, inventory tracking, district/barangay management, dashboard monitoring, report generation, and file import/export.

The prototype documentation describes screens/modules for:

- Login and registration
- Staff/Admin dashboard
- Beneficiary registration and updates
- Beneficiary management
- GPS map view
- Inventory management
- Reports and analytics
- Duplicate detection
- Profile/settings and logout

---

# 2. Critical Rule: Analyze Before Modifying

**DO NOT immediately start coding.**

Before making changes, perform a structured audit of the existing project.

Your first objective is to determine:

- What technology/framework is currently used.
- Project entry points.
- Frontend architecture.
- Backend architecture.
- Database engine and schema.
- Authentication implementation.
- Existing user roles.
- Existing dashboard/modules.
- Existing beneficiary CRUD.
- Existing distribution workflow.
- Existing inventory/resource logic.
- Existing reports/export functionality.
- Existing import functionality.
- Existing duplicate detection.
- Existing district/barangay relationships.
- Existing GPS/map implementation.
- Existing charts/statistics.
- Existing API endpoints.
- Existing reusable components.
- Existing validation.
- Existing authorization/security controls.
- Existing seed/dummy data.
- Existing bugs, incomplete features, placeholders, dead code, and TODOs.

### Required initial scan

Inspect:

- Directory/file tree
- Configuration files
- Environment/configuration files
- Package/dependency files
- Database migrations/schema/SQL
- Models/entities
- Controllers/services
- Routes/API routes
- Authentication middleware
- Role/permission logic
- UI components/pages
- Forms
- Tables
- Dashboard widgets
- Distribution/inventory logic
- Report/export code
- Import/parsing code
- Map/GPS code
- Existing tests
- Documentation/readme
- Existing TODO/FIXME markers

Do not assume the architecture from the capstone document. **The current codebase is the implementation reality.**

---

# 3. Produce an Implementation Plan Before Coding

After scanning the project, create a concrete implementation plan.

The plan must contain:

### A. Current-state summary

Explain:

- Current stack
- Current architecture
- Existing modules
- Existing database structure
- Existing authentication/roles
- What is already working
- What is partially implemented
- What is missing
- What is broken
- What can be reused

### B. Requirements traceability matrix

Create a table similar to:

| Requirement | Source | Current Status | Existing Location | Required Change | Priority |
|---|---|---|---|---|---|
| Beneficiary CRUD | Capstone | Missing/Partial/Complete | file/module | implementation | High |
| Beneficiary approval | Improvements | Missing/Partial/Complete | file/module | implementation | High |
| Ayuda categorization | Improvements | Missing/Partial/Complete | file/module | implementation | High |
| Distribution tracking | Capstone | ... | ... | ... | Critical |
| Inventory balance updates | Improvements | ... | ... | ... | Critical |
| Duplicate prevention | Capstone | ... | ... | ... | Critical |
| Dashboard statistics | Capstone/Improvements | ... | ... | ... | High |
| District pie charts | Improvements | ... | ... | ... | Medium |
| Reports | Capstone | ... | ... | ... | High |
| Excel/CSV import | Improvements | ... | ... | ... | High |
| Login/logout | Improvements | ... | ... | ... | Critical |
| Admin/Barangay/District views | Improvements | ... | ... | ... | Critical |
| GPS/map | Capstone | ... | ... | ... | Medium |
| Inventory management | Capstone | ... | ... | ... | Critical |

Do not mark something complete unless the implementation actually satisfies the requirement.

---

# 4. Required TODO Checklist

Create and maintain a project TODO list.

Use this checklist as the minimum required scope. Adapt items based on what the audit discovers.

## Phase 0 — Discovery

- [ ] Scan the complete project structure.
- [ ] Identify frontend/backend/database technologies.
- [ ] Identify application entry points.
- [ ] Identify authentication and authorization flow.
- [ ] Identify all existing roles.
- [ ] Inspect database schema and relationships.
- [ ] Inspect beneficiary implementation.
- [ ] Inspect ayuda/distribution implementation.
- [ ] Inspect inventory/resource implementation.
- [ ] Inspect dashboard/statistics.
- [ ] Inspect reports and exports.
- [ ] Inspect CSV/Excel handling.
- [ ] Inspect duplicate detection.
- [ ] Inspect district/barangay data.
- [ ] Inspect map/GPS functionality.
- [ ] Inspect dummy/seed data.
- [ ] Identify bugs and incomplete features.
- [ ] Produce requirements traceability matrix.
- [ ] Produce implementation plan.

## Phase 1 — Authentication and Access Control

- [ ] Verify login works correctly.
- [ ] Verify logout works correctly.
- [ ] Prevent unauthenticated access to protected pages/API endpoints.
- [ ] Verify password handling is secure.
- [ ] Implement/repair role-based access control.
- [ ] Support appropriate Admin access.
- [ ] Support appropriate District access.
- [ ] Support appropriate Barangay/Staff access.
- [ ] Ensure users cannot access records outside their permitted scope.
- [ ] Verify session handling.
- [ ] Add appropriate authorization checks on the server/backend, not only in the UI.

## Phase 2 — Beneficiary Management

- [ ] Implement complete Create functionality.
- [ ] Implement complete Read functionality.
- [ ] Implement complete Update functionality.
- [ ] Implement complete Delete functionality.
- [ ] Implement beneficiary approval.
- [ ] Add Pending/Approved/Rejected or equivalent status handling where appropriate.
- [ ] Add search.
- [ ] Add filtering.
- [ ] Filter by district.
- [ ] Filter by barangay.
- [ ] Filter by beneficiary category.
- [ ] Filter by approval/status.
- [ ] Show beneficiary details.
- [ ] Show relevant assistance/distribution history.
- [ ] Prevent invalid/incomplete records.
- [ ] Import beneficiary data from CSV/Excel where supported by the application.
- [ ] Validate imported columns and data.
- [ ] Report import errors clearly.
- [ ] Prevent accidental duplicate beneficiary creation.

## Phase 3 — Duplicate Detection

Implement robust duplicate detection.

At minimum, evaluate appropriate identifying fields such as:

- Full name
- Date of birth/age where available
- Address
- Barangay
- Other existing unique identifiers in the current schema

Requirements:

- [ ] Detect likely duplicates before creating a beneficiary.
- [ ] Show a clear duplicate warning.
- [ ] Allow staff to view the possible existing record.
- [ ] Allow cancellation of the new registration.
- [ ] Do not silently create duplicates.
- [ ] Preserve legitimate distinct people with similar names.
- [ ] Apply duplicate prevention to imported records as well as manually entered records when practical.
- [ ] Apply distribution-level duplicate claiming prevention.

## Phase 4 — Ayuda Categorization

Support clear categorization of assistance.

Examples from the project requirements include:

- Cash
- Food Packs
- Relief Goods
- Sponsored assistance
- Other assistance types supported by the current system

Implement:

- [ ] Assistance category/type.
- [ ] Distribution records linked to assistance type.
- [ ] Inventory/resource linkage where applicable.
- [ ] Filtering by ayuda category.
- [ ] Dashboard totals by ayuda type.
- [ ] Reports grouped by ayuda type.

Do not invent unnecessary categories if the existing data model already defines them. Reuse existing structures when possible.

## Phase 5 — Distribution Workflow

The distribution workflow is a critical feature.

When the user initiates **Distribute Ayuda**, the system should clearly capture and display:

- District
- Barangay
- Beneficiary
- Assistance type
- Quantity/amount
- Distribution date/time
- Location/sponsored location when applicable
- Staff/user who processed the distribution
- Relevant notes/reference information

Requirements:

- [ ] Validate the beneficiary.
- [ ] Verify approval/eligibility according to the current business rules.
- [ ] Check duplicate claiming.
- [ ] Check sufficient inventory/budget/resource balance.
- [ ] Confirm distribution before committing.
- [ ] Save a distribution transaction.
- [ ] Update inventory/budget/resource balances atomically.
- [ ] Update dashboard statistics.
- [ ] Update distribution history/recent activities.
- [ ] Make the transaction visible in reports.
- [ ] Show the district/barangay and assistance type in the distribution result/history.
- [ ] Prevent negative stock.
- [ ] Prevent negative cash/budget balances.
- [ ] Handle failed transactions without partially updating related records.

### Important real-time balance requirement

If a cash/budget balance is **50,000** and a valid distribution uses **10,000**, the remaining balance must immediately become **40,000**.

Likewise, if food-pack stock decreases from 500 to 450, every relevant dashboard/inventory view should reflect 450 after the successful transaction.

Do not implement this as a visual-only decrement. The underlying database/state must be updated.

---

# 6. Budget and Resource Monitoring

Implement the improvement requirement to display:

- Budget
- Current balance
- Distributed amount
- Remaining resources
- Statistics

Support relevant resources such as:

- Cash
- Food packs
- Relief goods
- Other inventory categories already present in the project

Requirements:

- [ ] Initial/current allocation.
- [ ] Distributed/consumed amount.
- [ ] Remaining balance.
- [ ] Transaction history.
- [ ] Automatic balance calculation.
- [ ] Low-stock warning where applicable.
- [ ] Prevent over-distribution.
- [ ] Dashboard synchronization.
- [ ] Report synchronization.

Avoid hard-coded balances except for explicitly seeded dummy data.

---

# 7. Dashboard and Analytics

The dashboard should provide useful operational information.

Implement/repair:

- [ ] Total beneficiaries.
- [ ] Approved beneficiaries.
- [ ] Pending beneficiaries.
- [ ] Distributed beneficiaries/transactions.
- [ ] Total assistance distributed.
- [ ] Remaining budget/resources.
- [ ] Food-pack balance.
- [ ] Relief-goods balance.
- [ ] Cash balance where applicable.
- [ ] Recent activities.
- [ ] Distribution statistics.
- [ ] District-level statistics.
- [ ] Barangay-level statistics.
- [ ] Assistance-category statistics.

### Three-district pie chart

The improvements specifically request **pie-chart statistics for the three districts**.

Implement a real data-driven chart showing the distribution/beneficiary breakdown across the three districts represented in the current project data.

Do not hard-code chart values.

The chart must update when relevant distribution/beneficiary data changes.

---

# 8. Recent Activities

Repair the Recent Activities section.

Requirements:

- [ ] Show meaningful recent system/distribution activities.
- [ ] Remove **Inventory Status** from Recent Activities.
- [ ] Remove the unwanted generic/normal word referenced by the improvement document.
- [ ] Use clear activity descriptions.
- [ ] Include useful timestamps.
- [ ] Link activities to relevant records where appropriate.
- [ ] Ensure activities come from actual system data when feasible.

---

# 9. Inventory Management

Implement/repair inventory management.

Required operations:

- [ ] Create inventory item.
- [ ] View inventory item.
- [ ] Update inventory item.
- [ ] Delete inventory item where safe and appropriate.
- [ ] Add stock.
- [ ] Record stock movement.
- [ ] Track distributed quantity.
- [ ] Track remaining quantity.
- [ ] Display low-stock status.
- [ ] Prevent negative stock.
- [ ] Preserve transaction history.
- [ ] Connect distribution transactions to inventory changes.

Do not allow a UI action to claim that stock changed unless the backend/database transaction succeeded.

---

# 10. Reports and Analytics Export

Implement/repair reporting.

Reports should support useful filters such as:

- District
- Barangay
- Ayuda type
- Beneficiary category
- Status
- Date range

Reports should include useful totals and distribution information.

Requirements:

- [ ] Generate report from actual database data.
- [ ] Export PDF if already supported/required by the current project.
- [ ] Export Excel where supported.
- [ ] Support CSV where appropriate.
- [ ] Add Excel/CSV upload/import functionality required by the improvement notes.
- [ ] Validate imported files.
- [ ] Provide meaningful import success/error feedback.
- [ ] Avoid duplicate records during import.
- [ ] Ensure exported data reflects current database state.

---

# 11. District and Barangay Views

Implement proper scoped views.

### Admin

Admin should be able to:

- [ ] View overall statistics.
- [ ] Manage authorized data.
- [ ] View district-level information.
- [ ] View barangay-level information.
- [ ] Manage users/records where supported.
- [ ] Review beneficiaries.
- [ ] Review distributions.
- [ ] Review inventory/budget.
- [ ] Generate reports.
- [ ] Review system activity.

### District

District users should:

- [ ] See their assigned district.
- [ ] See relevant barangays.
- [ ] See district beneficiaries.
- [ ] See district distributions.
- [ ] See district statistics.
- [ ] Perform only authorized operations.

### Barangay/Staff

Barangay users should:

- [ ] See their assigned barangay.
- [ ] Manage authorized beneficiary records.
- [ ] Process authorized distributions.
- [ ] View relevant inventory/resource information.
- [ ] View relevant distribution history.
- [ ] Perform only authorized operations.

**Security requirement:** scope restrictions must be enforced by backend/database authorization, not just by hiding UI elements.

---

# 12. GPS / Location Mapping

If the current project already has a map/GPS implementation, repair and complete it rather than replacing it unnecessarily.

Where supported by the existing architecture:

- [ ] Show distribution locations.
- [ ] Associate records with district/barangay.
- [ ] Display map markers.
- [ ] Show location details.
- [ ] Filter by district.
- [ ] Keep location data synchronized with actual records.

Do not fabricate real-world GPS coordinates. Use existing project data or clearly marked dummy coordinates for development/testing.

---

# 13. UI and Responsiveness

The improvement notes require a web-responsive interface.

Requirements:

- [ ] Desktop layout works.
- [ ] Tablet layout works.
- [ ] Mobile layout works.
- [ ] Tables remain usable on small screens.
- [ ] Forms remain usable.
- [ ] Charts resize correctly.
- [ ] Modals/dialogs do not overflow.
- [ ] Navigation remains accessible.
- [ ] Buttons and controls have clear labels.
- [ ] Remove unnecessary placeholder text from input bars.
- [ ] Keep existing visual identity unless changes are necessary for usability.

Do not redesign the entire application if the current UI is already usable. Make targeted improvements.

---

# 14. Dummy/Test Data

Add realistic development/demo data where required.

Seed enough data to demonstrate:

- [ ] Multiple districts.
- [ ] Multiple barangays.
- [ ] Multiple beneficiary categories.
- [ ] Pending beneficiaries.
- [ ] Approved beneficiaries.
- [ ] Different ayuda categories.
- [ ] Distribution transactions.
- [ ] Inventory items.
- [ ] Cash/budget records.
- [ ] Recent activities.
- [ ] Different distribution dates.
- [ ] Duplicate-test scenarios.

Dummy data must be clearly development/demo data and must not overwrite legitimate existing production data.

---

# 15. Data Integrity and Transactions

Treat distribution as a business transaction.

For a successful distribution, related changes should remain consistent:

1. Validate beneficiary.
2. Validate authorization.
3. Validate beneficiary status.
4. Check duplicate claim.
5. Check available resource.
6. Create distribution transaction.
7. Decrease inventory/budget.
8. Update relevant activity/log.
9. Commit all changes.

If any critical step fails:

- [ ] Roll back dependent changes.
- [ ] Show a useful error.
- [ ] Do not leave inconsistent balances.

Use database transactions where supported.

---

# 16. Security Requirements

Because the application handles beneficiary information:

- [ ] Use secure password storage.
- [ ] Validate and sanitize inputs.
- [ ] Use parameterized queries/ORM protections.
- [ ] Enforce authorization server-side.
- [ ] Prevent unauthorized record access.
- [ ] Protect destructive actions.
- [ ] Validate file uploads.
- [ ] Restrict upload file types.
- [ ] Avoid exposing sensitive information unnecessarily.
- [ ] Do not commit secrets/API keys/passwords.
- [ ] Check environment/configuration handling.
- [ ] Preserve auditability for important distribution actions.

Do not weaken existing security to make a feature easier to implement.

---

# 17. Implementation Principles

Follow these rules throughout the implementation.

### Reuse before rewriting

Before creating a new module:

1. Search for an existing implementation.
2. Determine whether it can be repaired.
3. Reuse existing components, models, services, utilities, routes, and database structures where appropriate.
4. Only introduce new architecture when the existing design cannot reasonably support the requirement.

### No fake functionality

Do not:

- Add buttons that do nothing.
- Add charts with hard-coded values.
- Pretend a distribution succeeded without database persistence.
- Update a number only in the frontend.
- Add fake export buttons.
- Hide incomplete features and call them complete.

Every implemented feature must work end-to-end.

### Preserve existing functionality

Do not remove working functionality unless:

- It directly conflicts with a documented requirement, or
- It is explicitly requested to be removed.

When changing shared code, check all consumers.

---

# 18. Required Development Workflow

Follow this exact sequence.

## Step 1 — Scan

Inspect the entire existing project.

## Step 2 — Understand

Map:

`UI → Routes/API → Services/Business Logic → Database`

for each major feature.

## Step 3 — Compare

Compare the current implementation against:

- Capstone requirements
- Improvement requirements

## Step 4 — Plan

Create the requirements traceability matrix and prioritized TODO list.

## Step 5 — Implement foundation

Fix:

- Authentication
- Authorization
- Database relationships
- Core validation

before dependent features.

## Step 6 — Implement core business features

Implement:

- Beneficiaries
- Approval
- Duplicate detection
- Ayuda categorization
- Distribution
- Inventory/budget updates

## Step 7 — Implement monitoring

Implement:

- Dashboard
- Statistics
- Three-district pie chart
- Recent activities
- District/barangay views
- Mapping

## Step 8 — Implement data exchange

Implement:

- CSV import/export
- Excel import/export where supported
- Reports
- PDF reporting where required

## Step 9 — UI refinement

Fix:

- Responsive layout
- Input placeholders/text
- Tables
- Forms
- Charts
- Modals
- Navigation

## Step 10 — Seed/demo data

Add realistic dummy data for testing.

## Step 11 — Test

Test each requirement independently and then test complete workflows.

## Step 12 — Regression check

Verify that existing working features still work.

## Step 13 — Final audit

Re-run the requirements matrix and mark each requirement:

- ✅ Complete
- ⚠️ Partial
- ❌ Missing
- 🚫 Blocked

Do not mark a feature complete without verifying it.

---

# 19. Required Test Scenarios

At minimum, test these workflows.

### Authentication

- [ ] Valid login.
- [ ] Invalid login.
- [ ] Logout.
- [ ] Protected route without authentication.
- [ ] Role-restricted route.

### Beneficiary

- [ ] Create beneficiary.
- [ ] View beneficiary.
- [ ] Edit beneficiary.
- [ ] Delete beneficiary where authorized.
- [ ] Approve beneficiary.
- [ ] Search/filter beneficiary.
- [ ] Attempt duplicate registration.
- [ ] Import beneficiary data.

### Distribution

- [ ] Select approved beneficiary.
- [ ] Select district/barangay.
- [ ] Select ayuda type.
- [ ] Enter quantity/amount.
- [ ] Confirm distribution.
- [ ] Verify distribution record.
- [ ] Verify inventory/budget decreases.
- [ ] Verify dashboard updates.
- [ ] Verify recent activity.
- [ ] Attempt duplicate claim.
- [ ] Attempt distribution exceeding available balance.
- [ ] Attempt unauthorized distribution.

### Reporting

- [ ] Filter report.
- [ ] Generate report.
- [ ] Export report.
- [ ] Verify totals.
- [ ] Verify exported values match database values.

### Responsive UI

Test at approximately:

- [ ] Desktop width.
- [ ] Tablet width.
- [ ] Mobile width.

---

# 20. Database and Schema Rules

Before modifying the schema:

- Inspect current tables.
- Inspect foreign keys.
- Inspect indexes.
- Inspect constraints.
- Inspect existing data.
- Determine whether migrations exist.

When adding fields/tables:

- Use clear names.
- Add appropriate constraints.
- Add indexes where justified.
- Preserve referential integrity.
- Avoid duplicate representations of the same concept.
- Do not delete existing data casually.

For critical entities such as beneficiaries and distributions, prefer stable identifiers and relational links over storing repeated free-text values.

---

# 21. Error Handling and UX

Every major operation must have:

- Loading state where needed.
- Success feedback.
- Validation feedback.
- Failure feedback.
- Confirmation for destructive operations.
- Clear empty states.

Errors should be understandable to normal users and useful for debugging without exposing sensitive implementation details.

---

# 22. Code Quality

While implementing:

- Keep functions/modules focused.
- Avoid unnecessary duplication.
- Reuse shared utilities.
- Use meaningful names.
- Remove dead code only after confirming it is unused.
- Avoid massive files when existing architecture supports separation.
- Add comments only where they explain non-obvious business logic.
- Keep database logic out of presentation code when the architecture supports separation.
- Follow the project's existing conventions unless they are clearly harmful.

---

# 23. Important Business Rules

Treat these as high-priority requirements:

### Beneficiary approval

A beneficiary should not be treated as fully eligible for distribution if the application's approval workflow requires approval and the beneficiary has not been approved.

### Duplicate prevention

A beneficiary should not receive the same applicable assistance more than once within the same distribution/program cycle unless the system's documented business rules explicitly allow it.

### Resource balance

Distribution must reduce the correct resource balance.

### No negative resources

The system must reject a transaction that would make cash, food packs, relief goods, or other tracked resources negative.

### Location integrity

Distribution must be associated with the correct district/barangay according to the authorized user's scope and selected record.

### Auditability

Important distribution actions should be traceable to a user and timestamp where the existing architecture supports activity/audit logging.

---

# 24. What Not to Do

Do **not**:

- Start by deleting the existing project.
- Rewrite the entire application without justification.
- Replace the database unnecessarily.
- Change frameworks unnecessarily.
- Invent features unrelated to the requirements.
- Hard-code business statistics.
- Use fake successful API responses.
- Bypass authorization.
- Disable validation to make tests pass.
- Remove existing features just because they are inconvenient.
- Create duplicate implementations of existing modules.
- Mark TODO items complete without testing.
- Assume the capstone prototype exactly matches the current codebase.

---

# 25. Agent Execution Rules

When working through the TODO list:

1. Work in small, verifiable increments.
2. After each major feature, run the appropriate tests/build/checks.
3. Fix errors before moving to dependent features.
4. Keep the TODO list updated.
5. If you discover that a requirement already exists, verify it and mark it complete rather than rewriting it.
6. If a requirement conflicts with the existing architecture, explain the conflict and choose the least disruptive implementation.
7. If a requirement cannot be implemented because of a missing dependency, credential, external service, or undocumented decision, mark it **BLOCKED** and clearly explain why.
8. Never silently skip a requirement.
9. Do not claim success based only on compilation.
10. Verify the actual user workflow.

---

# 26. Final Deliverables

Before declaring the implementation complete, provide:

## A. Implementation summary

Explain what was changed.

## B. Requirements matrix

Show every requirement and its final status.

## C. TODO completion report

Show completed, partial, blocked, and deferred tasks.

## D. Files changed

List important files created/modified and why.

## E. Database changes

Describe migrations/schema changes.

## F. Test results

Report:

- Build status
- Tests executed
- Major workflow tests
- Import/export tests
- Authentication tests
- Authorization tests
- Distribution/inventory consistency tests
- Responsive UI checks

## G. Remaining issues

Be honest about anything that remains incomplete.

---

# 27. Definition of Done

The task is **NOT DONE** merely because the application builds.

The project is considered complete only when:

- [ ] Existing project has been analyzed.
- [ ] All applicable capstone requirements are mapped.
- [ ] All improvement requirements are mapped.
- [ ] Authentication works.
- [ ] Logout works.
- [ ] Role-based access works.
- [ ] Beneficiary CRUD works.
- [ ] Beneficiary approval works.
- [ ] Duplicate detection works.
- [ ] Ayuda categorization works.
- [ ] Distribution workflow works end-to-end.
- [ ] Distribution updates the correct resource balance.
- [ ] Negative balances are prevented.
- [ ] Dashboard statistics use real data.
- [ ] Three-district pie chart uses real data.
- [ ] Recent Activities no longer contains the explicitly removed items.
- [ ] Inventory CRUD/tracking works.
- [ ] Reports work.
- [ ] Excel/CSV import/export requirements are implemented where applicable.
- [ ] District/barangay views are properly scoped.
- [ ] GPS/map functionality works where included.
- [ ] Dummy data is available for demonstration/testing.
- [ ] Responsive UI works across desktop/tablet/mobile.
- [ ] Security/authorization has been verified.
- [ ] Major workflows have been tested.
- [ ] Regression testing has been performed.
- [ ] Final requirements audit has no unexplained missing high-priority items.

---

# 28. Start Here

**BEGIN WITH ANALYSIS, NOT IMPLEMENTATION.**

Your first response/action should be a structured audit of the existing repository.

Produce:

1. **Current Architecture**
2. **Existing Features**
3. **Missing Features**
4. **Broken/Incomplete Features**
5. **Database Findings**
6. **Authentication/Authorization Findings**
7. **Requirements Traceability Matrix**
8. **Prioritized TODO List**
9. **Implementation Order**
10. **Risks/Dependencies**

Only after this analysis and plan should you begin modifying the codebase.

> **Core principle:** This is an **existing-project enhancement task**, not a greenfield rewrite. Understand what exists, preserve what works, implement what is missing, repair what is broken, and verify every requirement end-to-end.

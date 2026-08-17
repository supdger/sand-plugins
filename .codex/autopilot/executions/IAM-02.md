# IAM-02 execution record

- Status: completed, 2026-08-13.
- Changed paths: `sand-iam/install.sql`, `sand-iam/uninstall.sql`, `sand-iam/update.sql`, and P0 lifecycle wording in the contract.
- Verification host: isolated PostgreSQL database `saiadmin_iam_acceptance_20260813`; `saiadmin` demonstration database was inspected and remained at zero `sand_iam_*` tables.
- Evidence: root install completed; aggregate schema inspection returned `tables=17`, `constraints=203`, `indexes=50`, `non_sand_tables=0`; root update completed with `tables=17`; root uninstall completed with `tables=0`.
- Lifecycle evidence: SaiPackage `InstallLogic` imports only the package-root `install.sql`, `update.sql` and `uninstall.sql`; it does not invoke the plugin-directory lifecycle hook. The actual SaiAdmin-PG database had zero existing `sand_iam_*` tables, so this `0.1.0` package is the first P0 schema-bearing release rather than an upgrade of an installed historical skeleton. The root update path was nevertheless executed successfully after install.
- Rollback: the isolated database is left empty after uninstall. No SandIAM object was installed into the `saiadmin` demonstration database.

# IAM-01 execution record

- Status: completed 2026-08-13.
- Changed paths: `sand-iam/docs/development/sand-iam-p0-contract.md`, development entry, README, and task board.
- Evidence: the contract defines P0 ownership, all `sand_iam_*` records with primary/foreign/unique/index constraints, admin DTO and permission contract, stable errors, the SandAI Adapter, migration boundaries, Cursor handoff, and acceptance responsibilities.
- Verification: searched the contract for every development-entry gate, all P0 table names, adapter interfaces, stable errors, and host middleware requirements.
- Handoff: IAM-02 owns schema and lifecycle implementation; Cursor U-02 may consume only contract v0.1 DTOs; SandAI SAND-113C may implement the documented fail-closed Adapter.
- Rollback: documentation-only change; no database, host, credential, service, or deployment state changed.

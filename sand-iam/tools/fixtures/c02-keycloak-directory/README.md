# C02 controlled Keycloak directory

This default-off fixture supplies the real HTTPS directory side of the C02
acceptance chain. It implements the Keycloak Admin REST users endpoint consumed
by `KeycloakDirectorySyncDriver`, checks the exact realm and bearer token, keeps
pagination evidence, supports a controlled second-generation source state, and
removes its prefix-scoped state on cleanup.

Run it behind a dedicated public HTTPS origin accepted by SandIAM's SSRF policy.
Set:

- `SAND_IAM_C02_DIRECTORY_ENABLED=1`
- `SAND_IAM_C02_DIRECTORY_STATE_DIR` to an empty temporary directory
- `SAND_IAM_C02_DIRECTORY_CONTROL_TOKEN` to a random value of at least 32 bytes

Control requests use `X-C02-Control-Token`. The directory access token is stored
only in a mode `0600` state file and is never returned by proof endpoints.

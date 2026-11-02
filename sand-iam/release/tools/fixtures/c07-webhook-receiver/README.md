# C07 controlled Webhook receiver

This receiver is a default-off acceptance fixture for the `event-webhook-delivery`
chain. It verifies SandIAM's `credential.changed` HMAC signature, deliberately
returns HTTP 500 for the first valid delivery, returns HTTP 204 for the retry,
and exposes a minimal proof record.

Run it only behind a dedicated public HTTPS origin accepted by SandIAM's SSRF
policy. Set:

- `SAND_IAM_C07_RECEIVER_ENABLED=1`
- `SAND_IAM_C07_RECEIVER_STATE_DIR` to an empty temporary directory
- `SAND_IAM_C07_RECEIVER_CONTROL_TOKEN` to a random value of at least 32 bytes

The control token and Webhook secret are never returned by proof endpoints. The
live plan configures and removes one exact prefix-scoped record.

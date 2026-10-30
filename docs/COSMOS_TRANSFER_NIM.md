# NVIDIA Cosmos Transfer2.5 controlled-video integration

Official NVIDIA Cosmos NIM reference: https://docs.nvidia.com/nim/cosmos/latest/api-reference.html

The `nvidia/cosmos-transfer2.5-2b` self-hosted NIM accepts POST `/v1/infer`, a prompt, a base64 encoded MP4 `video`, and **at least one** `edge`, `depth`, `vis` or `seg` control field with `control_weight` and base64 MP4 `control`. It returns `b64_video`.

Install and configure your own GPU-backed NIM. Set server-only `COSMOS_TRANSFER_NIM_URL` (HTTPS, or loopback for trusted same-host deployment) and `COSMOS_TRANSFER_NIM_TOKEN` (32+ characters, enforced by a reverse proxy/worker). The NIM must support protected bearer access; do not expose this NIM directly to distributed clients. This API is **not NVIDIA's free hosted inference endpoint**.

Send a multipart request with Developer API Key Bearer auth to `POST /api/v1/openmindai/transfer/generations`:
- `prompt`: text, 5–2000 chars
- `video`: MP4 input, up to 12 MiB
- `control_video`: MP4 input of the same scene, up to 12 MiB
- `control_type`: edge, depth, vis or seg
- optional `control_weight`: 0–1

The backend creates a queued request, safely sends only its input media to the configured private GPU worker, validates returned MP4 bytes, and makes progress available at `GET /api/v1/openmindai/transfer/{id}`. Only the submitting user can download the output via `GET /api/v1/openmindai/transfer/{id}/download`; old files are deleted after 24 hours. It also enforces one active job per user and application credit limits, with completed-only accounting.

Queue worker and Laravel scheduler must be enabled. Model NIM hardware, GPU memory, compatible conditioned videos, trial/enterprise entitlement, and real GPU execution have **not** been verified by mocked CI tests; never label it `Verified live` until tested end-to-end on your NIM.

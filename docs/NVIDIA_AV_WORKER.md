# SparseDrive and BEVFormer NIM worker integration

NVIDIA public documentation provides the inference API contracts:
- SparseDrive: https://docs.api.nvidia.com/nim/reference/nvidia-sparsedrive-infer, POST `/v1/sparsedrive/inference`
- BEVFormer: https://docs.api.nvidia.com/nim/reference/nvidia-bevformer-infer, POST `/v1/bevformer/process`

**Important:** The published sample base URL is `https://example.com`, NOT a verified NVIDIA managed inference server. This integration intentionally requires an **operator-managed, deployed NVIDIA AV NIM worker**. A valid OpenMindAI Developer API Key does not provide NIM runtime hardware or provider entitlement.

Configure the Laravel backend `NVIDIA_AV_NIM_URL=https://your-trusted-gpu-worker.example` and `NVIDIA_AV_NIM_TOKEN` with a random server-only 32+ character Bearer secret. The worker must support the fixed paths above, accept JSON `{"scene_id":"scene-0103"}`, validate that scene is available, and return a model-specific JSON prediction. Do not send these server-side credentials to the desktop application.

The Cloud API is:
`POST /api/v1/openmindai/av-nim/nvidia-sparsedrive/jobs` or `POST /api/v1/openmindai/av-nim/nvidia-bevformer/jobs`, authenticated using the user's own Developer API Key. Accept `scene-####` identifiers as per the provider's hosted example. This is a **scene-reference adapter**, not a general arbitrary camera/video upload service.
`GET /api/v1/openmindai/av-nim/jobs/{id}` polls progress; `GET /api/v1/openmindai/av-nim/jobs/{id}/download` downloads a private JSON result.

A database queue worker, Scheduler and private storage are required. A completed job charges one application credit. Worker downtime, incompatible datasets or provider refusal returns failed state without counting completed success. Responses are size-limited and redirects disabled.

Before calling this live ready, deploy a genuine NIM runtime compatible with both APIs, configure its identity/authentication and scene data, and run a real sample to verify the provider result. Mock CI only validates Laravel integration, not GPU inference. The admin catalog will show API configured, not Verified Live, until upstream validation is performed.

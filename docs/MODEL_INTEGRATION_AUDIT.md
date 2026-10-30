# Model integration audit — 2026-10-22

Executed sequentially from the Laravel production host using its existing NVIDIA configuration. The first pass used 30-second timeouts; ten failed chat probes were retried once with 120 seconds and a larger output budget. This is a small inference smoke test, not a quality, tool-use, vision or load benchmark.

17 valid responses, 10 failed or retired provider integrations, 10 missing worker configurations, and 5 specialized services awaiting valid task fixtures. All 42 were inspected; only 27 supported text/embedding/rerank endpoints received live inference requests.

| Model | Latest result | Evidence / blocker |
|---|---|---|
| `nvidia-3d-body-pose` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-active-speaker-detection` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-background-noise-removal` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-bevformer` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-cosmos-transfer2.5-2b` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-cosmos3-nano` | task_input_required | Requires a real specialized task; no live inference claimed |
| `nvidia-cosmos3-nano-reasoner` | invalid_request | 404; absent from live provider model registry |
| `nvidia-deepseek-v4.1-flash` | unreachable | 120-second retry timed out (cURL 28) |
| `nvidia-diffusiongemma-26b-a4b-it` | invalid_response | 200 but no usable visible answer |
| `nvidia-gemma-4-31b-it` | unreachable | 120-second retry timed out (cURL 28) |
| `nvidia-glm-5-3` | unreachable | 120-second retry timed out (cURL 28) |
| `nvidia-glm-5-3-flash` | unreachable | 120-second retry timed out (cURL 28) |
| `nvidia-gpt-oss-20b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-ising-calibration-1-35b-a3b` | retired | 410; replacement nvidia-ising-calibration-1.5-31b passed |
| `nvidia-ising-calibration-1.5-31b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-kumo-relational` | task_input_required | Requires a real specialized task; no live inference claimed |
| `nvidia-kumo-tabular` | task_input_required | Requires a real specialized task; no live inference claimed |
| `nvidia-laguna-xs-2.1` | success | 200; nonempty protocol-appropriate output |
| `nvidia-llama-3.1-nemotron-safety-guard-8b-v3` | success | 200; nonempty protocol-appropriate output |
| `nvidia-llama-3.2-11b-vision-instruct` | success | 200; nonempty protocol-appropriate output |
| `nvidia-llama-3.2-90b-vision-instruct` | success | 200; nonempty protocol-appropriate output |
| `nvidia-llama-guard-4-12b` | unreachable | 120-second retry timed out (cURL 28) |
| `nvidia-magpie-tts-zeroshot` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-muse-glimmer-30b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3-embed-1b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3-nano-omni-30b-a3b-reasoning` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3-super-120b-a12b` | invalid_request | 404; provider function not found for this account; registry still lists ID |
| `nvidia-nemotron-3.5-content-safety` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3.5-embed-1b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3.5-embed-8b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-3.5-rerank-8b` | success | 200; nonempty protocol-appropriate output |
| `nvidia-nemotron-voicechat` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-rfd4-proteina` | task_input_required | Requires a real specialized task; no live inference claimed |
| `nvidia-riva-translate-4b-instruct-v2` | success | 200; nonempty protocol-appropriate output |
| `nvidia-sparsedrive` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-step-5-preview` | success | 200; nonempty protocol-appropriate output |
| `nvidia-streampetr` | task_input_required | Requires a real specialized task; no live inference claimed |
| `nvidia-studio-voice` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `nvidia-synthetic-video-detector` | setup_required | Private worker/NIM URL and authentication absent; no inference attempted |
| `openmindai-lite` | success | 200; nonempty protocol-appropriate output |
| `openmindai-super` | unavailable | 500: failed to generate completions |
| `openmindai-ultra` | success | 200; nonempty protocol-appropriate output |

## Fixes

- Corrected GLM API identifiers to `z-ai/glm-5.3` and `z-ai/glm-5.3-flash`, preserving custom administrator overrides. Applied live and covered by a conditional migration. Corrected requests still timed out. References: https://build.nvidia.com/z-ai/glm-5-3/build and https://build.nvidia.com/z-ai/glm-5-3-flash/build.
- Missing private runtime configuration is no longer advertised as available. Models remain listed. Retired HTTP 410 integrations expose a replacement and no callable catalog path.
- Shared probes validate visible chat output, finite numeric embeddings, and rerank scores rather than treating any HTTP 200 as success. Diagnostics expose status and configuration names, never secrets or raw provider responses.
- Malformed embedding/rerank responses fail before credit accounting.
- Admin test results show the actual failure category. Added a repeatable sequential `models:audit` command.

## Repeating the audit

```sh
php artisan models:audit
php artisan models:audit --live --timeout=45
php artisan models:audit --live --model=openmindai-lite
```

The default performs no network requests or success writes. Live mode uses provider quota and stores supported probe results. Command success means the scan completed; read each JSON result for model status. Specialized media/data/protein inference requires its documented inputs and must not be represented as passed without real output.

## Remaining operator/provider work

Configure and deploy the private Maxine, AV NIM, Magpie, VoiceChat and Cosmos Transfer services before retesting those ten integrations. A URL alone is not a running GPU worker. NVIDIA must resolve account access/deployment or service failures for the failing hosted endpoints; do not invent replacement IDs or report them as fixed. Supply representative Kumo, protein, Cosmos and scene fixtures for specialized end-to-end tests.

Image generation is separate from this 42-model catalog: previous live FLUX calls timed out and Stable Diffusion 3 medium returned 404. The queue ran, but successful image delivery remains unverified.

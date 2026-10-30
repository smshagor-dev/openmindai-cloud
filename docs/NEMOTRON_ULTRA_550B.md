# Nemotron 3 Ultra 550B (A55B)

Provider model ID: `nvidia/nemotron-3-ultra-550b-a55b`
Provider endpoint: `https://integrate.api.nvidia.com/v1/chat/completions`
Public model slug: `nvidia-nemotron-3-ultra-550b-a55b`

## Register
Run migrations, then:
```bash
php artisan db:seed --class=NemotronUltra550BSeeder --force
```
The seeder intentionally leaves a new entry disabled and not API-ready. It is safe to rerun and preserves operator-set activation.

## Verify provider access (server-side)
```bash
curl -sS --fail-with-body https://integrate.api.nvidia.com/v1/chat/completions \
  -H "Authorization: Bearer $NVIDIA_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"model":"nvidia/nemotron-3-ultra-550b-a55b","messages":[{"role":"user","content":"Reply with OK"}],"max_tokens":128,"stream":false}'
```
Do not paste the API key into GitHub or client code. On successful inference, enable the entry using the existing administrator model management workflow and verify both `enabled` and `api_ready` are true. Test authenticated model listing, non-streaming completion, SSE streaming, tool calls, error paths, and research orchestration before production release.

## Important limitations
- NVIDIA's advertised 1M context is **not** currently supported end-to-end by the OpenMind Cloud chat gateway. The gateway caps messages at 40 and each content at 16,000 characters, with an 8,192 output-token cap.
- NVIDIA examples use `chat_template_kwargs.enable_thinking`; the current gateway does not forward that setting.
- Existing developer model listings intentionally expose only three coding agents; this research model appears in the full/mobile catalog after registration.
- Successful provider access, production readiness, and deployment are **not** established by this registration commit.
- Use actual retrieved sources and independently verified experiments for research manuscripts; do not fabricate references or results.

Reference: https://build.nvidia.com/nvidia/nemotron-3-ultra-550b-a55b

# Cloud image generation and editing

Image generation is queued on Laravel. Browser -> Next.js authenticated proxy -> Laravel
`POST /api/v1/media/cloud-image` -> `media` queue -> trusted provider -> private Laravel
storage. **No image-generation inference goes from Next.js directly to NVIDIA.**
The browser receives only task IDs and polls `GET /api/v1/media/{id}`.
Preview, download and deletion use the existing authenticated media endpoints.
Credentials never enter the browser.

## Setup

Set `NVIDIA_API_KEY` and `CLOUD_IMAGE_ENABLED=true` **on Laravel**. Deploy the
`2026_10_21_110000_add_cloud_image_options` migration with
`php artisan migrate --force`, rebuild config, and **run a persistent worker**
`php artisan queue:work media --queue=media --tries=1 --timeout=180`.
The process manager must restart the worker on failure and after deployments.
Never use `QUEUE_CONNECTION=sync` in production: it negates asynchronous
generation and can cause HTTP timeouts. Verify the worker's database jobs table.

Next.js needs `LARAVEL_API_URL` and the existing authenticated session proxy.
No frontend `NVIDIA_API_KEY` is required for image creation. Chat inference may
still require its own separately configured server key.

## Features and model limits

- `mode=generate`, `count=1..4` makes **separate** queued jobs and separately
  tracks daily quotas/credits; the provider's `samples` stays exactly **1**.
- `aspect_ratio`: `1:1`, `16:9`, `9:16`, `5:4`, `4:5`,
  `3:2`, `2:3`.
- `1:1` uses FLUX.1 Schnell, which only supports native **1024×1024**.
  Other ratios use the dedicated Stable Diffusion 3 Medium endpoint with
  its documented `aspect_ratio` parameter. Exact pixel dimensions are
  **not** controllable through these hosted endpoints, and are never
  advertised as custom pixel resolution.
- `seed`: zero asks the provider to choose; nonzero seeds increment for
  variations. PNG/JPEG artifacts, maximum 20 MB/16 megapixels, private downloads.
- `mode=edit|reference`: only available if an administrator configures a
  private compatible NIM endpoint `CLOUD_IMAGE_EDIT_NIM_URL`,
  `CLOUD_IMAGE_EDIT_NIM_KEY`, and its model name.
  Public NVIDIA preview FLUX Kontext only accepts canned sample images and
  **cannot** handle arbitrary user-provided photos.
- `mode=inpaint|outpaint`: requires a **mask-aware** private NIM configured
  via `CLOUD_IMAGE_MASK_NIM_URL`, `CLOUD_IMAGE_MASK_NIM_KEY`, and
  `CLOUD_IMAGE_MASK_MODEL`. Runtime must support image/mask data URIs and
  `mode`. Mask must be a PNG the same dimensions as the source image.
  If not configured, these choices are **disabled**. Editing is not
  falsely represented as working on the public preview service.
- For Bangla, Banglish and non-Latin image prompts, Laravel optionally requests
  an English rendering from a configured chat model while retaining the
  original user prompt; failed translation falls back to the original.
  Translation quality needs live evaluation and can vary by model.

## Request examples

Authenticated `POST /api/v1/media/cloud-image`:

```json
{"prompt":"A mountain sunset","mode":"generate","aspect_ratio":"16:9","count":3,"seed":10}
```

Reply: `202 {"id":123,"ids":[123,124,125],"status":"queued","kind":"image"}`.

For editing, add `"mode":"edit"` or `"mode":"reference"` and `"image":"<raw PNG/JPEG base64>"`.
For masked operations also supply `"mask":"<raw PNG base64>"`.

`GET /api/v1/media/image-capabilities` provides safe capabilities, including
`image_editing` and `image_masking`; the UI hides modes without a configured
private endpoint.

## Verification

- CI: Laravel `CloudImagePipelineTest`, existing `MediaTest`, and frontend
  `hosted-image.integration.test.mjs` / frontend inference routing tests.
  Provider fixtures prove request/response and storage code only.
- Live smoke test: use a real server-side NVIDIA key; enqueue 1 square and 1
  non-square image; run queue worker; confirm each returns nonempty JPEG/PNG
  through the signed-in download, and the media record has `complete` status
  and exactly one success credit. Verify a second account cannot download it.
  Test cancellation, provider 429, failed storage, multilingual prompts,
  and quota exhaustion. For editing, verify compatible private NIM independently.
- Do not claim full live validation without production credentials, running
  queue workers and actual inference outputs. Check PHP and cPanel request/body
  limits separately for the maximum 4 MB reference image.

Public provider parameter references:
https://docs.api.nvidia.com/nim/reference/black-forest-labs-flux_1-schnell-infer
https://docs.api.nvidia.com/nim/reference/stabilityai-stable-diffusion-3-medium-infer
https://docs.nvidia.com/nim/visual-genai/latest/api/openai-image-editing.html

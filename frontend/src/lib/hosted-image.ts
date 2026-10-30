// Server-only cloud image bridge. The NVIDIA key is owned exclusively by Laravel.
// Generation runs on the Laravel media queue, not the Vercel request.
type ImageConfig = { apiKey?: string; bridgeKey?: string };
type Fetcher = typeof fetch;
type ImageOptions = { fetcher?: Fetcher; config?: ImageConfig; waitForCompletion?: boolean };

export async function generateHostedImage(
  body: string, token: string, backend: string, signal: AbortSignal, options: ImageOptions = {},
): Promise<Response> {
  let data: Record<string, unknown>;
  try {
    data = JSON.parse(body);
    if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error("Invalid");
  } catch {
    return Response.json({message: "Invalid image request."}, {status: 400});
  }
  if (typeof data.prompt !== "string" || !data.prompt.trim() || data.prompt.length > 4000)
    return Response.json({message: "Enter an image description up to 4000 characters."}, {status: 422});

  const fetcher = options.fetcher || fetch;
  const base = backend.replace(/\/$/, "");
  const headers = {Authorization: "Bearer " + token, Accept: "application/json", "Content-Type": "application/json"};
  const request = async (endpoint: string, method: "GET" | "POST", json?: unknown) =>
    fetcher(base + "/" + endpoint, {
      method, headers, ...(json ? {body: JSON.stringify(json)} : {}),
      redirect: "error", cache: "no-store", signal: AbortSignal.any([signal, AbortSignal.timeout(18000)]),
    });
  try {
    const started = await request("media/cloud-image", "POST", data);
    if (!started.ok) {
      if ([502, 503, 504].includes(started.status)) {
        return Response.json({
          message: "Image backend unavailable (HTTP " + started.status + "). The queue may have accepted your request. Check Library before retrying.",
        }, {status: started.status, headers: {"Cache-Control":"no-store"}});
      }
      const result = await started.json().catch(() => ({}));
      return Response.json({message: typeof result.message === "string" ? result.message : "Image request rejected."},
        {status: started.status, headers: {"Cache-Control":"no-store"}});
    }
    const created = await started.json();
    if (!Number.isSafeInteger(created?.id) || created.id < 1 || !Array.isArray(created.ids))
      return Response.json({message:"Invalid cloud image task response."}, {status:502});
    // A durable task is accepted, not completed. Return its ID immediately so
    // chat can display and poll it without occupying the inference tool loop.
    return Response.json(created, {status:202, headers:{"Cache-Control":"no-store"}});
  } catch {
    // A slow request can be accepted upstream even if the acknowledgement
    // times out. Never retry a non-idempotent generation request automatically.
    return Response.json({message:"The image service did not acknowledge the request before timeout. Check Library for an accepted task before retrying."}, {status:504, headers:{"Cache-Control":"no-store"}});
  }
}

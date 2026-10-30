// Parse hosted inference JSON with a hard byte cap while streaming.
// A missing or forged Content-Length must not allow an unbounded response.
export class ProviderResponseError extends Error {
  constructor(message = "The generation service returned an invalid response.") {
    super(message);
  }
}

export async function readProviderJson<T = unknown>(
  response: Response,
  maximumBytes: number,
): Promise<T> {
  if (!Number.isSafeInteger(maximumBytes) || maximumBytes < 1)
    throw new ProviderResponseError();

  const contentType = response.headers.get("content-type");
  if (contentType && !/^application\/(?:[a-z0-9.+-]*\+)?json(?:\s*;|\s*$)/i.test(contentType))
    throw new ProviderResponseError();

  const length = response.headers.get("content-length");
  if (length !== null) {
    const bytes = Number(length);
    if (!Number.isSafeInteger(bytes) || bytes < 0 || bytes > maximumBytes)
      throw new ProviderResponseError("The generation service response is too large.");
  }
  if (!response.body) throw new ProviderResponseError();

  const reader = response.body.getReader();
  const chunks: Uint8Array[] = [];
  let total = 0;
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > maximumBytes) {
        await reader.cancel().catch(() => {});
        throw new ProviderResponseError("The generation service response is too large.");
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const body = Buffer.concat(chunks.map(chunk => Buffer.from(chunk)), total);
  try {
    return JSON.parse(body.toString("utf8")) as T;
  } catch {
    throw new ProviderResponseError();
  }
}

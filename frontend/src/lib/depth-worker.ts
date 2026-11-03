// Runs Depth Anything in a background thread so model start-up and inference
// never freeze the page. Messages: progress events, then the depth map or an error.
import { env, pipeline, RawImage } from "@huggingface/transformers";

const MODEL = "onnx-community/depth-anything-v2-small";
env.allowLocalModels = false;

type Estimator = (image: RawImage) => Promise<{ depth: RawImage }>;
let estimator: Promise<Estimator> | null = null;
const post = (message: unknown, transfer: Transferable[] = []) => (self as unknown as Worker).postMessage(message, transfer);

function load(): Promise<Estimator> {
  if (!estimator) {
    // Real download progress: aggregate events when available, otherwise the
    // per-file events for the ONNX weights (by far the largest file).
    const progress_callback = (info: { status: string; file?: string; loaded?: number; total?: number; progress?: number }) => {
      const percent = info.status === "progress_total" && info.total
        ? ((info.loaded || 0) / info.total) * 100
        : info.status === "progress" && info.file?.endsWith(".onnx")
          ? info.total ? ((info.loaded || 0) / info.total) * 100 : info.progress
          : undefined;
      if (typeof percent === "number" && Number.isFinite(percent))
        post(percent >= 100 ? { type: "progress", stage: "prepare", percent: null } : { type: "progress", stage: "download", percent: Math.round(percent) });
    };
    const webgpu = "gpu" in navigator;
    estimator = (async () => {
      try {
        return (await pipeline("depth-estimation", MODEL, { device: webgpu ? "webgpu" : "wasm", dtype: webgpu ? "fp16" : "q8", progress_callback })) as unknown as Estimator;
      } catch {
        // WebGPU can exist but be unusable (drivers, blocklists): use WebAssembly.
        return (await pipeline("depth-estimation", MODEL, { device: "wasm", dtype: "q8", progress_callback })) as unknown as Estimator;
      }
    })();
    estimator.catch(() => { estimator = null; });
  }
  return estimator;
}

self.onmessage = async (event: MessageEvent<{ image: Blob }>) => {
  try {
    post({ type: "progress", stage: "download", percent: 0 });
    const estimate = await load();
    post({ type: "progress", stage: "depth", percent: null });
    const { depth } = await estimate(await RawImage.fromBlob(event.data.image));
    const data = new Uint8Array(depth.data);
    post({ type: "depth", data, width: depth.width, height: depth.height }, [data.buffer]);
  } catch (error) {
    post({ type: "error", message: error instanceof Error ? error.message : "Depth estimation failed." });
  }
};

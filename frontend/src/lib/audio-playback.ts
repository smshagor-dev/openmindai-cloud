// Playback listeners must be installed before play() to avoid losing short clips.
export function playAudioUntilFinished(
  player: Pick<HTMLAudioElement, "play" | "pause" | "addEventListener" | "removeEventListener">,
  signal: AbortSignal,
): Promise<void> {
  return new Promise<void>((resolve, reject) => {
    if (signal.aborted) {
      resolve();
      return;
    }
    let settled = false;
    const cleanup = () => {
      player.removeEventListener("ended", done);
      player.removeEventListener("pause", done);
      player.removeEventListener("error", failed);
      signal.removeEventListener("abort", aborted);
    };
    const finish = (error?: Error) => {
      if (settled) return;
      settled = true;
      cleanup();
      if (error) reject(error);
      else resolve();
    };
    const done = () => finish();
    const failed = () => finish(new Error("Audio playback failed."));
    const aborted = () => {
      player.pause();
      finish();
    };
    player.addEventListener("ended", done, { once: true });
    player.addEventListener("pause", done, { once: true });
    player.addEventListener("error", failed, { once: true });
    signal.addEventListener("abort", aborted, { once: true });
    // Do not leak a rejection from an autoplay policy, unsupported codec or
    // a race where playback was cancelled before the browser could start it.
    void player.play().catch((reason: unknown) => {
      if (signal.aborted) finish();
      else finish(reason instanceof Error ? reason : new Error("Cannot start audio playback."));
    });
  });
}

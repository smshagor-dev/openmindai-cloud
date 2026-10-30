import test from "node:test";
import assert from "node:assert/strict";
import { playAudioUntilFinished } from "../src/lib/audio-playback.ts";

function player(behavior = "pending") {
  const media = new EventTarget();
  let playCount = 0;
  let pauseCount = 0;
  media.play = async () => {
    playCount++;
    if (behavior === "reject") throw new Error("Autoplay rejected");
    if (behavior === "instant") queueMicrotask(() => media.dispatchEvent(new Event("ended")));
  };
  media.pause = () => {
    pauseCount++;
    media.dispatchEvent(new Event("pause"));
  };
  return { media, get playCount() { return playCount; }, get pauseCount() { return pauseCount; } };
}
test("short generated audio resolves when ended immediately", async () => {
  const { media } = player("instant");
  await playAudioUntilFinished(media, new AbortController().signal);
});
test("generated audio resolves immediately on interruption", async () => {
  const clip = player();
  const controller = new AbortController();
  const done = playAudioUntilFinished(clip.media, controller.signal);
  controller.abort();
  await done;
  assert.equal(clip.pauseCount, 1);
});
test("an already aborted session never starts playback", async () => {
  const clip = player();
  const controller = new AbortController();
  controller.abort();
  await playAudioUntilFinished(clip.media, controller.signal);
  assert.equal(clip.playCount, 0);
});
test("voice player rejects denied autoplay without hanging", async () => {
  const clip = player("reject");
  await assert.rejects(
    playAudioUntilFinished(clip.media, new AbortController().signal),
    /Autoplay rejected/,
  );
});
test("voice player rejects decoder failures", async () => {
  const clip = player();
  const listening = playAudioUntilFinished(clip.media, new AbortController().signal);
  clip.media.dispatchEvent(new Event("error"));
  await assert.rejects(listening, /playback failed/);
});

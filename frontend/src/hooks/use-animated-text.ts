"use client";
import { useEffect, useRef, useState } from "react";

// Smooth buffered chunks without changing the complete message used by history/copy.
export function useAnimatedText(
  content: string,
  animate: boolean,
  reducedMotion = false,
) {
  const [visible, setVisible] = useState(animate ? "" : content);
  const displayed = useRef(animate ? "" : content);
  const target = useRef(content);
  const frame = useRef<number | null>(null);
  const lastFrame = useRef(0);
  const enabled = useRef(animate);
  const reduced = useRef(reducedMotion);
  target.current = content;
  enabled.current = animate;
  reduced.current = reducedMotion;

  useEffect(() => {
    const media = window.matchMedia("(prefers-reduced-motion: reduce)");
    const instant = () =>
      !enabled.current ||
      reduced.current ||
      media.matches ||
      document.documentElement.dataset.reducedMotion === "true" ||
      document.hidden;
    const flush = () => {
      if (frame.current !== null) cancelAnimationFrame(frame.current);
      frame.current = null;
      displayed.current = target.current;
      setVisible(target.current);
    };
    if (instant()) {
      flush();
      return;
    }
    if (!content.startsWith(displayed.current)) {
      displayed.current = "";
      setVisible("");
    }
    if (frame.current !== null || displayed.current === content) return;
    lastFrame.current = 0;
    const tick = (now: number) => {
      if (instant()) {
        flush();
        return;
      }
      const elapsed = lastFrame.current ? now - lastFrame.current : 32;
      if (elapsed < 28) {
        frame.current = requestAnimationFrame(tick);
        return;
      }
      lastFrame.current = now;
      const text = target.current;
      const remaining = text.length - displayed.current.length;
      const step = Math.max(
        1,
        Math.ceil(remaining / 16),
        Math.floor(Math.min(elapsed, 64) * 0.1),
      );
      let end = Math.min(text.length, displayed.current.length + step);
      // Do not split UTF-16 surrogate pairs (emoji and supplementary characters).
      if (end < text.length && /[\uD800-\uDBFF]/.test(text[end - 1])) end++;
      displayed.current = text.slice(0, end);
      setVisible(displayed.current);
      frame.current = end < text.length ? requestAnimationFrame(tick) : null;
    };
    frame.current = requestAnimationFrame(tick);
  }, [content, animate, reducedMotion]);

  useEffect(
    () => () => {
      if (frame.current !== null) cancelAnimationFrame(frame.current);
    },
    [],
  );
  return {
    text: animate && !reducedMotion ? visible : content,
    pending: animate && !reducedMotion && visible !== content,
  };
}

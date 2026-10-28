// Only same-origin, server-issued artifact links become inline cards. The media
// endpoint independently enforces ownership on every status/output request.
export function generatedMediaIds(content: string): number[] {
  const ids = new Set<number>();
  for (const match of content.matchAll(/\[(?:View|Download) generated (?:image|document|speech|video)\]\(\/api\/backend\/media\/([1-9]\d*)\/output\)/g)) {
    const id = Number(match[1]);
    if (Number.isSafeInteger(id)) ids.add(id);
  }
  return [...ids].slice(0, 12);
}

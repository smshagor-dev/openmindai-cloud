export function effectiveLanguage(
  preferred: unknown,
  detected: unknown,
): string {
  const value = String(preferred || "auto");
  return value.toLowerCase() === "auto" ? String(detected || "auto") : value;
}
export function languageCode(value: string): string {
  const names: Record<string, string> = {
    english: "en",
    বাংলা: "bn",
    bengali: "bn",
    русский: "ru",
    russian: "ru",
    العربية: "ar",
    arabic: "ar",
    हिन्दी: "hi",
    hindi: "hi",
    中文: "zh",
    chinese: "zh",
    español: "es",
    spanish: "es",
    français: "fr",
    french: "fr",
    deutsch: "de",
    german: "de",
  };
  return (
    names[value.trim().toLowerCase()] || value.toLowerCase().split(/[-_]/)[0]
  );
}

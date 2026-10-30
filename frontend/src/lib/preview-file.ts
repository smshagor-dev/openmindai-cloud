export function documentFile(blob: Blob, mime = blob.type): File | undefined {
  const extension: Record<string, string> = {
    "application/pdf": "pdf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
      "docx",
    "application/msword": "doc",
    "text/markdown": "md",
    "text/plain": "txt",
  };
  return extension[mime]
    ? new File([blob], `document.${extension[mime]}`, { type: mime })
    : undefined;
}

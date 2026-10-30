import TypingIndicator from "./typing-indicator";

const imageStages: Record<string, string> = {
  queued: "Waiting for an image worker",
  preparing: "Preparing your image",
  generating: "Generating your image",
  finalizing: "Finalizing your image",
  complete: "Image ready",
};

export default function CreationProgress({
  kind,
  preparing = false,
  progress = null,
  stage = null,
  taskId = null,
  status = null,
}: {
  kind: string;
  preparing?: boolean;
  progress?: number | null;
  stage?: string | null;
  taskId?: number | null;
  status?: string | null;
}) {
  const video = kind === "video";
  const image = kind === "image";
  const name = kind === "document_create"
    ? "document"
    : kind === "speech"
      ? "audio"
      : kind;
  // This is server-reported workflow progress, not a guessed model-rendering
  // percentage. Providers with a single synchronous response cannot report steps.
  // Until the backend returns a task ID and status, no percentage can be verified.
  // The image provider reports completed workflow milestones, not rendering steps.
  const accepted = image && taskId !== null && Number.isSafeInteger(taskId);
  const percent = accepted && typeof progress === "number" && Number.isFinite(progress)
    ? Math.max(0, Math.min(100, Math.round(progress)))
    : null;
  const label = image && !accepted
    ? "Submitting your image request"
    : image && status == null
      ? "Checking your image request"
      : image && stage && imageStages[stage]
        ? imageStages[stage]
        : preparing ? `Preparing your ${name}` : `Creating your ${name}`;

  return (
    <section className={`creation-progress ${video ? "creation-video" : ""}`}
      aria-label={`Creating ${name}`}>
      <div className="creation-canvas" aria-hidden="true">
        <div className="creation-glow" />
        <div className="creation-grid" />
        <span className="creation-glyph">
          {video ? "▷" : image ? "✧" : "✳"}
        </span>
        {percent !== null && (
          <span className="creation-canvas-percent">{percent}%</span>
        )}
        {video && (
          <div className="creation-film">
            <i /><i /><i /><i /><i /><i />
          </div>
        )}
      </div>
      <div className="creation-caption" role={image ? "status" : undefined}
        aria-live={image ? "polite" : undefined} aria-atomic={image ? "true" : undefined}>
        <TypingIndicator label={label} />
        {percent !== null && (
          <span className="creation-progress-announcement">
            {"Image generation workflow: " + label + ", " + percent + "%"}
          </span>
        )}
        <span>{accepted
          ? `Task #${taskId} accepted · Status: ${status || "checking"}`
          : image ? "Waiting for the server to accept your request." : "You can stay here while it takes shape."}</span>
      </div>
    </section>
  );
}

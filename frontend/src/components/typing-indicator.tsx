export default function TypingIndicator({
  writing = false,
  label = "Thinking",
}: {
  writing?: boolean;
  label?: string;
}) {
  if (writing)
    return (
      <span
        className="response-cursor"
        role="status"
        aria-label="Writing response"
      >
        <span className="sr-only">Writing response</span>
      </span>
    );
  return (
    <span className="work-status" role="status">
      <span className="work-spark" aria-hidden="true">
        ✳
      </span>
      <span className="work-status-label">{label}</span>
    </span>
  );
}

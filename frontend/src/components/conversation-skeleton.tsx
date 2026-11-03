// Animated placeholder shown while a saved chat or research project opens.
export default function ConversationSkeleton({ label = "Loading conversation…" }: { label?: string }) {
  return (
    <div className="conversation-skeleton" role="status" aria-live="polite">
      <span className="sr-only">{label}</span>
      <div className="skeleton-turn is-user" aria-hidden="true">
        <i className="skeleton-bubble" />
      </div>
      <div className="skeleton-turn" aria-hidden="true">
        <span className="skeleton-avatar">
          <img src="/openmindai-logo.png" width={20} height={20} alt="" />
        </span>
        <div className="skeleton-lines"><i /><i /><i /><i /></div>
      </div>
      <div className="skeleton-turn is-user" aria-hidden="true">
        <i className="skeleton-bubble is-short" />
      </div>
      <div className="skeleton-turn" aria-hidden="true">
        <span className="skeleton-avatar">
          <img src="/openmindai-logo.png" width={20} height={20} alt="" />
        </span>
        <div className="skeleton-lines"><i /><i /><i /></div>
      </div>
    </div>
  );
}

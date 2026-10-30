"use client";
import Image from "next/image";
import { useEffect, useState, type ReactNode } from "react";
import {
  BookOpen,
  Grid2X2,
  LogIn,
  Menu,
  MessageCircle,
  PanelLeftClose,
} from "lucide-react";
import { api, type User } from "@/lib/api";
import { applyAppearance, type Preferences } from "@/lib/settings";

type Studio = "3d" | "research";
const studios: { id: Studio; href: string; label: string; icon: typeof Grid2X2 }[] = [
  { id: "3d", href: "/3d-studio", label: "3D Studio", icon: Grid2X2 },
  { id: "research", href: "/research-studio", label: "Research Studio", icon: BookOpen },
];

/**
 * The chat workspace's sidebar, top bar and theme around a studio page, so
 * 3D Studio and Research Studio share one navigation and look with chat.
 */
export default function StudioFrame({
  active,
  primary,
  sidebar,
  topbarRight,
  children,
}: {
  active: Studio;
  primary: { label: string; icon: ReactNode; onClick: () => void; disabled?: boolean };
  sidebar?: ReactNode;
  topbarRight?: ReactNode;
  children: ReactNode;
}) {
  const [user, setUser] = useState<User | null>(null);
  const [checked, setChecked] = useState(false);
  const [open, setOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    const media = matchMedia("(prefers-color-scheme: light)");
    let saved: Preferences | null = null;
    const change = () => {
      if (saved) applyAppearance(saved);
    };
    api<User>("me", "GET", undefined, controller.signal)
      .then((account) => {
        setUser(account);
        return api<Preferences>("preferences", "GET", undefined, controller.signal);
      })
      .then((p) => {
        saved = p;
        change();
      })
      .catch(() => {
        /* Signed out: keep the system theme, like the chat home. */
      })
      .finally(() => setChecked(true));
    media.addEventListener("change", change);
    return () => {
      controller.abort();
      media.removeEventListener("change", change);
    };
  }, []);
  const current = studios.find((s) => s.id === active)!;
  return (
    <div className={`workspace studio-frame ${collapsed ? "sidebar-collapsed" : ""}`}>
      {open && (
        <button
          className="sidebar-overlay"
          aria-label="Close sidebar"
          onClick={() => setOpen(false)}
        />
      )}
      <aside
        className={`sidebar ${open ? "mobile-open" : ""}`}
        aria-label={`${current.label} navigation`}
      >
        <div className="sidebar-top">
          <a className="sidebar-brand" href="/">
            <span className="brand-symbol">
              <Image src="/openmindai-logo.png" alt="" width={30} height={30} />
            </span>
            <span className="sidebar-brand-copy">
              <strong>OpenMindAI</strong>
              <span>AI Workspace</span>
            </span>
          </a>
          <button
            className="icon-button sidebar-toggle"
            aria-label="Collapse sidebar"
            onClick={() => {
              setCollapsed(true);
              setOpen(false);
            }}
          >
            <PanelLeftClose size={20} />
          </button>
        </div>
        <div className="sidebar-scroll">
          <nav className="sidebar-navigation" aria-label="Main navigation">
            <button
              className="sidebar-action new-chat-action"
              disabled={primary.disabled}
              onClick={() => {
                primary.onClick();
                setOpen(false);
              }}
            >
              {primary.icon}
              <span>{primary.label}</span>
            </button>
            <a className="sidebar-action" href="/">
              <MessageCircle size={19} />
              <span>Chat</span>
            </a>
            {studios.map(({ id, href, label, icon: Icon }) => (
              <a
                key={id}
                className={`sidebar-action ${id === active ? "selected" : ""}`}
                href={href}
                aria-current={id === active ? "page" : undefined}
              >
                <Icon size={19} />
                <span>{label}</span>
              </a>
            ))}
          </nav>
          {sidebar}
        </div>
        <div className="sidebar-footer">
          {user ? (
            <a className="profile-button" href="/">
              <span className="avatar">
                {user.avatar ? (
                  <img src={user.avatar} alt="" />
                ) : (
                  user.name.charAt(0).toUpperCase()
                )}
              </span>
              <span>
                <strong>{user.name}</strong>
                <small>Personal account</small>
              </span>
            </a>
          ) : (
            checked && (
              <a className="profile-button" href="/">
                <span className="avatar guest">
                  <LogIn size={18} />
                </span>
                <span>
                  <strong>Log in</strong>
                  <small>Save your work and more</small>
                </span>
              </a>
            )
          )}
        </div>
      </aside>
      <main className="main-panel studio-frame-main">
        <header className="topbar">
          <div className="topbar-left">
            <button
              className="icon-button mobile-menu"
              aria-label="Open sidebar"
              onClick={() => setOpen(true)}
            >
              <Menu size={21} />
            </button>
            {collapsed && (
              <button
                className="icon-button desktop-expand"
                aria-label="Expand sidebar"
                onClick={() => setCollapsed(false)}
              >
                <Menu size={21} />
              </button>
            )}
            <span className="topbar-brand">
              <Image
                src="/openmindai-logo.png"
                width={28}
                height={28}
                alt=""
                className="topbar-brand-logo"
              />
              <span>OpenMindAI</span>
              <span className="studio-frame-section">{current.label}</span>
            </span>
          </div>
          {topbarRight && <div className="topbar-right">{topbarRight}</div>}
        </header>
        <div className="studio-frame-body">{children}</div>
      </main>
    </div>
  );
}

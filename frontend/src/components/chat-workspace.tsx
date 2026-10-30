"use client";
import WorkspaceWelcome from "./workspace-welcome";
import type {ResearchMetadata} from "@/lib/research-status";
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import {
  ArrowUp,
  AudioLines,
  Plus,
  SlidersHorizontal,
  Paperclip,
  FolderOpen,
  FolderPlus,
  GitBranch,
  Images,
  Grid2X2,
  Archive,
  Pin,
  Square,
  RotateCcw,
  Pencil,
  ThumbsUp,
  ThumbsDown,
  Volume2,
  BookOpen,
  Check,
  Code2,
  Copy,
  Lightbulb,
  LoaderCircle,
  LogIn,
  UserRound,
  LogOut,
  Menu,
  MessageCircle,
  MoreHorizontal,
  PanelLeftClose,
  PenLine,
  Search,
  Settings,
  SquarePen,
  Trash2,
  X,
} from "lucide-react";
import dynamic from "next/dynamic";
import Image from "next/image";
import CreationProgress from "./creation-progress";
import { generatedMediaIds } from "@/lib/chat-artifacts";
import TypingIndicator from "./typing-indicator";
import ModelPicker from "./model-picker";
import Dialog from "./dialog";
import WelcomeToolsMenu, { type WelcomeToolAction } from "./welcome-tools-menu";
import BrowserTabLinksDialog from "./browser-tab-links-dialog";
import { applyAppearance, type Preferences } from "@/lib/settings";
const ToolsWorkbench = dynamic(() => import("./tools-workbench"));
const FilePreview = dynamic(() => import("./file-preview"));
const InlineMedia = dynamic(() => import("./inline-media"));
const AuthDialog = dynamic(() => import("./auth-dialog"));
const AccountDialog = dynamic(() => import("./account-dialog"));
const CanvasDialog = dynamic(() => import("./canvas-dialog"));
const WorkspaceDialog = dynamic(() => import("./workspace-dialog"));
const ConversationDialog = dynamic(() => import("./conversation-dialog"));
const ResearchResponse = dynamic(() => import("./research-response"));
import {
  api,
  streamChat,
  ApiError,
  type Conversation,
  type Message,
  type Model,
  type Page,
  type User,
} from "@/lib/api";

function publicChatError(message: string): string {
  // Keep actionable error details without exposing the hosted model's branding.
  return message
    .replace(/\bNVIDIA_MODEL\b/g, "hosted model configuration")
    .replace(/\bNVIDIA_API_KEY\b/g, "hosted service credentials")
    .replace(/\bNVIDIA(?:['’]s)?\b/gi, "hosted AI service")
    .replace(/\bNemotron\b/gi, "hosted model");
}

const suggestions = [
  {
    icon: Code2,
    title: "Write & debug code",
    text: "Help me build a modern web application",
    color: "blue",
  },
  {
    icon: PenLine,
    title: "Write something",
    text: "Write a thoughtful and professional email",
    color: "coral",
  },
  {
    icon: BookOpen,
    title: "Learn a concept",
    text: "Explain machine learning in simple terms",
    color: "purple",
  },
  {
    icon: Lightbulb,
    title: "Explore ideas",
    text: "Brainstorm creative ideas for my next project",
    color: "amber",
  },
  {
    icon: Search,
    title: "Explore a topic",
    text: "Help me research a topic with reliable sources",
    color: "blue",
  },
  {
    icon: MessageCircle,
    title: "Get a second opinion",
    text: "Help me think through an important decision",
    color: "purple",
  },
];
export default function ChatWorkspace() {
  const [guestRemaining, setGuestRemaining] = useState<number | null>(null);
  const guestSlug = useRef<string | null>(null);
  const restoredRoute = useRef(false);
  const routeHandler = useRef<() => void>(() => {});
  function chatUrl(id: number, title: string) {
    const label = title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "").slice(0, 60) || "chat";
    return `/c/${id}-${label}`;
  }
  function changeUrl(path: string) {
    if (window.location.pathname !== path) window.history.pushState(null, "", path);
  }
  const [settingsRevision, setSettingsRevision] = useState(0);
  const [preferences, setPreferences] = useState<Preferences | null>(null);
  const [toolsOpen, setToolsOpen] = useState<string | null>(null);
  const [filePreview, setFilePreview] = useState<File | null>(null);
  const [inlineImage, setInlineImage] = useState<{
    name: string; mime: "image/png" | "image/jpeg" | "image/webp"; data: string;
  } | null>(null);
  const [inlineMedia, setInlineMedia] = useState<number[]>([]);
  const [assistant, setAssistant] = useState<{
    id: number;
    name: string;
    model_id: number | null;
  } | null>(null);
  const [branches, setBranches] = useState<
    { id: number; title: string }[] | null
  >(null);
  const [mediaMode, setMediaMode] = useState("chat");
  const [mediaKinds, setMediaKinds] = useState<string[]>([]);
  const [searchAvailable, setSearchAvailable] = useState(false);
  const [webSearch, setWebSearch] = useState(false);
  const [deepResearch, setDeepResearch] = useState(false);
  const [pendingCreation, setPendingCreation] = useState<string | null>(null);
  const [researchProgress, setResearchProgress] = useState("");
  const [memoryNotice, setMemoryNotice] = useState("");
  const [searchTimeRange, setSearchTimeRange] = useState("");
  const [canvasInitial, setCanvasInitial] = useState<string | null>(null);
  const [workspaceOpen, setWorkspaceOpen] = useState(false);
  const [projectToolOpen, setProjectToolOpen] = useState(false);
  const [browserTabLinksOpen, setBrowserTabLinksOpen] = useState(false);
  const [toolsChat, setToolsChat] = useState<Conversation | null>(null);
  const [projects, setProjects] = useState<{ id: number; name: string }[]>([]);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [archived, setArchived] = useState(false);
  const [temporary, setTemporary] = useState(false);
  const [attachments, setAttachments] = useState<
    { id: number; name: string }[]
  >([]);
  const [uploading, setUploading] = useState(false);
  const [readAloud, setReadAloud] = useState(false);
  const filtersRef = useRef({
    search: "",
    projectId: null as number | null,
    archived: false,
  });
  const generationRef = useRef<AbortController | null>(null);
  const activeStream = useRef<{
    conversation_id: number | null;
    message_id: number | null;
  } | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const [user, setUser] = useState<User | null>(null);
  const identityRef = useRef<number | null>(null);
  identityRef.current = user?.id ?? null;
  const uploadRequest = useRef<AbortController | null>(null);
  const [initializing, setInitializing] = useState(true);
  const [models, setModels] = useState<Model[]>([]);
  const [modelsLoading, setModelsLoading] = useState(false);
  const [modelReload, setModelReload] = useState(0);
  const [modelId, setModelId] = useState<number | null>(null);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyLastPage, setHistoryLastPage] = useState(1);
  const [messages, setMessages] = useState<Message[]>([]);
  const [conversationId, setConversationId] = useState<number | null>(null);
  const [messagePage, setMessagePage] = useState(1);
  const [draft, setDraft] = useState("");
  const [editingMessageIndex, setEditingMessageIndex] = useState<number | null>(null);
  const [editingText, setEditingText] = useState("");
  const [savingEdit, setSavingEdit] = useState(false);
  const editTextareaRef = useRef<HTMLTextAreaElement>(null);
  const [search, setSearch] = useState("");
  const [searchOpen, setSearchOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [authOpen, setAuthOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);
  const [accountMenu, setAccountMenu] = useState(false);
  const [historyMenuId, setHistoryMenuId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [loadingChat, setLoadingChat] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(false);
  const [error, setError] = useState("");
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const skipScroll = useRef(false);
  const [copied, setCopied] = useState<number | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const followOutput = useRef(true);
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const chatRequest = useRef<AbortController | null>(null);
  const historyRequest = useRef<AbortController | null>(null);
  const accountMenuRef = useRef<HTMLDivElement>(null);
  const composerToolsRef = useRef<HTMLDetailsElement>(null);
  const [toolMenuOpen, setToolMenuOpen] = useState(false);
  const [toolMenuPosition, setToolMenuPosition] = useState({
    top: 12, left: 12, width: 440, maxHeight: 570,
  });

  const clearSession = useCallback(() => {
    chatRequest.current?.abort();
    historyRequest.current?.abort();
    generationRef.current?.abort();
    uploadRequest.current?.abort();
    setWorkspaceOpen(false);
    setProjectToolOpen(false);
    setBrowserTabLinksOpen(false);
    setToolsOpen(null);
    setFilePreview(null);
    setInlineImage(null);
    setInlineMedia([]); setPendingCreation(null);
    setAssistant(null);
    setBranches(null);
    setCanvasInitial(null);
    setToolsChat(null);
    setUser(null);
    guestSlug.current = null;
    if (window.location.pathname !== "/") window.history.replaceState(null, "", "/");
    setPreferences(null);
    delete document.documentElement.dataset.accent;
    delete document.documentElement.dataset.textSize;
    delete document.documentElement.dataset.reducedMotion;
    delete document.documentElement.dataset.theme;
    setModels([]);
    setModelsLoading(false);
    setModelId(null);
    setConversations([]);
    setMessages([]);
    setEditingMessageIndex(null);
    setEditingText("");
    setSavingEdit(false);
    setConversationId(null);
    setAccountOpen(false);
    setAccountMenu(false);
    setDraft("");
    setTemporary(false);
    setAttachments([]);
    setProjects([]);
    setProjectId(null);
    setArchived(false);
    setSearch("");
    setWebSearch(false);
    setDeepResearch(false);
  }, []);
  const report = useCallback(
    (e: unknown) => {
      if (e instanceof Error && e.name === "AbortError") return;
      if (e instanceof ApiError && e.status === 401) clearSession();
      setError(publicChatError(e instanceof Error ? e.message : "Something went wrong."));
    },
    [clearSession],
  );
  const refreshHistory = useCallback(async (page = 1) => {
    historyRequest.current?.abort();
    const controller = new AbortController();
    historyRequest.current = controller;
    const result = await api<Page<Conversation>>(
      `conversations?page=${page}&q=${encodeURIComponent(filtersRef.current.search)}&archived=${filtersRef.current.archived ? 1 : 0}${filtersRef.current.projectId ? `&project_id=${filtersRef.current.projectId}` : ""}`,
      "GET",
      undefined,
      controller.signal,
    );
    setConversations((previous) =>
      page === 1
        ? result.data
        : [
            ...previous,
            ...result.data.filter(
              (item) => !previous.some((old) => old.id === item.id),
            ),
          ],
    );
    setHistoryPage(result.current_page);
    setHistoryLastPage(result.last_page);
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    api<User>("me", "GET", undefined, controller.signal)
      .then(setUser)
      .catch((e) => {
        if (!(e instanceof ApiError && e.status === 401)) report(e);
      })
      .finally(() => setInitializing(false));
    return () => controller.abort();
  }, [report]);
  useEffect(() => {
    if (!user) return;
    const controller = new AbortController();
    setModelsLoading(true);
    api<Model[]>("models", "GET", undefined, controller.signal)
      .then((result) => {
        if (controller.signal.aborted) return;
        if (!Array.isArray(result)) throw new Error("Invalid chat connection response.");
        setModels(result);
        setModelId((previous) => result.some((model) => model.id === previous)
          ? previous : (result[0]?.id ?? null));
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        setModels([]);
        setModelId(null);
        if (error instanceof ApiError && error.status === 401) report(error);
      })
      .finally(() => {
        if (!controller.signal.aborted) setModelsLoading(false);
      });
    return () => controller.abort();
  }, [user?.id, modelReload, report]);
  useEffect(() => {
    if (!user) return;
    const controller = new AbortController();
    void api<{ web_search: boolean; media: string[] }>(
      "capabilities",
      "GET",
      undefined,
      controller.signal,
    )
      .then((c) => {
        setSearchAvailable(c.web_search);
        setMediaKinds(c.media || []);
      })
      .catch(report);
    void api<{ id: number; name: string }[]>(
      "projects",
      "GET",
      undefined,
      controller.signal,
    )
      .then(setProjects)
      .catch(report);
    return () => {
      controller.abort();
      historyRequest.current?.abort();
    };
  }, [user?.id, refreshHistory, report]); // Reload data only when the signed-in identity changes.
  useEffect(() => {
    if (!user) return;
    const controller = new AbortController();
    const media = matchMedia("(prefers-color-scheme: light)");
    let saved: Preferences | null = null;
    const change = () => {
      if (saved) applyAppearance(saved);
    };
    api<Preferences>("preferences", "GET", undefined, controller.signal)
      .then((p) => {
        saved = p;
        setPreferences(p);
        change();
      })
      .catch(report);
    media.addEventListener("change", change);
    return () => {
      controller.abort();
      media.removeEventListener("change", change);
    };
  }, [user?.id, settingsRevision, report]);
  useEffect(() => {
    const element = inputRef.current;
    if (!element) return;
    const resize = () => {
      element.style.height = "auto";
      element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
    };
    resize();
    let width = element.clientWidth;
    const observer = new ResizeObserver(() => {
      if (width !== element.clientWidth) {
        width = element.clientWidth;
        resize();
      }
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, [draft]);
  useEffect(() => {
    if (editingMessageIndex !== null) {
      const textarea = editTextareaRef.current;
      textarea?.focus();
      textarea?.setSelectionRange(textarea.value.length, textarea.value.length);
    }
  }, [editingMessageIndex]);
  useEffect(() => {
    if (skipScroll.current) {
      skipScroll.current = false;
      return;
    }
    if (!loadingChat)
      scrollRef.current?.scrollTo({
        top: scrollRef.current.scrollHeight,
        behavior: preferences?.reduced_motion ? "instant" : "smooth",
      });
  }, [messages.length, busy, loadingChat, pendingCreation, inlineMedia.length]);
  useEffect(() => {
    function dismiss(event: MouseEvent) {
      if (!accountMenuRef.current?.contains(event.target as Node))
        setAccountMenu(false);
      if (
        !(event.target instanceof Element) ||
        !event.target.closest(".history-item")
      )
        setHistoryMenuId(null);
      if (!composerToolsRef.current?.contains(event.target as Node))
        composerToolsRef.current?.removeAttribute("open");
    }
    document.addEventListener("mousedown", dismiss);
    return () => document.removeEventListener("mousedown", dismiss);
  }, []);
  useEffect(
    () => () => {
      generationRef.current?.abort();
      if (typeof speechSynthesis !== "undefined") speechSynthesis.cancel();
      chatRequest.current?.abort();
      historyRequest.current?.abort();
    },
    [],
  );

  useEffect(() => {
    function keyboard(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setHistoryMenuId(null);
        composerToolsRef.current?.removeAttribute("open");
        setAccountMenu(false);
        setSidebarOpen(false);
      }
      if (
        (event.ctrlKey || event.metaKey) &&
        event.key.toLowerCase() === "k" &&
        !busy
      ) {
        event.preventDefault();
        chatRequest.current?.abort();
        setLoadingChat(false);
        setConversationId(null);
        setMessages([]);
        setDraft("");
        setError("");
        setSidebarOpen(false);
        inputRef.current?.focus();
      }
    }
    document.addEventListener("keydown", keyboard);
    return () => document.removeEventListener("keydown", keyboard);
  }, [busy]);
  useLayoutEffect(() => {
    if (!toolMenuOpen) return;
    const reposition = () => {
      const summary = composerToolsRef.current?.querySelector("summary");
      const menu = composerToolsRef.current?.querySelector<HTMLElement>(".composer-tools-menu");
      if (!summary || !menu) return;
      const rect = summary.getBoundingClientRect();
      const viewport = window.visualViewport;
      const vw = viewport?.width ?? window.innerWidth;
      const vh = viewport?.height ?? window.innerHeight;
      const offsetY = viewport?.offsetTop ?? 0;
      const mobile = vw <= 600;
      const width = Math.min(440, Math.max(180, vw - 24));
      const availableAbove = Math.max(0, rect.top - offsetY - 20);
      const availableBelow = Math.max(0, vh - (rect.bottom - offsetY) - 20);
      const above = availableAbove >= 300 || availableAbove >= availableBelow;
      const availableHeight = mobile
        ? Math.max(100, vh - 24)
        : Math.max(100, above ? availableAbove : availableBelow);
      const maxHeight = Math.min(570, availableHeight);
      const visibleHeight = Math.min(menu.scrollHeight, maxHeight);
      const left = mobile ? 12
        : Math.max(12, Math.min(rect.left - 44, vw - width - 12));
      const top = mobile
        ? offsetY + vh - visibleHeight - 12
        : above
          ? Math.max(offsetY + 12, rect.top - visibleHeight - 10)
          : Math.min(offsetY + vh - visibleHeight - 12, rect.bottom + 10);
      setToolMenuPosition((previous) => {
        if (previous.top === top && previous.left === left &&
            previous.width === width && previous.maxHeight === maxHeight) return previous;
        return { top, left, width, maxHeight };
      });
    };
    reposition();
    window.addEventListener("resize", reposition);
    window.addEventListener("scroll", reposition, true);
    window.visualViewport?.addEventListener("resize", reposition);
    window.visualViewport?.addEventListener("scroll", reposition);
    return () => {
      window.removeEventListener("resize", reposition);
      window.removeEventListener("scroll", reposition, true);
      window.visualViewport?.removeEventListener("resize", reposition);
      window.visualViewport?.removeEventListener("scroll", reposition);
    };
  }, [toolMenuOpen]);

  const scrollBottom = useCallback(() => {
    if (!followOutput.current) return;
    scrollRef.current?.scrollTo({top: scrollRef.current.scrollHeight, behavior: "instant"});
  }, []);
  useEffect(() => {
    filtersRef.current = { search, projectId, archived };
    if (!user) return;
    const timer = setTimeout(() => void refreshHistory().catch(report), 250);
    return () => clearTimeout(timer);
  }, [search, projectId, archived, user?.id, refreshHistory, report]);
  useEffect(() => {
    setReadAloud(typeof speechSynthesis !== "undefined");
  }, []);
  const filteredHistory = conversations;
  function newChat(updateUrl = true) {
    if (busy && updateUrl) return;
    chatRequest.current?.abort();
    setLoadingChat(false);
    setConversationId(null);
    guestSlug.current = null;
    if (updateUrl) changeUrl("/");
    setAssistant(null);
    setMediaMode("chat");
    setInlineImage(null);
    setInlineMedia([]); setPendingCreation(null);
    setMessages([]);
    setEditingMessageIndex(null);
    setEditingText("");
    setMessagePage(1);
    setAttachments([]);
    setDraft("");
    setError("");
    setMemoryNotice("");
    setSidebarOpen(false);
    inputRef.current?.focus();
  }
  async function openChat(id: number, fromRoute = false) {
    if ((!fromRoute && busy) || deleteId) return;
    chatRequest.current?.abort();
    const controller = new AbortController();
    chatRequest.current = controller;
    setLoadingChat(true);
    setEditingMessageIndex(null);
    setEditingText("");
    setTemporary(false);
    setAttachments([]);
    setError("");
    setSidebarOpen(false);
    try {
      let result = await api<{
        conversation: Conversation;
        messages: Page<Message>;
      }>(`conversations/${id}`, "GET", undefined, controller.signal);
      if (result.messages.last_page > 1)
        result = await api(
          `conversations/${id}?page=${result.messages.last_page}`,
          "GET",
          undefined,
          controller.signal,
        );
      if (controller.signal.aborted) return;
      setConversationId(id);
      changeUrl(chatUrl(id, result.conversation.title));
      setInlineMedia([]); setPendingCreation(null);
      setInlineImage(null);
      setAssistant(
        result.conversation.assistant_id
          ? {
              id: result.conversation.assistant_id,
              name: "Custom assistant",
              model_id: null,
            }
          : null,
      );
      setMessages(result.messages.data);
      setMessagePage(result.messages.current_page);
      setDraft("");
    } catch (e) {
      report(e);
    } finally {
      if (!controller.signal.aborted) setLoadingChat(false);
    }
  }
  routeHandler.current = () => {
    generationRef.current?.abort();
    generationRef.current = null;
    setBusy(false);
    setInlineMedia([]); setPendingCreation(null); setInlineImage(null); setAttachments([]); setDraft("");
    setEditingMessageIndex(null); setEditingText("");
    const slug = /^\/c\/([^/]+)\/?$/.exec(window.location.pathname)?.[1];
    if (!slug) { newChat(false); return; }
    if (slug.startsWith("guest-")) {
      if (user) { changeUrl("/"); setMessages([]); return; }
      guestSlug.current = slug;
      try {
        const saved = JSON.parse(localStorage.getItem(`openmindai.guest.v1.${slug}`) || "[]");
        setMessages(Array.isArray(saved) ? saved.filter((m) => ["user", "assistant"].includes(m.role) && typeof m.content === "string").slice(-40).map((m) => ({...m, animate: false})) : []);
      } catch { setError("This browser could not restore the guest chat."); }
      return;
    }
    const id = /^(\d+)-/.exec(slug)?.[1];
    if (id && user) void openChat(Number(id), true);
    else if (!user) setAuthOpen(true);
    else setError("Conversation not found.");
  };
  useEffect(() => {
    if (initializing) return;
    if (!restoredRoute.current) {
      restoredRoute.current = true;
      routeHandler.current();
    }
    const restore = () => routeHandler.current();
    window.addEventListener("popstate", restore);
    return () => window.removeEventListener("popstate", restore);
  }, [initializing, user?.id]);
  useEffect(() => {
    if (!user && guestSlug.current && messages.length) {
      try { localStorage.setItem(`openmindai.guest.v1.${guestSlug.current}`, JSON.stringify(messages.slice(-40))); }
      catch { /* Browser storage may be disabled; the active chat stays available. */ }
    }
  }, [messages, user]);
  async function olderMessages() {
    if (!conversationId || loadingChat || busy) return;
    chatRequest.current?.abort();
    const controller = new AbortController();
    chatRequest.current = controller;
    setLoadingChat(true);
    try {
      const result = await api<{ messages: Page<Message> }>(
        `conversations/${conversationId}?page=${messagePage - 1}`,
        "GET",
        undefined,
        controller.signal,
      );
      if (controller.signal.aborted) return;
      skipScroll.current = true;
      setMessages((previous) => [...result.messages.data, ...previous]);
      setMessagePage(result.messages.current_page);
    } catch (e) {
      report(e);
    } finally {
      if (!controller.signal.aborted) setLoadingChat(false);
    }
  }
  async function send(
    event?: React.FormEvent,
    override?: {
      text: string;
      conversationId: number | null;
      history: Message[];
      attachmentIds?: number[];
      resumeId?: number;
    },
  ) {
    event?.preventDefault();
    const text = (override?.text ?? draft).trim() ||
      (!override && inlineImage ? "Describe this image." : "");
    if (!text || busy || loadingChat || initializing) return;
    followOutput.current = true;
    if (!user) {
      const controller = new AbortController();
      generationRef.current = controller;
      const history = messages;
      const slug = guestSlug.current || `guest-${crypto.randomUUID()}`;
      guestSlug.current = slug;
      changeUrl(`/c/${slug}`);
      setMessages([...history, {role: "user", content: text, animate: true}, {role: "assistant", content: "", status: "generating", animate: true}]);
      setDraft(""); setBusy(true); setError("");
      try {
        const response = await api<{content: string; remaining: number}>("guest/chat", "POST", {
          message: text, history: history.slice(-4).map(({role, content}) => ({role, content: content.slice(0, 4000)})),
        }, controller.signal);
        if (controller.signal.aborted) return;
        setMessages([...history, {role: "user", content: text, animate: true}, {role: "assistant", content: response.content, status: "complete", animate: true}]);
        setGuestRemaining(response.remaining);
      } catch (e) {
        if (controller.signal.aborted) {
          if (generationRef.current === controller) setMessages((previous) => previous.map((message, index) => index === previous.length - 1 ? {...message, status: "cancelled"} : message));
          return;
        }
        setMessages(history); setDraft(text);
        if (e instanceof ApiError && [403, 429].includes(e.status)) setAuthOpen(true);
        report(e);
      } finally {
        if (generationRef.current === controller) {setBusy(false); setPendingCreation(null); generationRef.current = null;}
      }
      return;
    }
    if (mediaMode !== "chat" && !override && !inlineImage && !mediaKinds.includes(mediaMode))
      return setError("This media engine is not configured.");
    if (inlineImage && !models.some((m) => m.id === -2)) {
      setError("Image chat requires OpenMindAI hosted vision to be enabled.");
      return;
    }
    if (modelsLoading) return;
    if (!modelId && !override?.resumeId) {
      setError("Reconnect to chat and try again.");
      return;
    }
    const history = override?.history ?? messages;
    const id = override ? override.conversationId : conversationId;
    setError("");
    setBusy(true);
    setResearchProgress("Thinking about your request");
    setMemoryNotice("");
    if (!override?.resumeId) setDraft("");
    setMessages(
      override?.resumeId
        ? history
        : [...history, { role: "user", content: text, animate: true }],
    );
    const controller = new AbortController();
    generationRef.current = controller;
    activeStream.current = null;
    let receivedMeta = false;
    let autoMedia = false;
    try {
      await streamChat(
        {
          model_id: inlineImage && !override ? -2 : modelId,
          ...(override?.resumeId
            ? { resume_message_id: override.resumeId }
            : {}),
          ...(assistant ? { assistant_id: assistant.id } : {}),
          message: text,
          ...(!override && mediaMode !== "chat" ? {generation_kind: mediaMode} : {}),
          ...(!override && inlineImage ? {image: {mime: inlineImage.mime, data: inlineImage.data}} : {}),
          ...(id ? { conversation_id: id } : {}),
          ...(projectId ? { project_id: projectId } : {}),
          temporary,
          web_search: webSearch,
          deep_research: deepResearch,
          ...(searchTimeRange ? { search_time_range: searchTimeRange } : {}),
          attachment_ids:
            override?.attachmentIds ?? attachments.map((a) => a.id),
          ...(temporary
            ? {
                history: history.slice(-20).map((m) => ({
                  role: m.role,
                  content: m.content.slice(0, 16000),
                })),
              }
            : {}),
        },
        (event, data) => {
          if (controller.signal.aborted) return;
          if (event === "creation") setPendingCreation(data.status === "running" ? String(data.kind) : null);
          if (event === "artifact" && Number.isSafeInteger(data.id) && Number(data.id) > 0) {
            setPendingCreation(null);
            // The next delta contains the persisted task link, rendered in its
            // assistant turn rather than a transient workspace-only list.
          }
          if (event === "media" && Number.isSafeInteger(data.id) && Number(data.id) > 0) {
            // Compatibility with older media events: preserve the submitted turn.
            autoMedia = true;
            setInlineMedia((ids) => [...ids, Number(data.id)]);
          }
          if (event === "meta") {
            receivedMeta = true;
            const cid = data.conversation_id as number | null;
            setConversationId(cid);
            if (cid && cid !== id) changeUrl(chatUrl(cid, text));
            activeStream.current = {
              conversation_id: cid,
              message_id: data.message_id as number | null,
            };
            if (override?.resumeId)
              setMessages((previous) =>
                previous.map((m) =>
                  m.id === override.resumeId
                    ? {
                        ...m,
                        content: String(data.content || ""),
                        status: "generating",
                        animate: true,
                        resumable: true,
                        sources: data.sources as Message["sources"],
                      }
                    : m,
                ),
              );
            else
              setMessages((previous) => [
                ...previous.slice(0, -1),
                {
                  ...previous[previous.length - 1],
                  id: data.user_message_id as number | undefined,
                },
                {
                  id: data.message_id as number | undefined,
                  role: "assistant",
                  content: "",
                  status: "generating",
                  animate: true,
                  resumable: Boolean(data.resumable),
                  sources: data.sources as Message["sources"],
                },
              ]);
          }
          if (event === "memory" && Number(data.saved) > 0)
            setMemoryNotice(
              `Saved ${Number(data.saved)} new memory. Manage it in Memory settings.`,
            );
          if (event === "research") {
            if(data.metadata)setMessages(previous=>previous.map((m,i)=>i===previous.length-1?{...m,research_metadata:data.metadata as ResearchMetadata}:m));
            setResearchProgress(String(data.label));
            if (Array.isArray(data.sources))
              setMessages((previous) =>
                previous.map((m, i) =>
                  i === previous.length - 1
                    ? { ...m, sources: data.sources as Message["sources"] }
                    : m,
                ),
              );
          }
          if (event === "answer" && typeof data.content === "string") {
            setMessages(previous=>previous.map((message,index)=>index === previous.length-1 ? {...message,content:data.content as string} : message));
          }
          if (event === "delta")
            setMessages((previous) =>
              previous.map((m, i) =>
                i === previous.length - 1
                  ? { ...m, content: m.content + String(data.content) }
                  : m,
              ),
            );
          if (event === "error") setError(publicChatError(String(data.message)));
          if (
            event === "done" &&
            data.status === "complete" &&
            preferences?.completion_notifications &&
            document.hidden &&
            "Notification" in window &&
            Notification.permission === "granted"
          ) {
            try {
              new Notification("Openmindai", {
                body: "Your response is ready.",
                tag: "openmindai-response",
                icon: "/openmindai-logo.png",
              });
            } catch {
              /* Mobile browsers may require service-worker notifications. */
            }
          }
          // Keep the transient image in this composer for follow-up questions.
          // New chat, conversation switch, logout, or Remove image clears it.
          // The image remains in browser memory only, never in Laravel history.
          if (event === "done" && !autoMedia)
            setMessages((previous) =>
              previous.map((m, i) =>
                i === previous.length - 1
                  ? {
                      ...m,
                      status: String(data.status),
                      resumable: data.status !== "complete" && m.resumable,
                    }
                  : m,
              ),
            );
        },
        controller.signal,
      );
      if (!override?.resumeId) setAttachments([]);
      if (!controller.signal.aborted && !temporary) void refreshHistory().catch(report);
    } catch (e) {
      if (generationRef.current !== controller) return;
      if ((e as Error).name === "AbortError") {
        setMessages((previous) =>
          previous.map((m, i) =>
            i === previous.length - 1 ? { ...m, status: "cancelled" } : m,
          ),
        );
      } else {
        if (!receivedMeta) {
          // Keep the submitted turn visible when preparation fails, so an error
          // cannot silently drop the user back onto the welcome screen.
          setMessages([...history, { role: "user", content: text }]);
          if (!override?.resumeId) setDraft(text);
        }
        report(e);
      }
    } finally {
      if (generationRef.current === controller) {
        setBusy(false); setPendingCreation(null);
        generationRef.current = null;
        activeStream.current = null;
        inputRef.current?.focus();
      }
    }
  }
  async function stop() {
    setPendingCreation(null);
    const active = activeStream.current;
    if (active?.conversation_id && active.message_id) {
      try {
        await api(
          `conversations/${active.conversation_id}/messages/${active.message_id}/stop`,
          "POST",
        );
      } catch (e) {
        report(e);
      }
    } else { generationRef.current?.abort(); setBusy(false); }
  }
  function startEditingMessage(index: number) {
    if (busy || savingEdit || !conversationId) return;
    const target = messages[index];
    if (!target?.id || target.role !== "user") return;
    setEditingMessageIndex(index);
    setEditingText(target.content);
  }
  function cancelEditingMessage() {
    if (savingEdit) return;
    setEditingMessageIndex(null);
    setEditingText("");
  }
  async function saveEditedMessage(index: number) {
    const original = messages[index];
    const content = editingText.trim();
    if (editingMessageIndex !== index || savingEdit || busy || !conversationId ||
        !original?.id || original.role !== "user" || !content ||
        content === original.content.trim()) return;
    setSavingEdit(true);
    try {
      const saved = await branch(index, content);
      if (saved) {
        setEditingMessageIndex(null);
        setEditingText("");
      }
    } finally {
      setSavingEdit(false);
    }
  }
  async function branch(index: number, revisedText?: string): Promise<boolean> {
    if (busy || !conversationId) return false;
    const target = messages[index];
    if (!target?.id) return false;
    const content = (revisedText ?? target.content).trim();
    if (!content) return false;
    try {
      const result = await api<{
        conversation_id: number;
        message: string;
        attachment_ids: number[];
      }>("conversations/" + conversationId + "/branch", "POST", {
        message_id: target.id,
      });
      setConversationId(result.conversation_id);
      changeUrl(chatUrl(result.conversation_id, content));
      await send(undefined, {
        text: content,
        conversationId: result.conversation_id,
        history: messages.slice(0, index),
        attachmentIds: result.attachment_ids,
      });
      return true;
    } catch (e) {
      report(e);
      return false;
    }
  }
  async function feedback(index: number, value: "up" | "down") {
    if (!conversationId || !messages[index].id) return;
    try {
      await api(
        `conversations/${conversationId}/messages/${messages[index].id}/feedback`,
        "PUT",
        { feedback: messages[index].feedback === value ? null : value },
      );
      setMessages((previous) =>
        previous.map((m, i) =>
          i === index
            ? { ...m, feedback: m.feedback === value ? null : value }
            : m,
        ),
      );
    } catch (e) {
      report(e);
    }
  }
  function selectGenerationTool(kind: string) {
    composerToolsRef.current?.removeAttribute("open");
    setToolMenuOpen(false);
    if (!user) {setAuthOpen(true); return;}
    if (kind !== "chat" && !mediaKinds.includes(kind)) {setError("This generation tool is unavailable."); return;}
    setMediaMode(kind);
    setWebSearch(false); setDeepResearch(false);
    inputRef.current?.focus();
  }
  function selectWelcomeTool(action: WelcomeToolAction) {
    composerToolsRef.current?.removeAttribute("open");
    if (action === "tabs") {
      setBrowserTabLinksOpen(true);
      return;
    }
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (action === "files") fileRef.current?.click();
    if (action === "library") setToolsOpen("library");
    if (action === "image" || action === "document") {
      selectGenerationTool(action === "image" ? "image" : "document_create");
    }
    if (action === "project") {
      setProjectToolOpen(true);
      setWorkspaceOpen(true);
    }
    if (action === "web" || action === "research") {
      if (!searchAvailable) {
        setError("Web search requires a configured search provider.");
        return;
      }
      const turningOff = action === "research"
        ? deepResearch
        : webSearch && !deepResearch;
      setWebSearch(!turningOff);
      setDeepResearch(action === "research" && !turningOff);
      setMediaMode("chat");
      inputRef.current?.focus();
    }
    if (action === "voice") setToolsOpen("voice");
    if (action === "code") setToolsOpen("code");
    if (action === "planner") setToolsOpen("research");
  }

  async function attachImage(file: File) {
    const mime = file.type;
    if (!["image/png", "image/jpeg", "image/webp"].includes(mime)) {
      setError("Use a PNG, JPEG or WebP image.");
      return;
    }
    if (!file.size || file.size > 4_000_000) {
      setError("Image must be at most 4 MB.");
      return;
    }
    const owner = user?.id;
    setUploading(true);
    setError("");
    try {
      const data = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
          const value = String(reader.result || "");
          const prefix = "data:" + mime + ";base64,";
          if (!value.startsWith(prefix)) reject(new Error("Invalid image data."));
          else resolve(value.slice(prefix.length));
        };
        reader.onerror = () => reject(new Error("Unable to read image."));
        reader.readAsDataURL(file);
      });
      if (identityRef.current !== owner) return;
      setInlineImage({name: file.name.slice(0, 120), mime: mime as "image/png" | "image/jpeg" | "image/webp", data});
      setMediaMode("chat");
      setFilePreview(null);
    } catch (error) {
      setError((error as Error).message);
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }
  function handleFile(file: File) {
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (busy || loadingChat || uploading) return;
    if (file.type.startsWith("image/") || /\.(png|jpe?g|webp)$/i.test(file.name)) {
      void attachImage(file);
      return;
    }
    if (file.size > 8000000) {
      setError("File exceeds 8 MB.");
      return;
    }
    if (
      /\.(pdf|docx?|md|markdown|txt|csv|png|jpe?g|webp|wav|mp3|flac|ogg|webm)$/i.test(file.name) ||
      /^(image|audio)\//.test(file.type)
    )
      setFilePreview(file);
    else void upload(file);
  }
  async function upload(file: File) {
    if (!user) {
      setAuthOpen(true);
      return;
    }
    if (file.size > 100000 || attachments.length >= 5) {
      setError("Attach up to five text/code files, at most 100 KB each.");
      return;
    }
    const owner = user.id;
    const controller = new AbortController();
    uploadRequest.current = controller;
    setUploading(true);
    try {
      const content = await file.text();
      if (controller.signal.aborted || identityRef.current !== owner) return;
      const data = await api<{ id: number; name: string }>(
        "attachments",
        "POST",
        { name: file.name, content },
        controller.signal,
      );
      if (identityRef.current === owner)
        setAttachments((previous) => [...previous, data]);
    } catch (e) {
      report(e);
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }
  function speak(text: string) {
    if (typeof speechSynthesis === "undefined") return;
    const voice = speechSynthesis.getVoices().find((v) => v.localService);
    if (!voice) {
      setError("No on-device speech voice is installed.");
      return;
    }
    speechSynthesis.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.voice = voice;
    speechSynthesis.speak(utterance);
  }
  async function deleteConversation(id: number) {
    setDeleteBusy(true);
    try {
      await api(`conversations/${id}`, "DELETE");
      setConversations((previous) => previous.filter((item) => item.id !== id));
      if (conversationId === id) newChat();
      setDeleteId(null);
    } catch (e) {
      report(e);
    } finally {
      setDeleteBusy(false);
    }
  }
  async function logout() {
    try {
      await api("logout", "POST");
      clearSession();
      setDraft("");
      setError("");
    } catch (e) {
      report(e);
    }
  }
  async function copy(content: string, index: number) {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(index);
      setTimeout(() => setCopied(null), 1800);
    } catch {
      setError("Clipboard unavailable. Select the text to copy it.");
    }
  }

  return (
    <div className={`workspace ${sidebarCollapsed ? "sidebar-collapsed" : ""}`}>
      {sidebarOpen && (
        <button
          className="sidebar-overlay"
          aria-label="Close sidebar"
          onClick={() => setSidebarOpen(false)}
        />
      )}
      <aside
        className={`sidebar ${sidebarOpen ? "mobile-open" : ""}`}
        aria-label="Chat history"
      >
        <div className="sidebar-top">
          <div className="sidebar-brand">
            <button
              className="brand-symbol"
              aria-label="OpenMindAI: start a new chat"
              onClick={() => newChat()}
              disabled={busy}
            >
              <Image src="/openmindai-logo.png" alt="" width={30} height={30} />
            </button>
            <span className="sidebar-brand-copy">
              <strong>OpenMindAI</strong>
              <span>AI Workspace</span>
            </span>
          </div>
          <button
            className="icon-button sidebar-toggle"
            aria-label="Collapse sidebar"
            onClick={() => {
              setSidebarCollapsed(true);
              setSidebarOpen(false);
            }}
          >
            <PanelLeftClose size={20} />
          </button>
        </div>
        <div className="sidebar-scroll">
          <nav className="sidebar-navigation" aria-label="Main navigation">
            <button
              className="sidebar-action new-chat-action"
              onClick={() => newChat()}
              disabled={busy}
            >
              <SquarePen size={19} />
              <span>New chat</span>
              <kbd>Ctrl K</kbd>
            </button>
            <button
              className="sidebar-action"
              aria-expanded={searchOpen}
              onClick={() => {
                setSearchOpen(!searchOpen);
              }}
            >
              <Search size={19} />
              <span>Search chats</span>
            </button>
            {searchOpen && (
              <div className="history-search">
                <Search size={15} />
                <input
                  aria-label="Search saved chats"
                  placeholder="Search all saved chats"
                  maxLength={100}
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  autoFocus
                />
                <button
                  className="icon-button"
                  aria-label="Clear search"
                  onClick={() => setSearch("")}
                >
                  <X size={14} />
                </button>
              </div>
            )}
            {user && (
              <>
                <a className="sidebar-action" href="/3d-studio"><Grid2X2 size={19} /><span>3D Studio</span></a>
                <a className="sidebar-action" href="/research-studio"><BookOpen size={19} /><span>Research Studio</span></a>
                <button
                  className="sidebar-action"
                  disabled={busy}
                  onClick={() => {
                    setToolsOpen("library");
                    setSidebarOpen(false);
                  }}
                >
                  <BookOpen size={19} />
                  <span>Library</span>
                </button>
                <button
                  className="sidebar-action"
                  disabled={busy}
                  onClick={() => {
                    selectGenerationTool("image");
                    setSidebarOpen(false);
                  }}
                >
                  <Images size={19} />
                  <span>Images</span>
                </button>
                <button
                  className="sidebar-action"
                  disabled={busy}
                  onClick={() => {
                    setToolsOpen("assistants");
                    setSidebarOpen(false);
                  }}
                >
                  <Grid2X2 size={19} />
                  <span>Assistants</span>
                </button>
                <button
                  className="sidebar-action"
                  disabled={busy}
                  onClick={() => {
                    setCanvasInitial("");
                    setSidebarOpen(false);
                  }}
                >
                  <PenLine size={19} />
                  <span>Canvas</span>
                </button>
              </>
            )}
          </nav>
          {user && (
            <section className="sidebar-projects" aria-label="Projects">
              <span className="section-label">Projects</span>
              <button
                className="sidebar-action"
                disabled={busy}
                onClick={() => {
                  setWorkspaceOpen(true);
                  setSidebarOpen(false);
                }}
              >
                <FolderPlus size={19} />
                <span>New project</span>
              </button>
              {projects.map((project) => (
                <button
                  key={project.id}
                  className={
                    "sidebar-action project-link " +
                    (projectId === project.id ? "selected" : "")
                  }
                  aria-pressed={projectId === project.id}
                  disabled={busy}
                  onClick={() => {
                    newChat();
                    setProjectId(projectId === project.id ? null : project.id);
                  }}
                >
                  <FolderOpen size={18} />
                  <span>{project.name}</span>
                </button>
              ))}
              {projectId !== null && (
                <button
                  className="sidebar-action"
                  disabled={busy}
                  onClick={() => {
                    newChat();
                    setProjectId(null);
                  }}
                >
                  <X size={18} />
                  <span>Show all chats</span>
                </button>
              )}
            </section>
          )}
          <div className="history-list">
            <span className="section-label">
              {archived
                ? "Archived chats"
                : projectId
                  ? "Project chats"
                  : "Your chats"}
            </span>
            {initializing ? (
              <p className="sidebar-empty">Loading your workspace…</p>
            ) : !user ? (
              <div className="sidebar-empty">
                <p>Log in to see your chat history.</p>
              </div>
            ) : filteredHistory.length === 0 ? (
              <p className="sidebar-empty">
                {search
                  ? "No matching conversations."
                  : "Your conversations will appear here."}
              </p>
            ) : (
              filteredHistory.map((chat) => (
                <div
                  className={`history-item ${conversationId === chat.id ? "active" : ""}`}
                  key={chat.id}
                >
                  <MessageCircle
                    size={15}
                    className="history-icon"
                    aria-hidden="true"
                  />
                  <button
                    className="history-title"
                    disabled={busy || loadingChat}
                    onClick={() => void openChat(chat.id)}
                    title={chat.title}
                  >
                    {chat.title}
                  </button>
                  <button
                    className="history-delete icon-button"
                    disabled={busy}
                    aria-label={"More options for " + chat.title}
                    aria-expanded={historyMenuId === chat.id}
                    onClick={() =>
                      setHistoryMenuId(
                        historyMenuId === chat.id ? null : chat.id,
                      )
                    }
                  >
                    <MoreHorizontal size={18} />
                  </button>
                  {historyMenuId === chat.id && (
                    <div className="history-menu popover">
                      <button
                        onClick={() => {
                          setToolsChat(chat);
                          setHistoryMenuId(null);
                        }}
                      >
                        <Pencil size={16} />
                        Rename, share & more
                      </button>
                      <button
                        className="history-menu-delete"
                        onClick={() => {
                          setDeleteId(chat.id);
                          setHistoryMenuId(null);
                        }}
                      >
                        <Trash2 size={16} />
                        Delete chat
                      </button>
                    </div>
                  )}
                </div>
              ))
            )}
            {user && historyPage < historyLastPage && (
              <button
                className="history-more"
                disabled={loadingHistory}
                onClick={async () => {
                  setLoadingHistory(true);
                  try {
                    await refreshHistory(historyPage + 1);
                  } catch (e) {
                    report(e);
                  } finally {
                    setLoadingHistory(false);
                  }
                }}
              >
                {loadingHistory ? "Loading…" : "Load more chats"}
              </button>
            )}
          </div>
        </div>
        <div className="sidebar-footer" ref={accountMenuRef}>
          {user ? (
            <>
              <button
                className="profile-button"
                onClick={() => setAccountMenu(!accountMenu)}
                aria-expanded={accountMenu}
              >
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
                <MoreHorizontal size={18} />
              </button>
              {accountMenu && (
                <div className="account-menu popover">
                  <button
                    onClick={() => {
                      setAccountOpen(true);
                      setAccountMenu(false);
                    }}
                  >
                    <Settings size={17} /> Settings
                  </button>
                  <button
                    disabled={busy}
                    onClick={() => {
                      setArchived(!archived);
                      setAccountMenu(false);
                    }}
                  >
                    <Archive size={17} />
                    {archived ? "Show active chats" : "Archived chats"}
                  </button>
                  <button
                    onClick={() => {
                      setWorkspaceOpen(true);
                      setAccountMenu(false);
                    }}
                  >
                    <FolderOpen size={17} />
                    Projects & memory
                  </button>
                  <button disabled={busy} onClick={() => void logout()}>
                    <LogOut size={17} /> Sign out
                  </button>
                </div>
              )}
            </>
          ) : (
            <button
              className="profile-button"
              onClick={() => setAuthOpen(true)}
              disabled={initializing}
            >
              <span className="avatar guest">
                <LogIn size={18} />
              </span>
              <span>
                <strong>Log in</strong>
                <small>Save your chats and more</small>
              </span>
            </button>
          )}
        </div>
      </aside>
      <main
        className={
          "main-panel " +
          (messages.length === 0 && !loadingChat && !pendingCreation && inlineMedia.length === 0 ? "is-empty" : "")
        }
      >
        <header className="topbar">
          <div className="topbar-left">
            <button
              className="icon-button mobile-menu"
              aria-label="Open sidebar"
              onClick={() => setSidebarOpen(true)}
            >
              <Menu size={21} />
            </button>
            {sidebarCollapsed && (
              <button
                className="icon-button desktop-expand"
                aria-label="Expand sidebar"
                onClick={() => setSidebarCollapsed(false)}
              >
                <Menu size={21} />
              </button>
            )}
            <span className="topbar-brand">
              <Image src="/openmindai-logo.png" width={28} height={28} alt="" className="topbar-brand-logo" />
              <span>OpenMindAI</span>
            </span>
          </div>
          <div className="topbar-right">
            <button
              className="icon-button topbar-new-chat"
              onClick={() => newChat()}
              disabled={busy}
              aria-label="Start a new conversation"
              title="New chat (Ctrl+K)"
            >
              <SquarePen size={18} />
            </button>
            {user && (
              <>
                <button
                  className={`temporary-toggle ${temporary ? "enabled" : ""}`}
                  disabled={busy}
                  onClick={() => {
                    newChat();
                    setTemporary(!temporary);
                  }}
                  title="Temporary chats aren't saved and don't use memory"
                >
                  {temporary ? "Temporary on" : "Temporary"}
                </button>
                {conversationId && (
                  <button
                    className="icon-button"
                    aria-label="Conversation settings and sharing"
                    onClick={() =>
                      setToolsChat(
                        conversations.find((c) => c.id === conversationId) || {
                          id: conversationId,
                          title: "Conversation",
                          updated_at: "",
                        },
                      )
                    }
                  >
                    <MoreHorizontal size={19} />
                  </button>
                )}
              </>
            )}
            <span className="private-label">
              <span className="status-dot" />
              Your models. Your space.
            </span>
            {!user && guestRemaining !== null && <small className="muted">{guestRemaining} guest messages remaining</small>}
            {!user && (
              <button
                className="login-pill"
                onClick={() => setAuthOpen(true)}
                disabled={initializing}
              >
                Log in
              </button>
            )}
            {conversationId && (
              <button
                type="button"
                className="icon-button"
                aria-label="Conversation branches"
                title="Conversation branches"
                disabled={busy}
                onClick={() =>
                  void api<{ id: number; title: string }[]>(
                    `conversations/${conversationId}/branches`,
                  )
                    .then(setBranches)
                    .catch(report)
                }
              >
                <GitBranch size={18} />
              </button>
            )}
            {user && (
              <button
                className="icon-button"
                aria-label="Open settings"
                onClick={() => setAccountOpen(true)}
              >
                <Settings size={19} />
              </button>
            )}
          </div>
        </header>
        <div className="chat-scroll" ref={scrollRef} onScroll={(event) => {
          const container = event.currentTarget;
          followOutput.current = container.scrollHeight - container.scrollTop - container.clientHeight < 160;
        }}>
          {messages.length === 0 && !loadingChat && !pendingCreation && inlineMedia.length === 0 ? (
            <section className="welcome">
              <WorkspaceWelcome title="What can I help with?" />
            </section>
          ) : (
            <div className="thread">
              {messagePage > 1 && (
                <button
                  className="load-earlier secondary-button"
                  disabled={loadingChat || busy}
                  onClick={() => void olderMessages()}
                >
                  Load earlier messages
                </button>
              )}
              {messages.map((message, index) => (
                <article
                  className={`message ${message.role}${message.animate ? " message-new" : ""}`}
                  key={message.id ?? `message-${index}`}
                  aria-label={
                    message.role === "user" ? "Your message" : "Model response"
                  }
                >
                  {message.role === "user" && (
                    <span className="chat-user-avatar" aria-label={user?.name || "You"}>
                      {user?.avatar ? (
                        <img src={user.avatar} alt="Your profile photo" />
                      ) : user?.name ? (
                        user.name.charAt(0).toUpperCase()
                      ) : (
                        <UserRound size={18} aria-hidden="true" />
                      )}
                    </span>
                  )}
                  {message.role === "assistant" && (
                    <span className="assistant-avatar">
                      <Image
                        src="/openmindai-logo.png"
                        alt="OpenMindAI"
                        width={23}
                        height={23}
                      />
                    </span>
                  )}
                  <div className="message-body">
                    {message.role === "user" ? (
                      editingMessageIndex === index ? (
                        <form
                          className="inline-message-editor"
                          aria-label="Edit your message"
                          onSubmit={(event) => {
                            event.preventDefault();
                            void saveEditedMessage(index);
                          }}
                        >
                          <label htmlFor={"message-edit-" + message.id} className="inline-edit-label">Edit message</label>
                          <textarea
                            id={"message-edit-" + message.id}
                            ref={editTextareaRef}
                            value={editingText}
                            onChange={(event) => setEditingText(event.target.value)}
                            maxLength={16000}
                            rows={Math.min(8, Math.max(3, editingText.split("\n").length))}
                            disabled={savingEdit}
                            onKeyDown={(event) => {
                              if (event.key === "Escape") {
                                event.preventDefault();
                                cancelEditingMessage();
                              } else if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing) {
                                event.preventDefault();
                                void saveEditedMessage(index);
                              }
                            }}
                          />
                          <div className="inline-edit-actions">
                            <button type="button" className="inline-edit-cancel"
                              disabled={savingEdit} onClick={cancelEditingMessage}>
                              Cancel
                            </button>
                            <button type="submit" className="inline-edit-save"
                              disabled={savingEdit || !editingText.trim() || editingText.trim() === message.content.trim()}>
                              {savingEdit ? "Saving…" : "Save & send"}
                            </button>
                          </div>
                        </form>
                      ) : (
                        <div className="user-bubble">
                          {message.content}
                          {message.id && conversationId && (
                            <button
                              type="button"
                              className="icon-button edit-message"
                              disabled={busy || savingEdit}
                              onClick={() => startEditingMessage(index)}
                              aria-label="Edit message"
                              title="Edit message"
                            >
                              <Pencil size={14} />
                            </button>
                          )}
                        </div>
                      )
                    ) : (
                      <>
                        <ResearchResponse
                          sources={message.sources}
                          metadata={message.research_metadata}
                          status={message.status}
                          content={message.content}
                          animate={Boolean(message.animate) && index === messages.length - 1}
                          streaming={message.status === "generating" && busy && index === messages.length - 1}
                          reducedMotion={Boolean(preferences?.reduced_motion)}
                          onRendered={
                            index === messages.length - 1
                              ? scrollBottom
                              : undefined
                          }
                        />
                        {generatedMediaIds(message.content).map(id => (
                          <div className="generated-media-content" key={id}>
                            <InlineMedia id={id} onText={setDraft} />
                          </div>
                        ))}
                        {message.status &&
                          message.status !== "complete" &&
                          message.status !== "generating" && message.research_metadata?.status !== "paused" && (
                            <small className="muted">
                              {message.status === "cancelled"
                                ? "Stopped"
                                : "Response interrupted"}
                            </small>
                          )}
                        {message.resumable &&
                          message.id &&
                          message.status !== "complete" &&
                          index === messages.length - 1 && (
                            <button
                              className="secondary-button"
                              disabled={busy || loadingChat}
                              onClick={() =>
                                void send(undefined, {
                                  text:
                                    messages[index - 1]?.content || "Continue",
                                  conversationId,
                                  history: messages,
                                  resumeId: message.id,
                                })
                              }
                            >
                              Resume response
                            </button>
                          )}
                        <div className="message-actions">
                          {message.id && (
                            <>
                              <button
                                className={`icon-button ${message.feedback === "up" ? "feedback-selected" : ""}`}
                                aria-label="Good response"
                                onClick={() => void feedback(index, "up")}
                              >
                                <ThumbsUp size={14} />
                              </button>
                              <button
                                className={`icon-button ${message.feedback === "down" ? "feedback-selected" : ""}`}
                                aria-label="Bad response"
                                onClick={() => void feedback(index, "down")}
                              >
                                <ThumbsDown size={14} />
                              </button>
                            </>
                          )}
                          {!!message.sources?.length && message.content && (
                            <button
                              type="button"
                              disabled={busy}
                              onClick={() => {
                                const evidence = message
                                  .sources!.map(
                                    (
                                      source,
                                      i,
                                    ) => `${source.citation || i + 1}. ${source.title}
${source.url}
${source.read_status === "read" ? "Page read" : "Snippet only"}`,
                                  )
                                  .join("\n\n");
                                const url = URL.createObjectURL(
                                  new Blob(
                                    [
                                      message.content +
                                        "\n\n## Source evidence\n\n" +
                                        evidence,
                                    ],
                                    { type: "text/markdown;charset=utf-8" },
                                  ),
                                );
                                const link = document.createElement("a");
                                link.href = url;
                                link.download = `openmindai-research-${message.id || index}.md`;
                                link.click();
                                setTimeout(
                                  () => URL.revokeObjectURL(url),
                                  1000,
                                );
                              }}
                            >
                              Download report
                            </button>
                          )}
                          {index > 0 && messages[index - 1].id && (
                            <button
                              className="icon-button"
                              aria-label="Regenerate in a new branch"
                              disabled={busy}
                              onClick={() => void branch(index - 1)}
                            >
                              <RotateCcw size={14} />
                            </button>
                          )}
                          {readAloud && (
                            <button
                              className="icon-button"
                              aria-label="Read aloud using an on-device voice"
                              onClick={() => speak(message.content)}
                            >
                              <Volume2 size={14} />
                            </button>
                          )}
                          <button
                            className="icon-button"
                            aria-label="Open in canvas"
                            onClick={() => setCanvasInitial(message.content)}
                          >
                            <PenLine size={14} />
                          </button>
                          <button
                            className="icon-button"
                            onClick={() => void copy(message.content, index)}
                            aria-label="Copy response"
                          >
                            {copied === index ? (
                              <Check size={15} />
                            ) : (
                              <Copy size={15} />
                            )}
                          </button>
                        </div>
                      </>
                    )}
                  </div>
                </article>
              ))}
              {busy && !pendingCreation && messages[messages.length - 1]?.role === "user" && (
                <div className="thinking"><span className="assistant-avatar"><Image src="/openmindai-logo.png" alt="" width={23} height={23} /></span><TypingIndicator label={researchProgress || "Thinking"} /></div>
              )}
              {pendingCreation && (
                <div className="generated-media-entry" role="status" aria-live="polite">
                  <span className="assistant-avatar" aria-hidden="true">
                    <Image src="/openmindai-logo.png" alt="" width={23} height={23} />
                  </span>
                  <div className="generated-media-content">
                    <CreationProgress kind={pendingCreation} />
                  </div>
                </div>
              )}
              {inlineMedia.map((id) => (
                <div className="generated-media-entry" key={id}>
                  <span className="assistant-avatar" aria-hidden="true">
                    <Image src="/openmindai-logo.png" alt="" width={23} height={23} />
                  </span>
                  <div className="generated-media-content">
                    <InlineMedia id={id} onText={setDraft} />
                  </div>
                </div>
              ))}
            </div>
          )}
          {loadingChat && (
            <div className="chat-loading" role="status">
              <LoaderCircle className="spin" size={21} />
              Loading conversation…
            </div>
          )}
        </div>
        <div
          className={`composer-area ${messages.length === 0 && !pendingCreation && inlineMedia.length === 0 ? "empty-composer" : ""}`}
        >
          {error && (
            <div className="chat-error" role="alert">
              <span>{error}</span>
              <button
                className="icon-button"
                aria-label="Dismiss error"
                onClick={() => setError("")}
              >
                <X size={15} />
              </button>
            </div>
          )}
          {assistant && (
            <div className="attachment-chips">
              <span>Assistant: {assistant.name}</span>
              <button
                onClick={() => {
                  newChat();
                  setAssistant(null);
                }}
              >
                Leave assistant
              </button>
            </div>
          )}
          {mediaMode !== "chat" && (
            <p className="muted">
              {mediaMode === "document_create" ? "Document creation" : `Create ${mediaMode}`} selected. Describe it below; the result appears in this chat and Library.
              <button type="button" onClick={() => selectGenerationTool("chat")}>Clear tool</button>
            </p>
          )}
          <form
            className="composer"
            onSubmit={send}
            onDragOver={(e) => {
              if (e.dataTransfer.types.includes("Files")) e.preventDefault();
            }}
            onDrop={(e) => {
              e.preventDefault();
              const f = e.dataTransfer.files[0];
              if (f) handleFile(f);
            }}
          >
            <textarea
              ref={inputRef}
              onPaste={(e) => {
                const imageFile = Array.from(e.clipboardData.files).find((f) => f.type.startsWith("image/"));
                if (imageFile) { e.preventDefault(); handleFile(imageFile); }
              }}
              aria-label="Message"
              placeholder="Ask anything"
              value={draft}
              maxLength={16000}
              rows={1}
              disabled={busy || loadingChat}
              onChange={(e) => setDraft(e.target.value)}
              onKeyDown={(e) => {
                if (
                  (preferences?.send_on_enter ?? true) &&
                  e.key === "Enter" &&
                  !e.shiftKey &&
                  !e.nativeEvent.isComposing
                ) {
                  e.preventDefault();
                  void send();
                }
              }}
            />
            <input
              ref={fileRef}
              type="file"
              accept="image/png,image/jpeg,image/webp,audio/*,.pdf,.doc,.docx,.webm,.txt,.md,.markdown,.csv,.json,.py,.js,.jsx,.ts,.tsx,.php,.rs,.go,.java,.c,.cpp,.h,.html,.css,.sql,.yaml,.yml,.xml,.sh,.log"
              hidden
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) handleFile(file);
              }}
            />
            {inlineImage && (
              <div className="attachment-chips" role="group" aria-label="Image attached to the next message">
                <span>
                  <img src={"data:" + inlineImage.mime + ";base64," + inlineImage.data}
                    width={36} height={36} alt="" style={{objectFit: "cover", borderRadius: 5}} />
                  {inlineImage.name}
                  <button type="button" aria-label="Remove attached image"
                    onClick={() => setInlineImage(null)}><X size={13} /></button>
                </span>
              </div>
            )}
            {attachments.length > 0 && (
              <div className="attachment-chips">
                {attachments.map((a) => (
                  <span key={a.id}>
                    <Paperclip size={12} />
                    {a.name}
                    <button
                      type="button"
                      aria-label={`Remove ${a.name}`}
                      onClick={() =>
                        setAttachments((previous) =>
                          previous.filter((f) => f.id !== a.id),
                        )
                      }
                    >
                      <X size={13} />
                    </button>
                  </span>
                ))}
              </div>
            )}
            {memoryNotice && (
              <p role="status" className="muted">
                {memoryNotice}
              </p>
            )}
            {busy && (deepResearch || researchProgress) && (
              <p className="muted tool-progress" role="status">
                <TypingIndicator label={researchProgress || "Researching sources"} />
              </p>
            )}
            <div className="composer-toolbar">
              <button
                type="button"
                className="icon-button"
                disabled={busy || uploading}
                aria-label="Add photos and files"
                title="Add photos and files"
                onClick={() => fileRef.current?.click()}
              >
                {uploading ? (
                  <LoaderCircle size={17} className="spin" />
                ) : (
                  <Plus size={22} strokeWidth={1.8} />
                )}
              </button>
              <details className="composer-tools" ref={composerToolsRef}
                onToggle={(event) => setToolMenuOpen(event.currentTarget.open)}>
                <summary>
                  <SlidersHorizontal size={17} />
                  <span>Tools</span>
                </summary>
                <WelcomeToolsMenu
                  position={toolMenuPosition}
                  onClose={() => {
                    composerToolsRef.current?.removeAttribute("open");
                    setToolMenuOpen(false);
                    composerToolsRef.current?.querySelector("summary")?.focus();
                  }}
                  busy={busy || uploading}
                  signedIn={Boolean(user)}
                  searchAvailable={searchAvailable}
                  imageAvailable={mediaKinds.includes("image")}
                  mediaKinds={mediaKinds}
                  mediaMode={mediaMode}
                  onMediaMode={selectGenerationTool}
                  webSearch={webSearch}
                  deepResearch={deepResearch}
                  searchTimeRange={searchTimeRange}
                  onSearchTimeRange={setSearchTimeRange}
                  onSelect={selectWelcomeTool}
                />
              </details>
              {user && mediaMode === "chat" && modelsLoading && (
                <LoaderCircle size={16} className="spin" role="status" aria-label="Connecting to chat" />
              )}
              {user && mediaMode === "chat" && !modelsLoading && !modelId && (
                <button type="button" className="icon-button" onClick={() => {setError(""); setModelReload((value) => value + 1);}} aria-label="Reconnect to chat" title="Reconnect to chat">
                  <RotateCcw size={16} />
                </button>
              )}
              {models.length > 1 && mediaMode === "chat" && (
                <ModelPicker models={models} value={modelId} onChange={setModelId}
                  disabled={busy || loadingChat || modelsLoading} />
              )}
              {(webSearch || mediaMode !== "chat") && (
                <span className="composer-active-mode">
                  {mediaMode !== "chat"
                    ? mediaMode === "document_create" ? "Create document" : "Create " + mediaMode
                    : deepResearch
                      ? "Deep research"
                      : "Web search"}
                </span>
              )}
              <div className="composer-right">
                <button
                  type="button"
                  className="icon-button voice-button"
                  aria-label="Open voice mode"
                  title="Voice mode"
                  disabled={busy}
                  onClick={() => setToolsOpen("voice")}
                >
                  <AudioLines size={21} />
                </button>
                {draft.length > 14000 && (
                  <small>{draft.length.toLocaleString()}/16,000</small>
                )}
                {busy ? (
                  <button
                    type="button"
                    className="send-button"
                    aria-label="Stop generating"
                    onClick={() => void stop()}
                  >
                    <Square size={14} fill="currentColor" />
                  </button>
                ) : (
                  <button
                    className="send-button"
                    type="submit"
                    aria-label="Send message"
                    disabled={
                      !draft.trim() ||
                      busy ||
                      loadingChat ||
                      initializing ||
                      (!!user && (modelsLoading || !modelId))
                    }
                  >
                    {busy ? (
                      <LoaderCircle size={19} className="spin" />
                    ) : (
                      <ArrowUp size={20} strokeWidth={2.5} />
                    )}
                  </button>
                )}
              </div>
            </div>
          </form>
          {messages.length === 0 && !loadingChat && !pendingCreation && inlineMedia.length === 0 && (
            <div className="suggestions" aria-label="Try a prompt">
              {suggestions.slice(0, 4).map((item) => (
                <button
                  key={item.title}
                  onClick={() => {
                    setDraft(item.text);
                    inputRef.current?.focus();
                  }}
                >
                  <item.icon
                    className={item.color}
                    size={21}
                    strokeWidth={1.6}
                  />
                  <span>{item.title}</span>
                </button>
              ))}
            </div>
          )}
          <p className="composer-note">
            AI can make mistakes. Check important information.
          </p>
        </div>
      </main>
      {browserTabLinksOpen && (
        <BrowserTabLinksDialog
          onClose={() => setBrowserTabLinksOpen(false)}
          onAdd={(links) => {
            setDraft((previous) =>
              (previous.trimEnd() ? previous.trimEnd() + "\n\n" : "") +
              "Use these browser tab links as context:\n" + links.join("\n"),
            );
            if (searchAvailable) {
              setWebSearch(true);
              setDeepResearch(false);
              setMediaMode("chat");
            }
            setBrowserTabLinksOpen(false);
            requestAnimationFrame(() => inputRef.current?.focus());
          }}
        />
      )}
      {canvasInitial !== null && (
        <CanvasDialog
          initial={canvasInitial}
          onClose={() => setCanvasInitial(null)}
          onUse={(a) =>
            setAttachments((previous) =>
              [...previous.filter((f) => f.id !== a.id), a].slice(-5),
            )
          }
        />
      )}
      {toolsOpen && user && (
        <ToolsWorkbench
          onConversation={(id) => {
            setToolsOpen(null);
            void openChat(id);
          }}
          initial={toolsOpen}
          onResearch={() => {
            if (!searchAvailable) {
              setError(
                "Web research needs an available search provider. Retry or ask your administrator to check search configuration.",
              );
              setToolsOpen(null);
              return;
            }
            setDeepResearch(true);
            setWebSearch(true);
            setToolsOpen(null);
          }}
          onClose={() => setToolsOpen(null)}
          onAssistant={(a) => {
            newChat();
            setAssistant(a);
            if (a.model_id) setModelId(a.model_id);
            setToolsOpen(null);
          }}
          onText={(text) => {
            setDraft(text);
            setToolsOpen(null);
          }}
          onAttachment={(file) => {
            setAttachments((old) =>
              [...old.filter((f) => f.id !== file.id), file].slice(-5),
            );
            setToolsOpen(null);
          }}
        />
      )}
      {filePreview && user && (
        <FilePreview
          file={filePreview}
          onAttach={(file) => {setFilePreview(null); void upload(file);}}
          onClose={() => setFilePreview(null)}
          onTask={(id) => setInlineMedia((old) => [...old, id])}
        />
      )}
      {branches && (
        <Dialog title="Conversation branches" onClose={() => setBranches(null)}>
          <p className="muted">
            Switch between saved response branches. Each branch keeps its own
            history.
          </p>
          {branches.map((b) => (
            <button
              className="manage-card"
              key={b.id}
              onClick={() => {
                setBranches(null);
                void openChat(b.id);
              }}
              aria-current={b.id === conversationId ? "page" : undefined}
            >
              {b.title}
              {b.id === conversationId ? " (current)" : ""}
            </button>
          ))}
        </Dialog>
      )}
      {workspaceOpen && (
        <WorkspaceDialog
          initialTab={projectToolOpen ? "projects" : "instructions"}
          onAttachment={(a) =>
            setAttachments((previous) =>
              [...previous.filter((f) => f.id !== a.id), a].slice(-5),
            )
          }
          onClose={() => { setWorkspaceOpen(false); setProjectToolOpen(false); }}
          onProject={(id) => {
            setProjectId(id);
            newChat();
          }}
          onChanged={() => {
            setSettingsRevision((v) => v + 1);
            void api<{ id: number; name: string }[]>("projects")
              .then(setProjects)
              .catch(report);
          }}
        />
      )}
      {toolsChat && (
        <ConversationDialog
          chat={toolsChat}
          onClose={() => setToolsChat(null)}
          onChanged={() => void refreshHistory().catch(report)}
        />
      )}
      {authOpen && (
        <AuthDialog
          onClose={() => setAuthOpen(false)}
          onLogin={(account) => {
            if (guestSlug.current) {
              guestSlug.current = null; setMessages([]); changeUrl("/");
            } else if (window.location.pathname.startsWith("/c/")) restoredRoute.current = false;
            setUser(account);
            setAuthOpen(false);
            setError("");
          }}
        />
      )}
      {accountOpen && user && (
        <AccountDialog
          user={user}
          onClose={() => setAccountOpen(false)}
          onUpdate={setUser}
          onChanged={() => setSettingsRevision((v) => v + 1)}
          onDataChanged={() => {
            generationRef.current?.abort();
            chatRequest.current?.abort();
            setBusy(false); setPendingCreation(null);
            setLoadingChat(false);
            setConversationId(null);
            setMessages([]);
            setAttachments([]);
            setDraft("");
            void refreshHistory().catch(report);
          }}
          onSignedOut={clearSession}
        />
      )}
      {deleteId && (
        <Dialog
          title="Delete this chat?"
          onClose={() => {
            if (!deleteBusy) setDeleteId(null);
          }}
        >
          <p className="dialog-description">
            This removes the conversation and its messages from your account.
          </p>
          <div className="button-row">
            <button
              className="secondary-button"
              disabled={deleteBusy}
              onClick={() => setDeleteId(null)}
            >
              Cancel
            </button>
            <button
              className="danger-button"
              disabled={deleteBusy}
              onClick={() => void deleteConversation(deleteId)}
            >
              {deleteBusy ? "Deleting…" : "Delete chat"}
            </button>
          </div>
        </Dialog>
      )}
    </div>
  );
}

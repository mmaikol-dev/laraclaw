import { Head } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';
import {
    Archive,
    Bot,
    Brain,
    CheckCircle2,
    ChevronDown,
    LoaderCircle,
    MessageSquarePlus,
    MessagesSquare,
    MoreHorizontal,
    PenSquare,
    SendHorizontal,
    Square,
    Wrench,
    XCircle,
    Zap,
} from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import AppLayout from '@/layouts/app-layout';
import { index as chatRoute } from '@/routes/chat';
import type { BreadcrumbItem } from '@/types';

type TaskLog = {
    id: number;
    tool_name: string;
    tool_input: Record<string, unknown> | null;
    tool_output: string | null;
    status: string;
    duration_ms: number;
    error_message: string | null;
};

type ChatMessage = {
    id: number;
    role: 'user' | 'assistant' | 'tool' | 'system';
    content: string | null;
    thinking: string | null;
    tool_calls: unknown[] | null;
    tool_name: string | null;
    task_logs: TaskLog[];
    stats: { tokens_per_second: number; duration_ms: number };
    created_at: string | null;
};

type StreamTool = {
    id: number;
    tool_name: string;
    input: Record<string, unknown>;
    output: string | null | undefined;
    status: string;
    duration_ms: number | null;
};

type AgentStreamState = {
    conversation_id: number;
    status: 'idle' | 'queued' | 'thinking' | 'tool_running' | 'responding' | 'completed' | 'error';
    draft: string;
    steps: unknown[];
    tools: StreamTool[];
    message_id: number | null;
    stats: Record<string, unknown> | null;
    updated_at: string | null;
};

type ConversationPayload = {
    id: number;
    title: string;
    model: string;
    is_archived: boolean;
    stream: AgentStreamState;
    messages: ChatMessage[];
};

type ConversationResponse = { data: ConversationPayload };
type StoreConversationResponse = { data: { id: number; title: string; model: string; updated_at: string | null } };

type StreamStatusEvent = { type: 'status'; status: AgentStreamState['status'] | 'queued'; label: string };
type StreamChunkEvent = { type: 'chunk'; content: string };
type StreamToolEvent = { type: 'tool'; tool: StreamTool };
type StreamDoneEvent = { type: 'done'; message_id: number; stats: Record<string, unknown> };
type StreamErrorEvent = { type: 'error'; message: string };
type StreamThinkingChunkEvent = { type: 'thinking_chunk'; content: string };
type StreamThinkingDoneEvent = { type: 'thinking_done'; content: string };
type StreamEvent =
    | StreamStatusEvent
    | StreamChunkEvent
    | StreamToolEvent
    | StreamDoneEvent
    | StreamErrorEvent
    | StreamThinkingChunkEvent
    | StreamThinkingDoneEvent;

type FeedEntry =
    | { id: string; kind: 'tool'; tool: StreamTool }
    | { id: string; kind: 'response'; content: string };

type ConversationSummary = {
    id: number;
    title: string;
    model: string;
    message_count: number;
    updated_at: string | null;
};

type ConversationListResponse = { data: ConversationSummary[] };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chat', href: chatRoute() }];

export default function ChatIndex({ conversationId }: { conversationId: number | null }) {
    const [activeConversationId, setActiveConversationId] = useState<number | null>(conversationId);
    const [conversations, setConversations] = useState<ConversationSummary[]>([]);
    const [conversationsLoading, setConversationsLoading] = useState(true);
    const [message, setMessage] = useState('');
    const [conversation, setConversation] = useState<ConversationPayload | null>(null);
    const [streamState, setStreamState] = useState<AgentStreamState | null>(null);
    const [feedEntries, setFeedEntries] = useState<FeedEntry[]>([]);
    const [liveThinking, setLiveThinking] = useState<string>('');
    const [liveThinkingDone, setLiveThinkingDone] = useState(false);
    const [pendingUserMessage, setPendingUserMessage] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [isStreaming, setIsStreaming] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const bottomRef = useRef<HTMLDivElement | null>(null);
    const abortControllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        setActiveConversationId(conversationId);
    }, [conversationId]);

    async function loadConversations(): Promise<void> {
        try {
            const response = await api<ConversationListResponse>('/conversations');
            setConversations(response.data);
        } finally {
            setConversationsLoading(false);
        }
    }

    async function loadConversation(id: number): Promise<void> {
        const response = await api<ConversationResponse>(`/conversations/${id}`);
        setConversation(response.data);
        setStreamState(response.data.stream);
    }

    async function archiveConversation(id: number): Promise<void> {
        await api(`/conversations/${id}`, { method: 'DELETE' });
        setConversations((prev) => prev.filter((c) => c.id !== id));
        if (activeConversationId === id) {
            setActiveConversationId(null);
            setConversation(null);
            setStreamState(null);
            setFeedEntries([]);
            window.history.replaceState({}, '', '/chat');
        }
    }

    useEffect(() => {
        void loadConversations();
    }, []);

    useEffect(() => {
        if (activeConversationId === null) {
            setConversation(null);
            setStreamState(null);
            setFeedEntries([]);
            setLiveThinking('');
            setLiveThinkingDone(false);
            setPendingUserMessage(null);
            return;
        }

        setIsLoading(true);
        setError(null);

        loadConversation(activeConversationId)
            .catch(() => setError('Unable to load that conversation right now.'))
            .finally(() => setIsLoading(false));
    }, [activeConversationId]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [feedEntries, liveThinking, pendingUserMessage, isStreaming]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [conversation]);

    async function createConversation(): Promise<number> {
        const response = await api<StoreConversationResponse>('/conversations', {
            method: 'POST',
            body: JSON.stringify({}),
        });
        return response.data.id;
    }

    function upsertTool(tool: StreamTool): void {
        setFeedEntries((current) => {
            const existingIndex = current.findIndex((e) => e.kind === 'tool' && e.tool.id === tool.id);
            if (existingIndex === -1) {
                return [...current, { id: `tool-${tool.id}`, kind: 'tool', tool }];
            }
            const clone = [...current];
            clone[existingIndex] = { ...clone[existingIndex], kind: 'tool', tool };
            return clone;
        });
    }

    function appendResponseChunk(chunk: string): void {
        setFeedEntries((current) => {
            const last = current.at(-1);
            if (last?.kind === 'response') {
                return [...current.slice(0, -1), { ...last, content: last.content + chunk }];
            }
            return [...current, { id: `response-${crypto.randomUUID()}`, kind: 'response', content: chunk }];
        });
    }

    async function consumeStream(targetId: number, userMessage: string, signal: AbortSignal): Promise<void> {
        const response = await fetch(`/api/v1/conversations/${targetId}/messages/stream`, {
            method: 'POST',
            headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: userMessage }),
            signal,
        });

        if (!response.ok || response.body === null) {
            throw new Error('Streaming request failed.');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        const processEvent = (block: string): void => {
            const lines = block.split('\n').filter(Boolean);
            const eventLine = lines.find((l) => l.startsWith('event:'));
            const dataLine = lines.find((l) => l.startsWith('data:'));

            if (eventLine === undefined || dataLine === undefined) {
                return;
            }

            const eventName = eventLine.replace(/^event:\s*/, '').trim();
            const payload = JSON.parse(dataLine.replace(/^data:\s*/, '')) as StreamEvent;

            if (eventName === 'status' && payload.type === 'status') {
                setStreamState((current) => ({
                    conversation_id: targetId,
                    status: payload.status === 'queued' ? 'queued' : payload.status,
                    draft: current?.draft ?? '',
                    steps: current?.steps ?? [],
                    tools: current?.tools ?? [],
                    message_id: current?.message_id ?? null,
                    stats: current?.stats ?? null,
                    updated_at: new Date().toISOString(),
                }));
                return;
            }

            if (eventName === 'thinking_chunk' && payload.type === 'thinking_chunk') {
                setLiveThinking((t) => t + payload.content);
                return;
            }

            if (eventName === 'thinking_done' && payload.type === 'thinking_done') {
                setLiveThinking(payload.content);
                setLiveThinkingDone(true);
                return;
            }

            if (eventName === 'chunk' && payload.type === 'chunk') {
                setStreamState((current) => ({
                    conversation_id: targetId,
                    status: 'responding',
                    draft: `${current?.draft ?? ''}${payload.content}`,
                    steps: current?.steps ?? [],
                    tools: current?.tools ?? [],
                    message_id: current?.message_id ?? null,
                    stats: current?.stats ?? null,
                    updated_at: new Date().toISOString(),
                }));
                appendResponseChunk(payload.content);
                return;
            }

            if (eventName === 'tool' && payload.type === 'tool') {
                setStreamState((current) => ({
                    conversation_id: targetId,
                    status: payload.tool.status === 'running' ? 'tool_running' : 'thinking',
                    draft: current?.draft ?? '',
                    steps: current?.steps ?? [],
                    tools: upsertStreamTools(current?.tools ?? [], payload.tool),
                    message_id: current?.message_id ?? null,
                    stats: current?.stats ?? null,
                    updated_at: new Date().toISOString(),
                }));
                upsertTool(payload.tool);
                return;
            }

            if (eventName === 'error' && payload.type === 'error') {
                setError(payload.message);
                setStreamState((current) => ({
                    conversation_id: targetId,
                    status: 'error',
                    draft: current?.draft ?? '',
                    steps: current?.steps ?? [],
                    tools: current?.tools ?? [],
                    message_id: current?.message_id ?? null,
                    stats: current?.stats ?? null,
                    updated_at: new Date().toISOString(),
                }));
                return;
            }

            if (eventName === 'done' && payload.type === 'done') {
                setStreamState((current) => ({
                    conversation_id: targetId,
                    status: 'completed',
                    draft: current?.draft ?? '',
                    steps: current?.steps ?? [],
                    tools: current?.tools ?? [],
                    message_id: payload.message_id,
                    stats: payload.stats,
                    updated_at: new Date().toISOString(),
                }));
            }
        };

        while (true) {
            const { value, done } = await reader.read();
            if (done) {
                break;
            }
            buffer += decoder.decode(value, { stream: true });
            let boundary = buffer.indexOf('\n\n');
            while (boundary !== -1) {
                const block = buffer.slice(0, boundary).trim();
                buffer = buffer.slice(boundary + 2);
                if (block !== '') {
                    processEvent(block);
                }
                boundary = buffer.indexOf('\n\n');
            }
        }
    }

    function handleStop(): void {
        if (activeConversationId !== null) {
            void api(`/conversations/${activeConversationId}/stream`, { method: 'DELETE' }).catch(() => {});
        }
        abortControllerRef.current?.abort();
    }

    async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        const trimmedMessage = message.trim();
        if (trimmedMessage === '') {
            return;
        }

        const controller = new AbortController();
        abortControllerRef.current = controller;

        setIsSending(true);
        setIsStreaming(true);
        setError(null);
        setFeedEntries([]);
        setLiveThinking('');
        setLiveThinkingDone(false);
        setPendingUserMessage(trimmedMessage);
        setMessage('');

        try {
            let targetId = activeConversationId;
            if (targetId === null) {
                targetId = await createConversation();
                setActiveConversationId(targetId);
                window.history.replaceState({}, '', `/chat/${targetId}`);
                void loadConversations();
            }

            await loadConversation(targetId);
            await consumeStream(targetId, trimmedMessage, controller.signal);
            setPendingUserMessage(null);
            await Promise.all([loadConversation(targetId), loadConversations()]);
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') {
                setPendingUserMessage(null);
                setFeedEntries([]);
                setLiveThinking('');
                setLiveThinkingDone(false);
                if (activeConversationId !== null) {
                    const id = activeConversationId;
                    setTimeout(() => void loadConversation(id), 300);
                }
            } else {
                setError('Your message could not be sent.');
            }
        } finally {
            abortControllerRef.current = null;
            setIsSending(false);
            setIsStreaming(false);
        }
    }

    function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>): void {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            event.currentTarget.form?.requestSubmit();
        }
    }

    const visibleMessages = conversation?.messages.filter(
        (m) => m.role === 'user' || (m.role === 'assistant' && m.content !== null && m.content !== ''),
    ) ?? [];

    function handleNewChat(): void {
        setActiveConversationId(null);
        window.history.replaceState({}, '', '/chat');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={conversation?.title ?? 'Chat'} />

            <div className="flex min-h-0 flex-1 overflow-hidden">
                {/* ── Conversations sidebar ── */}
                <aside className="flex w-64 shrink-0 flex-col border-r bg-sidebar">
                    <div className="flex items-center justify-between border-b px-3 py-2.5">
                        <span className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <MessagesSquare className="size-3.5 text-teal-500" />
                            Conversations
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-6 text-muted-foreground hover:text-foreground"
                            onClick={handleNewChat}
                            title="New conversation"
                        >
                            <PenSquare className="size-3.5" />
                        </Button>
                    </div>

                    <div className="flex-1 overflow-y-auto py-1">
                        {conversationsLoading ? (
                            <div className="space-y-1 px-2 py-2">
                                {[...Array(5)].map((_, i) => (
                                    <Skeleton key={i} className="h-8 w-full rounded-md" />
                                ))}
                            </div>
                        ) : conversations.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-3 py-8 text-center">
                                <MessagesSquare className="size-8 text-muted-foreground/30" />
                                <p className="text-xs text-muted-foreground">No conversations yet</p>
                            </div>
                        ) : (
                            <ul className="space-y-px px-2 py-1">
                                {conversations.map((conv) => (
                                    <ConversationItem
                                        key={conv.id}
                                        conversation={conv}
                                        isActive={activeConversationId === conv.id}
                                        onSelect={() => {
                                            setActiveConversationId(conv.id);
                                            window.history.replaceState({}, '', `/chat/${conv.id}`);
                                        }}
                                        onArchive={() => void archiveConversation(conv.id)}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                </aside>

                {/* ── Chat panel ── */}
                <div className="relative flex min-w-0 flex-1 flex-col">
                    {/* Scrollable messages */}
                    <div className="flex-1 overflow-y-auto">
                        <div className="mx-auto max-w-3xl space-y-6 px-4 pb-2 pt-6">
                            {isLoading ? (
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <LoaderCircle className="size-4 animate-spin" />
                                    Loading conversation…
                                </div>
                            ) : conversation === null && !isStreaming ? (
                                <div className="flex min-h-[50vh] flex-col items-center justify-center gap-4 text-center">
                                    <div className="rounded-2xl bg-teal-50 p-5 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400">
                                        <MessageSquarePlus className="size-10" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <h2 className="text-lg font-semibold">Start a conversation</h2>
                                        <p className="max-w-sm text-sm text-muted-foreground">
                                            Ask LaraClaw anything — it can read files, run shell commands, and search the web.
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    {visibleMessages.map((msg) => (
                                        <HistoryMessage key={msg.id} message={msg} />
                                    ))}

                                    {isStreaming && (
                                        <LiveStreamArea
                                            pendingUserMessage={pendingUserMessage}
                                            streamState={streamState}
                                            feedEntries={feedEntries}
                                            liveThinking={liveThinking}
                                            liveThinkingDone={liveThinkingDone}
                                        />
                                    )}
                                </>
                            )}
                            <div ref={bottomRef} />
                        </div>
                    </div>

                    {/* Floating input — pinned to bottom, never scrolls */}
                    <div className="shrink-0 px-4 pb-4 pt-2">
                        <div className="mx-auto max-w-3xl">
                            <form onSubmit={handleSubmit}>
                                <div className="rounded-2xl border bg-card shadow-lg ring-1 ring-border/50 focus-within:ring-2 focus-within:ring-teal-500/40 dark:shadow-black/30">
                                    <textarea
                                        value={message}
                                        onChange={(e) => setMessage(e.target.value)}
                                        onKeyDown={handleKeyDown}
                                        rows={3}
                                        placeholder="Message LaraClaw…"
                                        className="w-full resize-none rounded-t-2xl border-0 bg-transparent px-4 pt-3.5 text-sm outline-none placeholder:text-muted-foreground disabled:opacity-50"
                                        disabled={isSending}
                                    />
                                    <div className="flex items-center justify-between px-3 pb-2.5 pt-1">
                                        <p className="text-[11px] text-muted-foreground/60">⏎ send · ⇧⏎ newline</p>
                                        {isStreaming ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={handleStop}
                                                className="h-8 gap-1.5 rounded-xl bg-red-600 px-3 text-xs hover:bg-red-700"
                                            >
                                                <Square className="size-3 fill-current" />
                                                Stop
                                            </Button>
                                        ) : (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                className="h-8 gap-1.5 rounded-xl bg-teal-600 px-3 text-xs hover:bg-teal-700"
                                                disabled={isSending || message.trim() === ''}
                                            >
                                                <SendHorizontal className="size-3.5" />
                                                Send
                                            </Button>
                                        )}
                                    </div>
                                </div>
                                {error !== null && (
                                    <p className="mt-1.5 text-xs text-destructive">{error}</p>
                                )}
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

// ── History message ─────────────────────────────────────────────────────────

function HistoryMessage({ message }: { message: ChatMessage }) {
    const isUser = message.role === 'user';
    const hasThinking = message.thinking !== null && message.thinking !== '';
    const hasTaskLogs = message.task_logs.length > 0;

    return (
        <div className={`flex gap-3 ${isUser ? 'justify-end' : 'justify-start'}`}>
            {!isUser && (
                <div className="mt-0.5 shrink-0">
                    <div className="flex size-8 items-center justify-center rounded-xl bg-teal-600 text-white shadow-sm">
                        <Bot className="size-4" />
                    </div>
                </div>
            )}

            <div className={`flex flex-col gap-2 ${isUser ? 'items-end max-w-[78%]' : 'items-start max-w-[85%]'}`}>
                {/* Thinking trace — above the bubble */}
                {!isUser && (hasThinking || hasTaskLogs) && (
                    <ThinkingTrace thinking={message.thinking} taskLogs={message.task_logs} />
                )}

                <div
                    className={`rounded-2xl px-4 py-3 text-sm shadow-sm ${
                        isUser
                            ? 'rounded-tr-sm bg-teal-600 text-white'
                            : 'rounded-tl-sm border bg-card text-foreground'
                    }`}
                >
                    {isUser ? (
                        <p className="whitespace-pre-wrap leading-relaxed">{message.content}</p>
                    ) : (
                        <Markdown>{message.content ?? ''}</Markdown>
                    )}

                    {message.stats.duration_ms > 0 && !isUser && (
                        <p className="mt-2 border-t pt-1.5 text-[10px] text-muted-foreground/50">
                            {message.stats.tokens_per_second > 0 ? `${message.stats.tokens_per_second} tok/s · ` : ''}
                            {message.stats.duration_ms}ms
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

// ── Thinking trace (history) ─────────────────────────────────────────────────

function ThinkingTrace({ thinking, taskLogs }: { thinking: string | null; taskLogs: TaskLog[] }) {
    const hasThinking = thinking !== null && thinking !== '';
    const hasLogs = taskLogs.length > 0;
    const label = hasThinking
        ? `Thought process · ${taskLogs.length > 0 ? `${taskLogs.length} tool${taskLogs.length !== 1 ? 's' : ''}` : 'no tools'}`
        : `Used ${taskLogs.length} tool${taskLogs.length !== 1 ? 's' : ''}`;

    return (
        <Collapsible>
            <CollapsibleTrigger className="flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground">
                <Brain className="size-3.5 text-violet-400" />
                <span>{label}</span>
                <ChevronDown className="size-3 transition-transform [[data-state=open]_&]:rotate-180" />
            </CollapsibleTrigger>

            <CollapsibleContent>
                <div className="mt-1 overflow-hidden rounded-xl border bg-muted/20 text-xs">
                    {/* <think> content */}
                    {hasThinking && (
                        <div className="border-b px-3 py-2.5">
                            <p className="mb-1.5 font-medium uppercase tracking-wide text-violet-500 opacity-70">
                                Internal reasoning
                            </p>
                            <div className="italic text-muted-foreground">
                                <Markdown>{thinking ?? ''}</Markdown>
                            </div>
                        </div>
                    )}

                    {/* Tool call logs */}
                    {hasLogs && (
                        <div className="divide-y">
                            {taskLogs.map((log) => (
                                <HistoryToolRow key={log.id} log={log} />
                            ))}
                        </div>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

function HistoryToolRow({ log }: { log: TaskLog }) {
    const isSuccess = log.status === 'success';
    const isError = log.status === 'error';
    const isSkill = log.tool_name === 'skill';

    const skillAction = isSkill ? (log.tool_input?.action as string | undefined) : undefined;
    const skillName = isSkill ? (log.tool_input?.name as string | undefined) : undefined;

    const skillLabel: Record<string, string> = {
        read: 'Loaded skill',
        create: 'Created skill',
        update: 'Updated skill',
        delete: 'Deleted skill',
        list: 'Listed skills',
    };

    return (
        <Collapsible>
            <CollapsibleTrigger className={`flex w-full items-center gap-2 px-3 py-2 text-left ${isSkill ? 'hover:bg-amber-50/60 dark:hover:bg-amber-950/20' : 'hover:bg-muted/40'}`}>
                {isSkill ? (
                    <Zap className={`size-3.5 shrink-0 ${isError ? 'text-destructive' : 'text-amber-500'}`} />
                ) : (
                    <>
                        {isSuccess && <CheckCircle2 className="size-3.5 shrink-0 text-teal-500" />}
                        {isError && <XCircle className="size-3.5 shrink-0 text-destructive" />}
                        {!isSuccess && !isError && <Wrench className="size-3.5 shrink-0 text-muted-foreground" />}
                    </>
                )}

                {isSkill ? (
                    <span className="grow truncate">
                        <span className="font-medium text-amber-700 dark:text-amber-300">
                            {skillAction !== undefined ? (skillLabel[skillAction] ?? 'Skill') : 'Skill'}
                        </span>
                        {skillName !== undefined && (
                            <span className="ml-1.5 font-mono text-[11px] text-amber-600/70 dark:text-amber-400/70">· {skillName}</span>
                        )}
                    </span>
                ) : (
                    <span className="grow truncate font-mono text-foreground">{log.tool_name}</span>
                )}

                {log.duration_ms > 0 && (
                    <span className="shrink-0 text-muted-foreground">{log.duration_ms}ms</span>
                )}
                <Badge
                    variant={isError ? 'destructive' : isSuccess ? 'default' : 'secondary'}
                    className={`shrink-0 text-[10px] ${isSkill && isSuccess ? 'bg-amber-500' : ''}`}
                >
                    {log.status}
                </Badge>
                <ChevronDown className="size-3 shrink-0 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className={`border-t px-3 py-2 font-mono ${isSkill ? 'bg-amber-50/30 dark:bg-amber-950/10' : 'bg-background/60'}`}>
                    {log.tool_input !== null && (
                        <>
                            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Input</p>
                            <pre className="overflow-x-auto whitespace-pre-wrap break-all text-foreground">
                                {JSON.stringify(log.tool_input, null, 2)}
                            </pre>
                        </>
                    )}
                    {log.tool_output !== null && (
                        <>
                            <p className="mb-1 mt-2 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Output</p>
                            <pre className="overflow-x-auto whitespace-pre-wrap break-all text-muted-foreground">
                                {log.tool_output.length > 800 ? `${log.tool_output.slice(0, 800)}…` : log.tool_output}
                            </pre>
                        </>
                    )}
                    {log.error_message !== null && (
                        <p className="mt-1 text-destructive">{log.error_message}</p>
                    )}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

// ── Live stream area ─────────────────────────────────────────────────────────

function LiveStreamArea({
    pendingUserMessage,
    streamState,
    feedEntries,
    liveThinking,
    liveThinkingDone,
}: {
    pendingUserMessage: string | null;
    streamState: AgentStreamState | null;
    feedEntries: FeedEntry[];
    liveThinking: string;
    liveThinkingDone: boolean;
}) {
    const status = streamState?.status ?? 'queued';
    const isThinking = status === 'queued' || status === 'thinking';
    const hasResponse = feedEntries.some((e) => e.kind === 'response');
    const showThinkingPulse = isThinking && !hasResponse && liveThinking === '';

    return (
        <div className="space-y-3">
            {/* Optimistic user message */}
            {pendingUserMessage !== null && (
                <div className="flex justify-end">
                    <div className="max-w-[80%] rounded-2xl bg-teal-600 px-4 py-3 text-sm leading-relaxed text-white">
                        <p className="whitespace-pre-wrap">{pendingUserMessage}</p>
                    </div>
                </div>
            )}

            {/* Agent response area */}
            <div className="flex justify-start gap-2">
                <div className="mt-1 shrink-0">
                    <div className="flex size-7 items-center justify-center rounded-full bg-teal-600 text-white">
                        <Bot className="size-4" />
                    </div>
                </div>

                <div className="min-w-0 flex-1 space-y-2">
                    {/* Live thinking block — visible while the model reasons */}
                    {liveThinking !== '' && (
                        <Collapsible defaultOpen={!liveThinkingDone}>
                            <div className="overflow-hidden rounded-xl border border-violet-200 bg-violet-50 text-xs dark:border-violet-800 dark:bg-violet-950/30">
                                <CollapsibleTrigger className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-violet-100/50 dark:hover:bg-violet-900/20">
                                    <Brain className={`size-3.5 text-violet-500 ${!liveThinkingDone ? 'animate-pulse' : ''}`} />
                                    <span className="grow font-medium text-violet-700 dark:text-violet-300">
                                        {liveThinkingDone ? 'Thought process' : 'Thinking…'}
                                    </span>
                                    <ChevronDown className="size-3 text-violet-400 transition-transform [[data-state=open]_&]:rotate-180" />
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <div className="border-t border-violet-200 px-3 py-2 dark:border-violet-800">
                                        <div className="italic text-violet-800 dark:text-violet-300">
                                            <Markdown>{liveThinking}</Markdown>
                                            {!liveThinkingDone && (
                                                <span className="inline-block h-3 w-1 animate-pulse rounded-sm bg-violet-400 align-middle" />
                                            )}
                                        </div>
                                    </div>
                                </CollapsibleContent>
                            </div>
                        </Collapsible>
                    )}

                    {/* Tool call cards */}
                    {feedEntries
                        .filter((e): e is Extract<FeedEntry, { kind: 'tool' }> => e.kind === 'tool')
                        .map((entry) => (
                            <ToolCard key={entry.id} tool={entry.tool} />
                        ))}

                    {/* Thinking pulse — shown before any output */}
                    {showThinkingPulse && (
                        <div className="flex items-center gap-2 rounded-xl border border-dashed bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                            <span className="relative flex size-2">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-teal-400 opacity-75" />
                                <span className="relative inline-flex size-2 rounded-full bg-teal-500" />
                            </span>
                            {status === 'queued' ? 'Queued…' : 'Thinking…'}
                        </div>
                    )}

                    {/* Streaming response bubble */}
                    {feedEntries
                        .filter((e): e is Extract<FeedEntry, { kind: 'response' }> => e.kind === 'response')
                        .map((entry) => (
                            <div
                                key={entry.id}
                                className="rounded-2xl rounded-tl-sm border bg-card px-4 py-3 text-sm text-foreground shadow-sm"
                            >
                                <Markdown>{entry.content}</Markdown>
                                <span className="inline-block h-4 w-1 animate-pulse rounded-sm bg-teal-500 align-middle" />
                            </div>
                        ))}
                </div>
            </div>
        </div>
    );
}

// ── Tool card (live) ─────────────────────────────────────────────────────────

function ToolCard({ tool }: { tool: StreamTool }) {
    const isSkill = tool.tool_name === 'skill';
    const isRunning = tool.status === 'running';
    const isError = tool.status === 'error';
    const isSuccess = tool.status === 'success';

    const skillAction = isSkill ? (tool.input.action as string | undefined) : undefined;
    const skillName = isSkill ? (tool.input.name as string | undefined) : undefined;

    const skillLabel: Record<string, string> = {
        read: 'Loading skill',
        create: 'Creating skill',
        update: 'Updating skill',
        delete: 'Deleting skill',
        list: 'Listing skills',
    };

    return (
        <Collapsible>
            <div className={`overflow-hidden rounded-xl border text-xs ${isSkill ? 'border-amber-200 bg-amber-50/50 dark:border-amber-800/60 dark:bg-amber-950/20' : 'bg-background'}`}>
                <CollapsibleTrigger className={`flex w-full items-center gap-2 px-3 py-2 text-left ${isSkill ? 'hover:bg-amber-100/50 dark:hover:bg-amber-900/20' : 'hover:bg-muted/50'}`}>
                    <span className="shrink-0">
                        {isRunning && isSkill && <Zap className="size-3.5 animate-pulse text-amber-500" />}
                        {isRunning && !isSkill && <LoaderCircle className="size-3.5 animate-spin text-amber-500" />}
                        {isSuccess && isSkill && <Zap className="size-3.5 text-amber-500" />}
                        {isSuccess && !isSkill && <CheckCircle2 className="size-3.5 text-teal-500" />}
                        {isError && <XCircle className="size-3.5 text-destructive" />}
                        {!isRunning && !isSuccess && !isError && !isSkill && <Wrench className="size-3.5 text-muted-foreground" />}
                        {!isRunning && !isSuccess && !isError && isSkill && <Zap className="size-3.5 text-amber-400" />}
                    </span>

                    {isSkill ? (
                        <span className="grow truncate">
                            <span className="font-medium text-amber-700 dark:text-amber-300">
                                {skillAction !== undefined ? (skillLabel[skillAction] ?? 'Skill') : 'Skill'}
                            </span>
                            {skillName !== undefined && (
                                <span className="ml-1.5 font-mono text-amber-600/80 dark:text-amber-400/80">· {skillName}</span>
                            )}
                        </span>
                    ) : (
                        <span className="grow truncate font-mono text-foreground">{tool.tool_name}</span>
                    )}

                    {tool.duration_ms !== null && (
                        <span className="shrink-0 text-muted-foreground">{tool.duration_ms}ms</span>
                    )}
                    <Badge
                        variant={isError ? 'destructive' : isSuccess ? 'default' : 'secondary'}
                        className={`shrink-0 text-[10px] ${isSkill && isSuccess ? 'bg-amber-500' : ''}`}
                    >
                        {tool.status}
                    </Badge>
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div className={`border-t px-3 py-2 font-mono ${isSkill ? 'border-amber-200 bg-amber-50/30 dark:border-amber-800/40 dark:bg-amber-950/10' : 'bg-muted/30'}`}>
                        <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Input</p>
                        <pre className="overflow-x-auto whitespace-pre-wrap break-all text-foreground">
                            {JSON.stringify(tool.input, null, 2)}
                        </pre>
                        {tool.output != null && (
                            <>
                                <p className="mb-1 mt-2 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Output</p>
                                <pre className="overflow-x-auto whitespace-pre-wrap break-all text-muted-foreground">
                                    {tool.output.length > 500 ? `${tool.output.slice(0, 500)}…` : tool.output}
                                </pre>
                            </>
                        )}
                    </div>
                </CollapsibleContent>
            </div>
        </Collapsible>
    );
}

// ── Markdown renderer ────────────────────────────────────────────────────────

function Markdown({ children, dim = false }: { children: string; dim?: boolean }) {
    return (
        <div className="markdown-body text-sm leading-relaxed">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                components={{
                    p: ({ children: c }) => <p className="mb-3 last:mb-0 leading-7">{c}</p>,

                    h1: ({ children: c }) => (
                        <h1 className="mb-3 mt-6 border-b pb-1.5 text-xl font-bold tracking-tight first:mt-0">
                            {c}
                        </h1>
                    ),
                    h2: ({ children: c }) => (
                        <h2 className="mb-2.5 mt-5 text-base font-semibold tracking-tight first:mt-0">{c}</h2>
                    ),
                    h3: ({ children: c }) => (
                        <h3 className="mb-2 mt-4 text-sm font-semibold first:mt-0">{c}</h3>
                    ),
                    h4: ({ children: c }) => (
                        <h4 className="mb-1.5 mt-3 text-sm font-medium first:mt-0">{c}</h4>
                    ),

                    ul: ({ children: c }) => (
                        <ul className="mb-3 space-y-1 pl-5 [&>li]:relative [&>li]:before:absolute [&>li]:before:-left-4 [&>li]:before:top-[0.4em] [&>li]:before:size-1.5 [&>li]:before:rounded-full [&>li]:before:bg-teal-500/70 [&>li]:before:content-['']">
                            {c}
                        </ul>
                    ),
                    ol: ({ children: c }) => (
                        <ol className="mb-3 list-decimal space-y-1 pl-5 marker:text-teal-600/70 marker:text-xs">
                            {c}
                        </ol>
                    ),
                    li: ({ children: c }) => <li className="leading-relaxed">{c}</li>,

                    strong: ({ children: c }) => <strong className="font-semibold">{c}</strong>,
                    em: ({ children: c }) => <em className="italic opacity-90">{c}</em>,

                    code: ({ children: c, className }) => {
                        const isBlock = className?.includes('language-');
                        const lang = className?.replace('language-', '') ?? '';
                        return isBlock ? (
                            <code className="block font-mono text-xs leading-relaxed" data-lang={lang}>
                                {c}
                            </code>
                        ) : (
                            <code
                                className={`rounded-md px-1.5 py-0.5 font-mono text-[0.8em] ${
                                    dim
                                        ? 'bg-white/15 text-white/90'
                                        : 'bg-teal-500/10 text-teal-700 dark:text-teal-300'
                                }`}
                            >
                                {c}
                            </code>
                        );
                    },

                    pre: ({ children: c, ...rest }) => {
                        const codeEl = (rest as { node?: { children?: Array<{ properties?: { className?: string[] } }> } }).node?.children?.[0];
                        const lang = (codeEl?.properties?.className ?? []).find((cl: string) => cl.startsWith('language-'))?.replace('language-', '') ?? '';
                        return (
                            <div className="group relative mb-3">
                                {lang !== '' && (
                                    <span className="absolute right-2.5 top-2 font-mono text-[10px] uppercase tracking-widest text-muted-foreground/50">
                                        {lang}
                                    </span>
                                )}
                                <pre
                                    className={`overflow-x-auto rounded-xl p-4 font-mono text-xs leading-relaxed ${
                                        dim
                                            ? 'bg-white/10 text-white/80'
                                            : 'bg-zinc-950 text-zinc-100 dark:bg-zinc-900'
                                    }`}
                                >
                                    {c}
                                </pre>
                            </div>
                        );
                    },

                    blockquote: ({ children: c }) => (
                        <blockquote
                            className={`mb-3 rounded-r-lg border-l-2 pl-4 italic ${
                                dim
                                    ? 'border-white/30 text-white/70'
                                    : 'border-teal-500/50 bg-teal-500/5 py-1 pr-3 text-muted-foreground'
                            }`}
                        >
                            {c}
                        </blockquote>
                    ),

                    a: ({ children: c, href }) => (
                        <a
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-teal-600 underline underline-offset-2 hover:text-teal-500 dark:text-teal-400"
                        >
                            {c}
                        </a>
                    ),

                    hr: () => <hr className="my-4 border-border/50" />,

                    table: ({ children: c }) => (
                        <div className="mb-3 overflow-x-auto rounded-xl border">
                            <table className="w-full border-collapse text-xs">{c}</table>
                        </div>
                    ),
                    thead: ({ children: c }) => <thead className="bg-muted/60">{c}</thead>,
                    th: ({ children: c }) => (
                        <th className="border-b px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {c}
                        </th>
                    ),
                    td: ({ children: c }) => (
                        <td className="border-b border-border/40 px-3 py-2 last:border-b-0">{c}</td>
                    ),
                    tr: ({ children: c }) => (
                        <tr className="transition-colors hover:bg-muted/30">{c}</tr>
                    ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}

// ── Conversation list item ────────────────────────────────────────────────────

function ConversationItem({
    conversation,
    isActive,
    onSelect,
    onArchive,
}: {
    conversation: ConversationSummary;
    isActive: boolean;
    onSelect: () => void;
    onArchive: () => void;
}) {
    return (
        <li className="group relative flex items-center">
            <button
                onClick={onSelect}
                className={`flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-2 text-left text-sm transition-colors ${
                    isActive
                        ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300'
                        : 'text-foreground hover:bg-muted/60'
                }`}
            >
                <MessagesSquare
                    className={`size-3.5 shrink-0 ${isActive ? 'text-teal-500' : 'text-muted-foreground'}`}
                />
                <span className="truncate">{conversation.title}</span>
            </button>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button className="absolute right-1 shrink-0 rounded p-1 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover:opacity-100 focus-visible:opacity-100">
                        <MoreHorizontal className="size-3.5" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-36">
                    <DropdownMenuItem onClick={onArchive} className="gap-2 text-destructive focus:text-destructive">
                        <Archive className="size-3.5" />
                        Archive
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </li>
    );
}

function upsertStreamTools(current: StreamTool[], tool: StreamTool): StreamTool[] {
    const idx = current.findIndex((t) => t.id === tool.id);
    if (idx === -1) {
        return [...current, tool];
    }
    const clone = [...current];
    clone[idx] = { ...clone[idx], ...tool };
    return clone;
}

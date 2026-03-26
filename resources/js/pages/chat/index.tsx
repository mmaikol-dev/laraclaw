import { Head } from '@inertiajs/react';
import { FormEvent, KeyboardEvent, useEffect, useRef, useState } from 'react';
import {
    Bot,
    Brain,
    CheckCircle2,
    ChevronDown,
    LoaderCircle,
    MessageSquarePlus,
    SendHorizontal,
    Wrench,
    XCircle,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Chat', href: chatRoute() }];

export default function ChatIndex({ conversationId }: { conversationId: number | null }) {
    const [activeConversationId, setActiveConversationId] = useState<number | null>(conversationId);
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

    useEffect(() => {
        setActiveConversationId(conversationId);
    }, [conversationId]);

    async function loadConversation(id: number): Promise<void> {
        const response = await api<ConversationResponse>(`/conversations/${id}`);
        setConversation(response.data);
        setStreamState(response.data.stream);
    }

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

    async function consumeStream(targetId: number, userMessage: string): Promise<void> {
        const response = await fetch(`/api/v1/conversations/${targetId}/messages/stream`, {
            method: 'POST',
            headers: { Accept: 'text/event-stream', 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: userMessage }),
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

    async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        const trimmedMessage = message.trim();
        if (trimmedMessage === '') {
            return;
        }

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
            }

            await loadConversation(targetId);
            await consumeStream(targetId, trimmedMessage);
            setPendingUserMessage(null);
            await loadConversation(targetId);
        } catch {
            setError('Your message could not be sent.');
        } finally {
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={conversation?.title ?? 'Chat'} />

            <div className="flex h-full flex-col">
                <div className="flex-1 overflow-y-auto">
                    <div className="mx-auto max-w-3xl space-y-4 px-4 py-6">
                        {isLoading ? (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <LoaderCircle className="size-4 animate-spin" />
                                Loading conversation...
                            </div>
                        ) : conversation === null && !isStreaming ? (
                            <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center">
                                <div className="rounded-full bg-teal-100 p-4 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                                    <MessageSquarePlus className="size-8" />
                                </div>
                                <div className="space-y-1">
                                    <h2 className="text-xl font-semibold">Start a conversation</h2>
                                    <p className="text-sm text-muted-foreground">
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

                <div className="border-t bg-background px-4 py-4">
                    <div className="mx-auto max-w-3xl">
                        <form onSubmit={handleSubmit}>
                            <div className="rounded-2xl border bg-card shadow-sm focus-within:ring-2 focus-within:ring-teal-500/30">
                                <textarea
                                    value={message}
                                    onChange={(e) => setMessage(e.target.value)}
                                    onKeyDown={handleKeyDown}
                                    rows={3}
                                    placeholder="Message LaraClaw..."
                                    className="w-full resize-none rounded-t-2xl border-0 bg-transparent px-4 pt-4 text-sm outline-none placeholder:text-muted-foreground disabled:opacity-50"
                                    disabled={isSending}
                                />
                                <div className="flex items-center justify-between px-4 pb-3">
                                    <p className="text-xs text-muted-foreground">Enter to send · Shift+Enter for newline</p>
                                    <Button type="submit" size="sm" disabled={isSending || message.trim() === ''}>
                                        {isSending ? (
                                            <LoaderCircle className="size-4 animate-spin" />
                                        ) : (
                                            <SendHorizontal className="size-4" />
                                        )}
                                        Send
                                    </Button>
                                </div>
                            </div>
                            {error !== null && <p className="mt-2 text-sm text-destructive">{error}</p>}
                        </form>
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
        <div className={`flex ${isUser ? 'justify-end' : 'justify-start'}`}>
            {!isUser && (
                <div className="mr-2 mt-1 shrink-0">
                    <div className="flex size-7 items-center justify-center rounded-full bg-teal-600 text-white">
                        <Bot className="size-4" />
                    </div>
                </div>
            )}

            <div className="flex max-w-[80%] flex-col gap-1.5">
                {/* Thinking trace (clickable) — shown above the message bubble */}
                {!isUser && (hasThinking || hasTaskLogs) && (
                    <ThinkingTrace thinking={message.thinking} taskLogs={message.task_logs} />
                )}

                <div
                    className={`rounded-2xl px-4 py-3 text-sm leading-relaxed ${
                        isUser ? 'bg-teal-600 text-white' : 'bg-muted text-foreground'
                    }`}
                >
                    <p className="whitespace-pre-wrap">{message.content}</p>
                    {message.stats.duration_ms > 0 && !isUser && (
                        <p className="mt-1.5 text-[11px] opacity-50">
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
                            <p className="whitespace-pre-wrap leading-relaxed text-muted-foreground italic">
                                {thinking}
                            </p>
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

    return (
        <Collapsible>
            <CollapsibleTrigger className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted/40">
                {isSuccess && <CheckCircle2 className="size-3.5 shrink-0 text-teal-500" />}
                {isError && <XCircle className="size-3.5 shrink-0 text-destructive" />}
                {!isSuccess && !isError && <Wrench className="size-3.5 shrink-0 text-muted-foreground" />}
                <span className="grow truncate font-mono text-foreground">{log.tool_name}</span>
                {log.duration_ms > 0 && (
                    <span className="shrink-0 text-muted-foreground">{log.duration_ms}ms</span>
                )}
                <Badge variant={isError ? 'destructive' : isSuccess ? 'default' : 'secondary'} className="shrink-0 text-[10px]">
                    {log.status}
                </Badge>
                <ChevronDown className="size-3 shrink-0 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className="border-t bg-background/60 px-3 py-2 font-mono">
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
                                        <p className="whitespace-pre-wrap italic leading-relaxed text-violet-800 dark:text-violet-300">
                                            {liveThinking}
                                            {!liveThinkingDone && (
                                                <span className="ml-0.5 inline-block h-3 w-1 animate-pulse rounded-sm bg-violet-400 align-middle" />
                                            )}
                                        </p>
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
                                className="rounded-2xl bg-muted px-4 py-3 text-sm leading-relaxed text-foreground"
                            >
                                <p className="whitespace-pre-wrap">
                                    {entry.content}
                                    <span className="ml-0.5 inline-block h-4 w-1.5 animate-pulse rounded-sm bg-teal-500 align-middle" />
                                </p>
                            </div>
                        ))}
                </div>
            </div>
        </div>
    );
}

// ── Tool card (live) ─────────────────────────────────────────────────────────

function ToolCard({ tool }: { tool: StreamTool }) {
    const isRunning = tool.status === 'running';
    const isError = tool.status === 'error';
    const isSuccess = tool.status === 'success';

    return (
        <Collapsible>
            <div className="overflow-hidden rounded-xl border bg-background text-xs">
                <CollapsibleTrigger className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted/50">
                    <span className="shrink-0">
                        {isRunning && <LoaderCircle className="size-3.5 animate-spin text-amber-500" />}
                        {isSuccess && <CheckCircle2 className="size-3.5 text-teal-500" />}
                        {isError && <XCircle className="size-3.5 text-destructive" />}
                        {!isRunning && !isSuccess && !isError && <Wrench className="size-3.5 text-muted-foreground" />}
                    </span>
                    <span className="grow truncate font-mono text-foreground">{tool.tool_name}</span>
                    {tool.duration_ms !== null && (
                        <span className="shrink-0 text-muted-foreground">{tool.duration_ms}ms</span>
                    )}
                    <Badge variant={isError ? 'destructive' : isSuccess ? 'default' : 'secondary'} className="shrink-0 text-[10px]">
                        {tool.status}
                    </Badge>
                    <ChevronDown className="size-3.5 shrink-0 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <div className="border-t bg-muted/30 px-3 py-2 font-mono">
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

function upsertStreamTools(current: StreamTool[], tool: StreamTool): StreamTool[] {
    const idx = current.findIndex((t) => t.id === tool.id);
    if (idx === -1) {
        return [...current, tool];
    }
    const clone = [...current];
    clone[idx] = { ...clone[idx], ...tool };
    return clone;
}

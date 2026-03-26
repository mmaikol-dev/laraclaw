import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { LoaderCircle, MessageSquareText, RefreshCcw } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { api } from '@/lib/api';
import AppLayout from '@/layouts/app-layout';
import { show as showChat } from '@/routes/chat';
import { index as tasks } from '@/routes/tasks';
import type { BreadcrumbItem } from '@/types';

type TaskStatus = 'pending' | 'running' | 'success' | 'error';

type TaskEntry = {
    id: number;
    tool_name: string;
    tool_input: Record<string, unknown> | null;
    tool_output: string | null;
    status: TaskStatus;
    error_message: string | null;
    duration_ms: number;
    conversation: {
        id: number;
        title: string;
    } | null;
    created_at: string | null;
};

type TaskIndexResponse = {
    data: TaskEntry[];
    current_page: number;
    last_page: number;
    total: number;
};

type TaskStatsResponse = {
    total: number;
    success: number;
    error: number;
    running: number;
    today: number;
    breakdown: Array<{
        tool_name: string;
        count: number;
        avg_ms: number;
        errors: number;
    }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tasks',
        href: tasks(),
    },
];

export default function TasksIndex() {
    const [taskResponse, setTaskResponse] = useState<TaskIndexResponse | null>(null);
    const [stats, setStats] = useState<TaskStatsResponse | null>(null);
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [toolFilter, setToolFilter] = useState('');
    const [page, setPage] = useState(1);
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function loadTaskData(showRefreshingState = false): Promise<void> {
        if (showRefreshingState) {
            setIsRefreshing(true);
        }

        setError(null);

        const params = new URLSearchParams({
            page: String(page),
        });

        if (statusFilter !== 'all') {
            params.set('status', statusFilter);
        }

        if (toolFilter.trim() !== '') {
            params.set('tool', toolFilter.trim());
        }

        try {
            const [taskIndex, taskStats] = await Promise.all([
                api<TaskIndexResponse>(`/tasks?${params.toString()}`),
                api<TaskStatsResponse>('/tasks/stats'),
            ]);

            setTaskResponse(taskIndex);
            setStats(taskStats);
        } catch {
            setError('Task activity could not be loaded right now.');
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }

    useEffect(() => {
        void loadTaskData();

        const poller = window.setInterval(() => {
            void loadTaskData(true);
        }, 10000);

        return () => window.clearInterval(poller);
    }, [page, statusFilter, toolFilter]);

    const taskEntries = taskResponse?.data ?? [];
    const toolOptions = stats?.breakdown.map((item) => item.tool_name) ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tasks" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Card className="border-none bg-gradient-to-r from-stone-950 via-slate-900 to-emerald-950 text-white shadow-lg">
                    <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-2">
                            <CardTitle className="font-serif text-2xl">Task Monitor</CardTitle>
                            <CardDescription className="max-w-2xl text-slate-300">
                                Inspect tool runs, failures, and conversation-linked activity across LaraClaw.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" onClick={() => void loadTaskData(true)} disabled={isRefreshing}>
                            {isRefreshing ? <LoaderCircle className="size-4 animate-spin" /> : <RefreshCcw className="size-4" />}
                            Refresh
                        </Button>
                    </CardHeader>
                </Card>

                {stats ? (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        {[
                            ['Total tasks', stats.total],
                            ['Successful', stats.success],
                            ['Errors', stats.error],
                            ['Running', stats.running],
                            ['Today', stats.today],
                        ].map(([label, value]) => (
                            <Card key={label}>
                                <CardHeader className="pb-2">
                                    <CardDescription>{label}</CardDescription>
                                    <CardTitle className="text-3xl">{Number(value).toLocaleString()}</CardTitle>
                                </CardHeader>
                            </Card>
                        ))}
                    </div>
                ) : null}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <Card>
                        <CardHeader className="gap-4">
                            <div>
                                <CardTitle>Recent runs</CardTitle>
                                <CardDescription>Filter by status or tool name to narrow the task stream.</CardDescription>
                            </div>
                            <div className="grid gap-3 md:grid-cols-[180px_minmax(0,1fr)]">
                                <select
                                    value={statusFilter}
                                    onChange={(event) => {
                                        setPage(1);
                                        setStatusFilter(event.target.value);
                                    }}
                                    className="h-10 rounded-md border bg-background px-3 text-sm"
                                >
                                    <option value="all">All statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="running">Running</option>
                                    <option value="success">Success</option>
                                    <option value="error">Error</option>
                                </select>
                                <input
                                    value={toolFilter}
                                    onChange={(event) => {
                                        setPage(1);
                                        setToolFilter(event.target.value);
                                    }}
                                    list="task-tools"
                                    placeholder="Filter by tool name..."
                                    className="h-10 rounded-md border bg-background px-3 text-sm"
                                />
                                <datalist id="task-tools">
                                    {toolOptions.map((toolName) => (
                                        <option key={toolName} value={toolName} />
                                    ))}
                                </datalist>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {isLoading ? (
                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <LoaderCircle className="size-4 animate-spin" />
                                    Loading tasks...
                                </div>
                            ) : error ? (
                                <p className="text-sm text-destructive">{error}</p>
                            ) : taskEntries.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No task runs match the current filters yet.</p>
                            ) : (
                                taskEntries.map((task) => (
                                    <div key={task.id} className="rounded-2xl border bg-card p-4 shadow-sm">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="space-y-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Badge variant="secondary" className="font-mono">
                                                        {task.tool_name}
                                                    </Badge>
                                                    <Badge
                                                        variant={
                                                            task.status === 'error'
                                                                ? 'destructive'
                                                                : task.status === 'success'
                                                                    ? 'default'
                                                                    : 'outline'
                                                        }
                                                    >
                                                        {task.status}
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    Task #{task.id} {task.created_at ? `· ${new Date(task.created_at).toLocaleString()}` : ''}
                                                </p>
                                            </div>
                                            {task.conversation ? (
                                                <Button variant="ghost" size="sm" asChild>
                                                    <Link href={showChat(task.conversation.id)}>
                                                        <MessageSquareText className="size-4" />
                                                        Open chat
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </div>

                                        <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px]">
                                            <div className="rounded-xl bg-muted/50 p-3">
                                                <p className="mb-2 text-xs font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                    Input
                                                </p>
                                                <p className="whitespace-pre-wrap break-words font-mono text-xs">
                                                    {task.tool_input ? JSON.stringify(task.tool_input, null, 2) : 'No input recorded.'}
                                                </p>
                                            </div>
                                            <div className="rounded-xl bg-muted/50 p-3">
                                                <p className="mb-2 text-xs font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                    Output
                                                </p>
                                                <p className="whitespace-pre-wrap break-words text-xs">
                                                    {task.error_message ?? task.tool_output ?? 'No output recorded yet.'}
                                                </p>
                                            </div>
                                            <div className="rounded-xl bg-muted/50 p-3 text-sm">
                                                <p className="mb-2 text-xs font-medium uppercase tracking-[0.2em] text-muted-foreground">
                                                    Context
                                                </p>
                                                <p className="mb-2">
                                                    {task.duration_ms > 0 ? `${task.duration_ms} ms` : 'Still running'}
                                                </p>
                                                <p className="line-clamp-3 text-muted-foreground">
                                                    {task.conversation?.title ?? 'No conversation'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}

                            {taskResponse && taskResponse.last_page > 1 ? (
                                <div className="flex items-center justify-between border-t pt-4 text-sm">
                                    <p className="text-muted-foreground">
                                        Page {taskResponse.current_page} of {taskResponse.last_page} · {taskResponse.total} total tasks
                                    </p>
                                    <div className="flex gap-2">
                                        <Button variant="outline" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>
                                            Previous
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={page >= taskResponse.last_page}
                                            onClick={() => setPage((current) => current + 1)}
                                        >
                                            Next
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="h-fit">
                        <CardHeader>
                            <CardTitle>Tool breakdown</CardTitle>
                            <CardDescription>Which tools are doing the most work right now.</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {stats === null || stats.breakdown.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No tool activity has been recorded yet.</p>
                            ) : (
                                stats.breakdown.slice(0, 8).map((tool) => (
                                    <div key={tool.tool_name} className="rounded-xl border px-4 py-3">
                                        <div className="mb-2 flex items-center justify-between gap-2">
                                            <p className="font-mono text-sm">{tool.tool_name}</p>
                                            <Badge variant={tool.errors > 0 ? 'destructive' : 'secondary'}>
                                                {tool.count} calls
                                            </Badge>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Avg {tool.avg_ms} ms · {tool.errors} errors
                                        </p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

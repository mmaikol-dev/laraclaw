import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CircleHelp, CirclePause, LoaderCircle, Play, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { api } from '@/lib/api';
import { index as agentTasks, show as showTask } from '@/routes/agent-tasks';
import type { BreadcrumbItem } from '@/types';

type TaskStatus =
    | 'pending'
    | 'planning'
    | 'running'
    | 'paused'
    | 'waiting'
    | 'retrying'
    | 'verifying'
    | 'failed'
    | 'completed'
    | 'cancelled';

type EngineTask = {
    id: number;
    goal: string;
    status: TaskStatus;
    status_label: string;
    current_step: number;
    total_steps: number;
    progress_percentage: number;
    attempts: number;
    max_attempts: number;
    failure_reason: string | null;
    model: string | null;
    complexity_level: string | null;
    steps_count: number;
    is_stale: boolean;
    last_heartbeat_at: string | null;
    last_action: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    latest_checkpoint: { action_taken: string; created_at: string | null } | null;
};

type PaginatedTasks = {
    data: EngineTask[];
    current_page: number;
    last_page: number;
    total: number;
};

type StatusCounts = Partial<Record<TaskStatus, number>>;

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Agent Tasks', href: agentTasks() }];

const primaryStatuses: Array<{ key: TaskStatus; label: string }> = [
    { key: 'running', label: 'Running' },
    { key: 'failed', label: 'Failed' },
    { key: 'completed', label: 'Completed' },
    { key: 'paused', label: 'Paused' },
];

function statusVariant(status: TaskStatus): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'running':
        case 'planning':
        case 'verifying':
        case 'retrying':
        case 'waiting':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

export default function AgentTasksIndex({ tasks, filters, metrics }: AgentTasksIndexProps) {
    const [busy, setBusy] = useState<Record<string, string | null>>({});

    async function runAction(taskId: number, action: string, body: Record<string, unknown> = {}): Promise<void> {
        setBusy((current) => ({ ...current, [taskId]: action }));
        try {
            await api(`/engine/tasks/${taskId}/${action}`, { method: 'POST', body: JSON.stringify(body) });
        } catch {
            // ignore; the reload reflects the actual stored state
        } finally {
            setBusy((current) => ({ ...current, [taskId]: null }));
            router.reload();
        }
    }

    async function removeTask(taskId: number): Promise<void> {
        setBusy((current) => ({ ...current, [taskId]: 'destroy' }));
        try {
            await api(`/engine/tasks/${taskId}`, { method: 'DELETE' });
        } catch {
            // ignore
        } finally {
            setBusy((current) => ({ ...current, [taskId]: null }));
            router.reload();
        }
    }

    const activeFilterLabel =
        primaryStatuses.find((entry) => entry.key === filters.status)?.label ??
        (filters.status ? filters.status : 'All');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agent Tasks" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Card className="border-none bg-gradient-to-r from-slate-950 via-slate-900 to-teal-950 text-white shadow-lg">
                    <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div className="space-y-2">
                            <CardTitle className="font-serif text-2xl">Agent Task Engine</CardTitle>
                            <CardDescription className="max-w-2xl text-slate-300">
                                Durable, resumable task execution with planning, checkpoints, verification and supervised recovery.
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {(['', 'running', 'failed', 'completed', 'paused'] as const).map((status) => (
                                <Link key={status} href={agentTasks(status ? { query: { status } } : undefined)}>
                                    <Button variant={(filters.status ?? '') === status ? 'secondary' : 'ghost'} size="sm">
                                        {status === '' ? 'All' : status}
                                    </Button>
                                </Link>
                            ))}
                        </div>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    {[
                        ['Active', metrics.active],
                        ['Paused', metrics.paused],
                        ['Completed', metrics.completed],
                        ['Failed', metrics.failed],
                        ['Stale', metrics.stale],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader className="pb-2">
                                <CardDescription>{label}</CardDescription>
                                <CardTitle className="text-3xl">{Number(value).toLocaleString()}</CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between gap-4">
                        <div>
                            <CardTitle>{activeFilterLabel} tasks</CardTitle>
                            <CardDescription>Every task persists its state; the supervisor can pick it up again after a crash.</CardDescription>
                        </div>
                        <Badge variant="outline">{tasks.total} total</Badge>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {tasks.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No agent tasks match this filter yet. Create one from the chat or a mission.</p>
                        ) : (
                            tasks.data.map((task) => (
                                <div key={task.id} className="rounded-2xl border bg-card p-4 shadow-sm">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="min-w-0 flex-1 space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant={statusVariant(task.status)}>{task.status_label}</Badge>
                                                {task.is_stale ? (
                                                    <Badge variant="destructive">
                                                        <AlertTriangle className="size-3" />
                                                        stale
                                                    </Badge>
                                                ) : null}
                                                {task.complexity_level ? (
                                                    <Badge variant="outline" className="font-mono">{task.complexity_level}</Badge>
                                                ) : null}
                                                {task.model ? <Badge variant="outline" className="font-mono">{task.model}</Badge> : null}
                                            </div>
                                            <Link href={showTask(task.id)} className="block">
                                                <p className="line-clamp-2 text-sm font-medium transition-colors hover:text-teal-600 dark:hover:text-teal-400">
                                                    {task.goal}
                                                </p>
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                Task #{task.id} · {task.steps_count} steps · created {task.created_at ? new Date(task.created_at).toLocaleString() : 'now'}
                                                {task.attempts > 0 ? ` · ${task.attempts}/${task.max_attempts} attempts` : ''}
                                            </p>
                                            {task.latest_checkpoint ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Last: {task.latest_checkpoint.action_taken}
                                                </p>
                                            ) : null}
                                            {task.failure_reason ? (
                                                <p className="line-clamp-1 text-xs text-destructive">{task.failure_reason}</p>
                                            ) : null}
                                        </div>

                                        <div className="flex shrink-0 flex-col items-end gap-3">
                                            <div className="flex flex-wrap items-center gap-1">
                                                {task.status === 'pending' || task.status === 'planning' ? (
                                                    <Button
                                                        size="sm"
                                                        onClick={() => void runAction(task.id, 'execute')}
                                                        disabled={busy[task.id] !== null}
                                                    >
                                                        {busy[task.id] === 'execute' ? <LoaderCircle className="size-4 animate-spin" /> : <Play className="size-4" />}
                                                        Run
                                                    </Button>
                                                ) : null}
                                                {['running', 'retrying', 'verifying', 'planning'].includes(task.status) ? (
                                                    <Button size="sm" variant="outline" onClick={() => void runAction(task.id, 'pause')} disabled={busy[task.id] !== null}>
                                                        {busy[task.id] === 'pause' ? <LoaderCircle className="size-4 animate-spin" /> : <CirclePause className="size-4" />}
                                                        Pause
                                                    </Button>
                                                ) : null}
                                                {['paused', 'failed', 'waiting'].includes(task.status) ? (
                                                    <Button size="sm" variant="outline" onClick={() => void runAction(task.id, 'resume')} disabled={busy[task.id] !== null}>
                                                        {busy[task.id] === 'resume' ? <LoaderCircle className="size-4 animate-spin" /> : <RotateCcw className="size-4" />}
                                                        Resume
                                                    </Button>
                                                ) : null}
                                                {task.is_stale ? (
                                                    <Button size="sm" variant="secondary" onClick={() => void runAction(task.id, 'recover')} disabled={busy[task.id] !== null}>
                                                        {busy[task.id] === 'recover' ? <LoaderCircle className="size-4 animate-spin" /> : <CircleHelp className="size-4" />}
                                                        Recover
                                                    </Button>
                                                ) : null}
                                                {['completed', 'failed', 'cancelled'].includes(task.status) ? (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => void removeTask(task.id)}
                                                        disabled={busy[task.id] !== null}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-4">
                                        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                            <span>
                                                Step {task.current_step} of {task.total_steps}
                                            </span>
                                            <span>{Math.round(task.progress_percentage)}%</span>
                                        </div>
                                        <div className="h-2 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all"
                                                style={{ width: `${Math.min(100, task.progress_percentage)}%` }}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}

                        {tasks.last_page > 1 ? (
                            <div className="flex items-center justify-between border-t pt-4 text-sm">
                                <p className="text-muted-foreground">
                                    Page {tasks.current_page} of {tasks.last_page}
                                </p>
                                <div className="flex gap-2">
                                    <Button variant="outline" disabled={tasks.current_page <= 1} asChild>
                                        <Link href={agentTasks({ query: { page: String(tasks.current_page - 1) } })}>
                                            Previous
                                        </Link>
                                    </Button>
                                    <Button variant="outline" disabled={tasks.current_page >= tasks.last_page} asChild>
                                        <Link href={agentTasks({ query: { page: String(tasks.current_page + 1) } })}>
                                            Next
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
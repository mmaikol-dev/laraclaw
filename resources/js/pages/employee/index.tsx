import { Head } from '@inertiajs/react';
import { EmployeeFormModal } from '@/components/employee-form-modal';
import {
    BrainCircuit,
    CalendarClock,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    CircleDot,
    Clock,
    FileText,
    FolderKanban,
    Loader2,
    PauseCircle,
    Play,
    PlayCircle,
    Plus,
    RefreshCw,
    Tag,
    Trash2,
    Webhook,
    Zap,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import {
    continueProject,
    deleteMemory,
    deleteTask,
    deleteTrigger,
    overview,
    runTask,
    toggleTask,
    toggleTrigger,
} from '@/actions/App/Http/Controllers/EmployeeController';
import { index as employeeRoute } from '@/routes/employee';
import type { BreadcrumbItem } from '@/types';

type ScheduledTask = {
    id: number;
    name: string;
    description: string | null;
    cron_expression: string;
    is_active: boolean;
    last_run_at: string | null;
    next_run_at: string | null;
    use_same_conversation: boolean;
};

type ProjectTask = {
    id: number;
    title: string;
    description: string | null;
    status: string;
    notes: string | null;
    completed_at: string | null;
};

type Project = {
    id: number;
    name: string;
    description: string | null;
    goal: string;
    status: string;
    due_date: string | null;
    progress_summary: string;
    progress_notes: string | null;
    started_at: string | null;
    completed_at: string | null;
    tasks: ProjectTask[];
};

type Trigger = {
    id: number;
    name: string;
    description: string | null;
    type: string;
    config: Record<string, string>;
    is_active: boolean;
    last_triggered_at: string | null;
    prompt: string;
};

type Memory = {
    id: number;
    key: string;
    value: string;
    category: string | null;
    tags: string[] | null;
    expires_at: string | null;
    updated_at: string | null;
};

type Report = {
    id: number;
    report_date: string;
    type: string;
    title: string;
    content: string;
    created_at: string | null;
};

type OverviewData = {
    scheduled_tasks: ScheduledTask[];
    projects: Project[];
    triggers: Trigger[];
    memories: Memory[];
    reports: Report[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Employee', href: employeeRoute() }];

function formatRelative(iso: string | null): string {
    if (!iso) return 'never';
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

function formatFuture(iso: string | null): string {
    if (!iso) return '—';
    const diff = new Date(iso).getTime() - Date.now();
    if (diff < 0) return 'overdue';
    const mins = Math.floor(diff / 60000);
    if (mins < 60) return `in ${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `in ${hrs}h`;
    return `in ${Math.floor(hrs / 24)}d`;
}

const STATUS_BADGE: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400',
    pending: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-950/50 dark:text-yellow-400',
    paused: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
    completed: 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-400',
    failed: 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-400',
};

const TRIGGER_TYPE_META: Record<string, { label: string; icon: React.ElementType; color: string }> = {
    file_watcher: { label: 'File watcher', icon: FolderKanban, color: 'text-amber-500' },
    webhook: { label: 'Webhook', icon: Webhook, color: 'text-violet-500' },
    url_monitor: { label: 'URL monitor', icon: RefreshCw, color: 'text-cyan-500' },
};

async function apiFetch(url: string, method: string = 'GET'): Promise<void> {
    await fetch(url, {
        method,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
    });
}

export default function EmployeeIndex() {
    const [data, setData] = useState<OverviewData | null>(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState<Record<string, boolean>>({});
    const [showForm, setShowForm] = useState(false);
    const [tab, setTab] = useState<'tasks' | 'projects' | 'triggers' | 'memories' | 'reports'>('tasks');
    const [expandedProjects, setExpandedProjects] = useState<Set<number>>(new Set());
    const [expandedReports, setExpandedReports] = useState<Set<number>>(new Set());

    const load = useCallback(async () => {
        try {
            const res = await fetch(overview.url(), { headers: { Accept: 'application/json' } });
            if (res.ok) {
                setData(await res.json());
            }
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
        const interval = window.setInterval(load, 30000);
        return () => window.clearInterval(interval);
    }, [load]);

    const act = useCallback(async (key: string, url: string, method: string) => {
        setBusy((b) => ({ ...b, [key]: true }));
        try {
            await apiFetch(url, method);
            await load();
        } finally {
            setBusy((b) => ({ ...b, [key]: false }));
        }
    }, [load]);

    const toggleExpand = (set: Set<number>, id: number, setter: (s: Set<number>) => void) => {
        const next = new Set(set);
        if (next.has(id)) { next.delete(id); } else { next.add(id); }
        setter(next);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Employee" />
            <EmployeeFormModal open={showForm} onClose={() => setShowForm(false)} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">Agentic Employee</h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">Scheduled tasks, projects, triggers, memories and reports.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button size="sm" onClick={() => setShowForm(true)} className="bg-teal-600 hover:bg-teal-700">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New task
                        </Button>
                        <Button variant="outline" size="sm" onClick={load} disabled={loading}>
                            <RefreshCw className={`mr-1.5 h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                            Refresh
                        </Button>
                    </div>
                </div>

                {loading ? (
                    <div className="space-y-3">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-20 rounded-xl" />
                        ))}
                    </div>
                ) : (
                    <div>
                        <div className="flex flex-wrap gap-1 border-b pb-0">
                            {([
                                { key: 'tasks', label: 'Scheduled Tasks', icon: CalendarClock, count: data?.scheduled_tasks.length },
                                { key: 'projects', label: 'Projects', icon: FolderKanban, count: data?.projects.length },
                                { key: 'triggers', label: 'Triggers', icon: Zap, count: data?.triggers.length },
                                { key: 'memories', label: 'Memories', icon: BrainCircuit, count: data?.memories.length },
                                { key: 'reports', label: 'Reports', icon: FileText, count: data?.reports.length },
                            ] as const).map(({ key, label, icon: Icon, count }) => (
                                <button
                                    key={key}
                                    onClick={() => setTab(key)}
                                    className={`flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                                        tab === key
                                            ? 'border-foreground text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    <Icon className="h-3.5 w-3.5" />
                                    {label}
                                    {count != null && count > 0 && (
                                        <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs">{count}</span>
                                    )}
                                </button>
                            ))}
                        </div>

                        {/* ── Scheduled Tasks ── */}
                        <div className={`mt-4 space-y-3 ${tab !== 'tasks' ? 'hidden' : ''}`}>
                            {!data || data.scheduled_tasks.length === 0 ? (
                                <EmptyState icon={CalendarClock} label="No scheduled tasks yet." hint='Ask the agent to create one using the scheduled_task tool.' />
                            ) : (
                                data.scheduled_tasks.map((task) => (
                                    <Card key={task.id}>
                                        <CardContent className="flex flex-wrap items-start justify-between gap-3 pt-4">
                                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{task.name}</span>
                                                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${task.is_active ? STATUS_BADGE.active : STATUS_BADGE.paused}`}>
                                                        {task.is_active ? 'active' : 'paused'}
                                                    </span>
                                                    <span className="rounded-md bg-muted px-2 py-0.5 font-mono text-xs">{task.cron_expression}</span>
                                                </div>
                                                {task.description && <p className="text-sm text-muted-foreground">{task.description}</p>}
                                                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                                                    <span className="flex items-center gap-1"><Clock className="h-3 w-3" />Last run: {formatRelative(task.last_run_at)}</span>
                                                    <span className="flex items-center gap-1"><CalendarClock className="h-3 w-3" />Next: {formatFuture(task.next_run_at)}</span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => act(`run-${task.id}`, runTask.url(task.id), 'POST')}
                                                    disabled={!!busy[`run-${task.id}`]}
                                                >
                                                    {busy[`run-${task.id}`] ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
                                                    <span className="ml-1">Run now</span>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => act(`toggle-task-${task.id}`, toggleTask.url(task.id), 'PATCH')}
                                                    disabled={!!busy[`toggle-task-${task.id}`]}
                                                >
                                                    {task.is_active ? <PauseCircle className="h-3.5 w-3.5" /> : <PlayCircle className="h-3.5 w-3.5" />}
                                                    <span className="ml-1">{task.is_active ? 'Pause' : 'Resume'}</span>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => act(`del-task-${task.id}`, deleteTask.url(task.id), 'DELETE')}
                                                    disabled={!!busy[`del-task-${task.id}`]}
                                                >
                                                    {busy[`del-task-${task.id}`] ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                ))
                            )}
                        </div>

                        {/* ── Projects ── */}
                        <div className={`mt-4 space-y-3 ${tab !== 'projects' ? 'hidden' : ''}`}>
                            {!data || data.projects.length === 0 ? (
                                <EmptyState icon={FolderKanban} label="No projects yet." hint='Ask the agent to create one using the project tool.' />
                            ) : (
                                data.projects.map((project) => {
                                    const expanded = expandedProjects.has(project.id);
                                    return (
                                        <Card key={project.id}>
                                            <CardHeader className="pb-2">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <CardTitle className="text-base">{project.name}</CardTitle>
                                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[project.status] ?? STATUS_BADGE.paused}`}>
                                                                {project.status}
                                                            </span>
                                                            {project.due_date && (
                                                                <span className="text-xs text-muted-foreground">due {project.due_date}</span>
                                                            )}
                                                        </div>
                                                        <p className="text-sm text-muted-foreground">{project.goal}</p>
                                                        <p className="text-xs text-muted-foreground">{project.progress_summary}</p>
                                                    </div>
                                                    <div className="flex shrink-0 gap-2">
                                                        {project.status !== 'completed' && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => act(`continue-${project.id}`, continueProject.url(project.id), 'POST')}
                                                                disabled={!!busy[`continue-${project.id}`]}
                                                            >
                                                                {busy[`continue-${project.id}`] ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Play className="h-3.5 w-3.5" />}
                                                                <span className="ml-1">Continue</span>
                                                            </Button>
                                                        )}
                                                        {project.tasks.length > 0 && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => toggleExpand(expandedProjects, project.id, setExpandedProjects)}
                                                            >
                                                                {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                                <span className="ml-1">{project.tasks.length} tasks</span>
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </CardHeader>
                                            {expanded && project.tasks.length > 0 && (
                                                <CardContent className="space-y-1.5 pt-0">
                                                    {project.tasks.map((t) => (
                                                        <div key={t.id} className="flex items-start gap-2 rounded-lg bg-muted/50 px-3 py-2 text-sm">
                                                            {t.status === 'done' ? (
                                                                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                                                            ) : (
                                                                <CircleDot className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                                            )}
                                                            <div className="flex-1">
                                                                <span className={t.status === 'done' ? 'line-through text-muted-foreground' : ''}>{t.title}</span>
                                                                {t.notes && <p className="mt-0.5 text-xs text-muted-foreground">{t.notes}</p>}
                                                            </div>
                                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[t.status] ?? STATUS_BADGE.paused}`}>
                                                                {t.status}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </CardContent>
                                            )}
                                        </Card>
                                    );
                                })
                            )}
                        </div>

                        {/* ── Triggers ── */}
                        <div className={`mt-4 space-y-3 ${tab !== 'triggers' ? 'hidden' : ''}`}>
                            {!data || data.triggers.length === 0 ? (
                                <EmptyState icon={Zap} label="No triggers yet." hint='Ask the agent to create one using the trigger tool.' />
                            ) : (
                                data.triggers.map((trigger) => {
                                    const meta = TRIGGER_TYPE_META[trigger.type] ?? { label: trigger.type, icon: Zap, color: 'text-muted-foreground' };
                                    const Icon = meta.icon;
                                    return (
                                        <Card key={trigger.id}>
                                            <CardContent className="flex flex-wrap items-start justify-between gap-3 pt-4">
                                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Icon className={`h-4 w-4 ${meta.color}`} />
                                                        <span className="font-medium">{trigger.name}</span>
                                                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${trigger.is_active ? STATUS_BADGE.active : STATUS_BADGE.paused}`}>
                                                            {trigger.is_active ? 'active' : 'paused'}
                                                        </span>
                                                        <Badge variant="outline" className="text-xs">{meta.label}</Badge>
                                                    </div>
                                                    {trigger.description && <p className="text-sm text-muted-foreground">{trigger.description}</p>}
                                                    <p className="text-xs text-muted-foreground">
                                                        Last fired: {formatRelative(trigger.last_triggered_at)}
                                                        {trigger.config.directory && <> · <span className="font-mono">{trigger.config.directory}</span></>}
                                                        {trigger.config.url && <> · <span className="font-mono">{trigger.config.url}</span></>}
                                                    </p>
                                                </div>
                                                <div className="flex shrink-0 gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => act(`toggle-trigger-${trigger.id}`, toggleTrigger.url(trigger.id), 'PATCH')}
                                                        disabled={!!busy[`toggle-trigger-${trigger.id}`]}
                                                    >
                                                        {trigger.is_active ? <PauseCircle className="h-3.5 w-3.5" /> : <PlayCircle className="h-3.5 w-3.5" />}
                                                        <span className="ml-1">{trigger.is_active ? 'Pause' : 'Resume'}</span>
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-destructive hover:text-destructive"
                                                        onClick={() => act(`del-trigger-${trigger.id}`, deleteTrigger.url(trigger.id), 'DELETE')}
                                                        disabled={!!busy[`del-trigger-${trigger.id}`]}
                                                    >
                                                        {busy[`del-trigger-${trigger.id}`] ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    );
                                })
                            )}
                        </div>

                        {/* ── Memories ── */}
                        <div className={`mt-4 space-y-3 ${tab !== 'memories' ? 'hidden' : ''}`}>
                            {!data || data.memories.length === 0 ? (
                                <EmptyState icon={BrainCircuit} label="No memories yet." hint='Ask the agent to remember something using the memory tool.' />
                            ) : (
                                data.memories.map((memory) => (
                                    <Card key={memory.id}>
                                        <CardContent className="flex flex-wrap items-start justify-between gap-3 pt-4">
                                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-sm font-medium">{memory.key}</span>
                                                    {memory.category && (
                                                        <Badge variant="secondary" className="text-xs">{memory.category}</Badge>
                                                    )}
                                                    {memory.expires_at && (
                                                        <span className="text-xs text-muted-foreground">expires {formatRelative(memory.expires_at)}</span>
                                                    )}
                                                </div>
                                                <p className="text-sm text-muted-foreground line-clamp-2">{memory.value}</p>
                                                {memory.tags && memory.tags.length > 0 && (
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <Tag className="h-3 w-3 text-muted-foreground" />
                                                        {memory.tags.map((tag) => (
                                                            <span key={tag} className="rounded-md bg-muted px-1.5 py-0.5 text-xs">{tag}</span>
                                                        ))}
                                                    </div>
                                                )}
                                                <p className="text-xs text-muted-foreground">Updated {formatRelative(memory.updated_at)}</p>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="shrink-0 text-destructive hover:text-destructive"
                                                onClick={() => act(`del-mem-${memory.id}`, deleteMemory.url(memory.id), 'DELETE')}
                                                disabled={!!busy[`del-mem-${memory.id}`]}
                                            >
                                                {busy[`del-mem-${memory.id}`] ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
                                            </Button>
                                        </CardContent>
                                    </Card>
                                ))
                            )}
                        </div>

                        {/* ── Reports ── */}
                        <div className={`mt-4 space-y-3 ${tab !== 'reports' ? 'hidden' : ''}`}>
                            {!data || data.reports.length === 0 ? (
                                <EmptyState icon={FileText} label="No reports yet." hint='Reports are generated automatically each night at 11:55 PM.' />
                            ) : (
                                data.reports.map((report) => {
                                    const expanded = expandedReports.has(report.id);
                                    return (
                                        <Card key={report.id}>
                                            <CardContent className="pt-4">
                                                <button
                                                    className="flex w-full items-start justify-between gap-3 text-left"
                                                    onClick={() => toggleExpand(expandedReports, report.id, setExpandedReports)}
                                                >
                                                    <div className="flex flex-col gap-0.5">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium">{report.title}</span>
                                                            <Badge variant="outline" className="text-xs">{report.type}</Badge>
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">{report.report_date} · {formatRelative(report.created_at)}</p>
                                                    </div>
                                                    {expanded ? <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" /> : <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />}
                                                </button>
                                                {expanded && (
                                                    <pre className="mt-3 whitespace-pre-wrap rounded-lg bg-muted/50 p-4 text-sm leading-relaxed">
                                                        {report.content}
                                                    </pre>
                                                )}
                                            </CardContent>
                                        </Card>
                                    );
                                })
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function EmptyState({ icon: Icon, label, hint }: { icon: React.ElementType; label: string; hint: string }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed py-16 text-center">
            <Icon className="mb-3 h-10 w-10 text-muted-foreground/40" />
            <p className="font-medium text-muted-foreground">{label}</p>
            <p className="mt-1 max-w-xs text-sm text-muted-foreground/70">{hint}</p>
        </div>
    );
}

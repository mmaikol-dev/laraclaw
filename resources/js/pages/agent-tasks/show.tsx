import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ChevronLeft, CircleDot, ClipboardList, History, Play, Square, Wrench } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { index as agentTasks, show as showTask } from '@/routes/agent-tasks';
import type { BreadcrumbItem } from '@/types';

type StepStatus = 'pending' | 'running' | 'completed' | 'failed' | 'skipped' | 'retrying';

type TaskStep = {
    id: number;
    sort_order: number;
    description: string;
    prompt: string | null;
    status: StepStatus;
    status_label: string;
    attempts: number;
    max_attempts: number;
    result: string | null;
    error: string | null;
    repaired: boolean;
};

type TaskCheckpoint = {
    id: number;
    step_sort_order: number | null;
    action_taken: string;
    action_result: string | null;
    task_status: string | null;
    created_at: string | null;
};

type AgentTaskShowProps = {
    task: {
        id: number;
        goal: string;
        status: string;
        status_label: string;
        plan: string | null;
        current_step: number;
        total_steps: number;
        attempts: number;
        max_attempts: number;
        failure_reason: string | null;
        last_action: string | null;
        last_result: string | null;
        last_error: string | null;
        verification_status: string | null;
        acceptance_criteria: string[];
        verification_results: unknown;
        last_verified_at: string | null;
        model: string | null;
        complexity_level: string | null;
        last_heartbeat_at: string | null;
        started_at: string | null;
        completed_at: string | null;
        created_at: string | null;
        steps: TaskStep[];
        checkpoints: TaskCheckpoint[];
    };
    context_summary: string;
};

function statusVariant(status: string): 'default' | 'secondary' | 'outline' | 'destructive' {
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

function stepVariant(status: StepStatus): 'default' | 'secondary' | 'outline' | 'destructive' {
    switch (status) {
        case 'completed':
            return 'default';
        case 'running':
        case 'retrying':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
}

function ActionIcon({ status }: { status: string }) {
    switch (status) {
        case 'completed':
            return <CheckCircle2 className="size-3.5 text-emerald-400" />;
        case 'running':
        case 'verifying':
        case 'retrying':
        case 'waiting':
            return <Play className="size-3.5 text-teal-400" />;
        case 'failed':
            return <Square className="size-3.5 text-red-400" />;
        default:
            return <CircleDot className="size-3.5 text-slate-400" />;
    }
}

export default function AgentTasksShow({ task, context_summary }: AgentTaskShowProps) {
    const progress = task.total_steps > 0 ? Math.round((task.current_step / task.total_steps) * 100) : 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Agent Tasks', href: agentTasks() },
        { title: `Task #${task.id}`, href: showTask(task.id) },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Agent Task #${task.id}`} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Link href={agentTasks()}>
                        <Badge variant="outline" className="gap-1">
                            <ChevronLeft className="size-3" />
                            Back to tasks
                        </Badge>
                    </Link>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={statusVariant(task.status)}>{task.status_label}</Badge>
                        {task.complexity_level ? <Badge variant="outline" className="font-mono">{task.complexity_level}</Badge> : null}
                        {task.model ? <Badge variant="outline" className="font-mono">{task.model}</Badge> : null}
                        {task.verification_status ? (
                            <Badge variant={task.verification_status === 'passed' ? 'default' : 'destructive'}>
                                {task.verification_status}
                            </Badge>
                        ) : null}
                    </div>
                </div>

                <Card className="border-none bg-gradient-to-r from-slate-950 via-slate-900 to-teal-950 text-white shadow-lg">
                    <CardHeader>
                        <CardTitle className="font-serif text-2xl">{task.goal}</CardTitle>
                        <CardDescription className="max-w-3xl text-slate-300">
                            Created {task.created_at ? new Date(task.created_at).toLocaleString() : 'now'}
                            {task.started_at ? ` · started ${new Date(task.started_at).toLocaleString()}` : ''}
                            {task.completed_at ? ` · finished ${new Date(task.completed_at).toLocaleString()}` : ''}
                            · {task.attempts}/{task.max_attempts} attempts
                        </CardDescription>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <ClipboardList className="size-4" />
                                    Steps
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {task.steps.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No steps yet — this task has not been planned.</p>
                                ) : (
                                    task.steps.map((step) => (
                                        <div key={step.id} className="flex gap-3 rounded-xl border bg-card p-3">
                                            <div className="mt-0.5 shrink-0">
                                                <ActionIcon status={step.status} />
                                            </div>
                                            <div className="min-w-0 flex-1 space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-mono text-xs text-muted-foreground">#{step.sort_order}</span>
                                                    <Badge variant={stepVariant(step.status)}>{step.status_label}</Badge>
                                                    {step.repaired ? <Badge variant="secondary">repaired</Badge> : null}
                                                    {step.attempts > 0 ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {step.attempts}/{step.max_attempts} attempts
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="text-sm font-medium">{step.description}</p>
                                                {step.prompt ? <p className="whitespace-pre-wrap break-words text-xs text-muted-foreground">{step.prompt}</p> : null}
                                                {step.result ? (
                                                    <p className="whitespace-pre-wrap break-words rounded-lg bg-muted/50 p-2 text-xs">{step.result}</p>
                                                ) : null}
                                                {step.error ? (
                                                    <p className="whitespace-pre-wrap break-words rounded-lg bg-red-950/20 p-2 text-xs text-destructive">{step.error}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}

                                <div className="pt-2">
                                    <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                        <span>
                                            Step {task.current_step} of {task.total_steps}
                                        </span>
                                        <span>{progress}%</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all"
                                            style={{ width: `${Math.min(100, progress)}%` }}
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {task.acceptance_criteria.length > 0 ? (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Acceptance criteria</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {task.acceptance_criteria.map((criterion, index) => (
                                        <div key={criterion} className="flex items-start gap-2 text-sm">
                                            <CheckCircle2 className="mt-0.5 size-4 text-emerald-500" />
                                            <span>
                                                {index + 1}. {criterion}
                                            </span>
                                        </div>
                                    ))}
                                </CardContent>
                            </Card>
                        ) : null}

                        {context_summary ? (
                            <Card>
                                <CardHeader className="pb-3">
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Wrench className="size-4" />
                                        Live context
                                    </CardTitle>
                                    <CardDescription>What the executor sees on the next step dispatch.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <pre className="whitespace-pre-wrap break-words rounded-xl bg-muted/50 p-3 font-mono text-xs">{context_summary}</pre>
                                </CardContent>
                            </Card>
                        ) : null}

                        {task.failure_reason ? (
                            <Card className="border-destructive/40">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base text-destructive">Failure reason</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap break-words text-sm">{task.failure_reason}</p>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <History className="size-4" />
                                    Checkpoints
                                </CardTitle>
                                <CardDescription>Durable state recorded after every completed step.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {task.checkpoints.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No checkpoints recorded yet.</p>
                                ) : (
                                    task.checkpoints.map((checkpoint, idx) => (
                                        <div key={checkpoint.id} className="relative flex gap-3">
                                            {idx < task.checkpoints.length - 1 ? (
                                                <span className="absolute left-[5px] top-4 h-full w-px bg-border" />
                                            ) : null}
                                            <span className="mt-1.5 size-2.5 shrink-0 rounded-full bg-teal-500" />
                                            <div className="min-w-0 space-y-1">
                                                <p className="text-xs text-muted-foreground">
                                                    {checkpoint.created_at ? new Date(checkpoint.created_at).toLocaleString() : 'Recently'}
                                                    {checkpoint.task_status ? ` · ${checkpoint.task_status}` : ''}
                                                </p>
                                                <p className="text-sm font-medium">{checkpoint.action_taken}</p>
                                                {checkpoint.action_result ? (
                                                    <p className="whitespace-pre-wrap break-words rounded-lg bg-muted/50 p-2 text-xs">{checkpoint.action_result}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
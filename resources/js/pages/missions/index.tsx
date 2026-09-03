import { Head } from '@inertiajs/react';
import {
    ChevronDown,
    CircleCheck,
    CircleX,
    Coins,
    Crosshair,
    FileCheck2,
    GitCommitHorizontal,
    ListChecks,
    Rocket,
    ShieldAlert,
    Sparkles,
    Terminal,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { index as missionsRoute } from '@/routes/missions';
import type { BreadcrumbItem } from '@/types';

type MissionFeature = {
    id: number;
    title: string;
    description: string | null;
    milestone: string | null;
    sort_order: number;
    status: 'pending' | 'in_progress' | 'implemented' | 'validated' | 'blocked';
    attempts: number;
    notes: string | null;
};

type MissionHandoff = {
    id: number;
    role: 'orchestrator' | 'worker' | 'validator';
    summary: string;
    completed_work: string | null;
    undone_work: string | null;
    discovered_issues: string | null;
    procedure_adhered: boolean;
    created_at: string;
};

type Mission = {
    id: number;
    name: string;
    goal: string;
    status: 'scoping' | 'active' | 'paused' | 'completed' | 'failed';
    orchestrator_model: string | null;
    worker_model: string | null;
    validator_model: string | null;
    validation_contract: string[] | null;
    milestone_count: number;
    context_notes: string | null;
    spent_tokens: number;
    features: MissionFeature[];
    handoffs: MissionHandoff[];
};

type Props = {
    missions: Mission[];
    opencodeAvailable: boolean;
    opencodeModels: string[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Missions', href: missionsRoute() },
];

const STATUS_META: Record<
    Mission['status'],
    { label: string; className: string }
> = {
    scoping: {
        label: 'Scoping',
        className:
            'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    },
    active: {
        label: 'Active',
        className:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    },
    paused: {
        label: 'Paused',
        className:
            'bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    },
    completed: {
        label: 'Completed',
        className:
            'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
    },
    failed: {
        label: 'Failed',
        className:
            'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    },
};

const FEATURE_STATUS_META: Record<
    MissionFeature['status'],
    { icon: React.ElementType; color: string }
> = {
    pending: { icon: ListChecks, color: 'text-muted-foreground' },
    in_progress: { icon: Rocket, color: 'text-blue-500 animate-pulse' },
    implemented: { icon: GitCommitHorizontal, color: 'text-violet-500' },
    validated: { icon: CircleCheck, color: 'text-emerald-500' },
    blocked: { icon: ShieldAlert, color: 'text-red-500' },
};

const ROLE_ICON: Record<MissionHandoff['role'], React.ElementType> = {
    orchestrator: UserCog,
    worker: Terminal,
    validator: ShieldAlert,
};

function progress(mission: Mission): {
    done: number;
    total: number;
    percent: number;
} {
    const total = mission.features.length;
    const done = mission.features.filter(
        (f) => f.status === 'implemented' || f.status === 'validated',
    ).length;

    return {
        done,
        total,
        percent: total === 0 ? 0 : Math.round((done / total) * 100),
    };
}

function formatTokens(tokens: number): string {
    if (tokens >= 1_000_000) {
        return `${(tokens / 1_000_000).toFixed(1)}M`;
    }

    if (tokens >= 1_000) {
        return `${(tokens / 1_000).toFixed(1)}k`;
    }

    return String(tokens);
}

export default function MissionsIndex({
    missions,
    opencodeAvailable,
    opencodeModels,
}: Props) {
    const [expandedId, setExpandedId] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Missions" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Mission Control</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Long-horizon autonomous engineering: orchestrator plans,
                        workers implement serially, validators verify against
                        the pre-defined contract.
                    </p>
                </div>

                <div className="rounded-xl border bg-card p-4">
                    <div className="flex items-center gap-2">
                        {opencodeAvailable ? (
                            <CircleCheck className="size-4 text-emerald-500" />
                        ) : (
                            <CircleX className="size-4 text-red-500" />
                        )}
                        <span className="font-medium">
                            OpenCode agent{' '}
                            {opencodeAvailable ? 'available' : 'not found'}
                        </span>
                        {!opencodeAvailable && (
                            <span className="text-sm text-muted-foreground">
                                — install it at ~/.opencode/bin/opencode to
                                enable coding missions
                            </span>
                        )}
                    </div>

                    {opencodeModels.length > 0 && (
                        <div className="mt-3 flex flex-wrap gap-2">
                            {opencodeModels.map((model) => (
                                <Badge
                                    key={model}
                                    variant="secondary"
                                    className="font-mono text-xs"
                                >
                                    {model}
                                </Badge>
                            ))}
                        </div>
                    )}
                </div>

                {missions.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center text-muted-foreground">
                        No missions yet. Ask the agent to start one — e.g.
                        “Create a mission to build X”.
                    </div>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {missions.map((mission) => {
                            const p = progress(mission);
                            const statusMeta = STATUS_META[mission.status];
                            const expanded = expandedId === mission.id;

                            const milestones = mission.features.reduce<
                                Record<string, MissionFeature[]>
                            >((acc, feature) => {
                                const key = feature.milestone ?? 'Unassigned';
                                acc[key] = acc[key] ?? [];
                                acc[key].push(feature);

                                return acc;
                            }, {});

                            return (
                                <div
                                    key={mission.id}
                                    className="rounded-xl border bg-card"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setExpandedId(
                                                expanded ? null : mission.id,
                                            )
                                        }
                                        className="w-full p-4 text-left"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="font-medium">
                                                    {mission.name}
                                                </div>
                                                <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
                                                    {mission.goal}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <Badge
                                                    className={
                                                        statusMeta.className
                                                    }
                                                >
                                                    {statusMeta.label}
                                                </Badge>
                                                <ChevronDown
                                                    className={`size-4 text-muted-foreground transition-transform ${expanded ? 'rotate-180' : ''}`}
                                                />
                                            </div>
                                        </div>

                                        <div className="mt-3 flex items-center gap-3">
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-emerald-500 transition-all"
                                                    style={{
                                                        width: `${p.percent}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {p.done}/{p.total}
                                            </span>
                                        </div>

                                        <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1">
                                                <Crosshair className="size-3.5" />
                                                {mission.validation_contract
                                                    ?.length ?? 0}{' '}
                                                assertions
                                            </span>
                                            {mission.spent_tokens > 0 && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Coins className="size-3.5" />
                                                    {formatTokens(
                                                        mission.spent_tokens,
                                                    )}{' '}
                                                    tokens
                                                </span>
                                            )}
                                            {mission.worker_model && (
                                                <span className="inline-flex items-center gap-1 font-mono">
                                                    <Terminal className="size-3.5" />
                                                    work: {mission.worker_model}
                                                </span>
                                            )}
                                            {mission.validator_model && (
                                                <span className="inline-flex items-center gap-1 font-mono">
                                                    <FileCheck2 className="size-3.5" />
                                                    verify:{' '}
                                                    {mission.validator_model}
                                                </span>
                                            )}
                                        </div>
                                    </button>

                                    {expanded && (
                                        <div className="space-y-4 border-t px-4 py-4">
                                            {Object.keys(milestones).length ===
                                            0 ? (
                                                <p className="text-sm text-muted-foreground">
                                                    Plan not scoped yet — the
                                                    orchestrator has not
                                                    produced features.
                                                </p>
                                            ) : (
                                                Object.entries(milestones).map(
                                                    ([milestone, features]) => (
                                                        <div key={milestone}>
                                                            <div className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                                {milestone}
                                                            </div>
                                                            <ul className="space-y-1">
                                                                {features.map(
                                                                    (
                                                                        feature,
                                                                    ) => {
                                                                        const meta =
                                                                            FEATURE_STATUS_META[
                                                                                feature
                                                                                    .status
                                                                            ];
                                                                        const Icon =
                                                                            meta.icon;

                                                                        return (
                                                                            <li
                                                                                key={
                                                                                    feature.id
                                                                                }
                                                                                className="flex items-start gap-2 text-sm"
                                                                            >
                                                                                <Icon
                                                                                    className={`mt-0.5 size-4 shrink-0 ${meta.color}`}
                                                                                />
                                                                                <span>
                                                                                    {
                                                                                        feature.title
                                                                                    }
                                                                                    {feature.attempts >
                                                                                        1 && (
                                                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                                                            (attempt{' '}
                                                                                            {
                                                                                                feature.attempts
                                                                                            }

                                                                                            )
                                                                                        </span>
                                                                                    )}
                                                                                    {feature.notes && (
                                                                                        <span className="block text-xs text-muted-foreground">
                                                                                            {
                                                                                                feature.notes
                                                                                            }
                                                                                        </span>
                                                                                    )}
                                                                                </span>
                                                                            </li>
                                                                        );
                                                                    },
                                                                )}
                                                            </ul>
                                                        </div>
                                                    ),
                                                )
                                            )}

                                            {mission.handoffs.length > 0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                        Recent handoffs
                                                    </div>
                                                    <ul className="space-y-1">
                                                        {mission.handoffs.map(
                                                            (handoff) => {
                                                                const Icon =
                                                                    ROLE_ICON[
                                                                        handoff
                                                                            .role
                                                                    ];

                                                                return (
                                                                    <li
                                                                        key={
                                                                            handoff.id
                                                                        }
                                                                        className="flex items-start gap-2 text-sm"
                                                                    >
                                                                        <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                                                        <span>
                                                                            {
                                                                                handoff.summary
                                                                            }
                                                                            {handoff.discovered_issues && (
                                                                                <span className="block text-xs text-red-500">
                                                                                    {
                                                                                        handoff.discovered_issues
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                        </span>
                                                                    </li>
                                                                );
                                                            },
                                                        )}
                                                    </ul>
                                                </div>
                                            )}

                                            {mission.context_notes && (
                                                <div>
                                                    <div className="mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                                        Mission context
                                                    </div>
                                                    <pre className="max-h-40 overflow-auto rounded-md bg-muted/50 p-2 text-xs whitespace-pre-wrap">
                                                        {mission.context_notes}
                                                    </pre>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}

                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Sparkles className="size-3.5" />
                    Create missions by chatting: “Create a mission named
                    api-rebuild to rebuild the public API”. The agent scopes the
                    plan, writes the validation contract, and executes serially.
                </p>
            </div>
        </AppLayout>
    );
}

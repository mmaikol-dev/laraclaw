import { Head } from '@inertiajs/react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { useEffect, useState } from 'react';
import type { BreadcrumbItem } from '@/types';

type DashboardMetrics = {
    ollama_health: {
        status: string;
        host: string;
        agent_model: string;
        embedding_model: string;
        agent_available: boolean;
        embedding_available: boolean;
        message?: string;
    };
    avg_tokens_per_sec: number;
    avg_latency_ms: number;
    total_tokens: number;
    total_conversations: number;
    total_messages: number;
    total_tasks: number;
    task_error_rate: number;
    tokens_over_time: Array<{ hour: string; tokens: number }>;
    latency_over_time: Array<{ hour: string; avg_ms: number }>;
    tool_usage: Array<{ tool_name: string; count: number; avg_ms: number; errors: number }>;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

export default function Dashboard() {
    const [metrics, setMetrics] = useState<DashboardMetrics | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        let isMounted = true;

        const loadMetrics = async () => {
            try {
                const response = await api<DashboardMetrics>('/metrics');

                if (isMounted) {
                    setMetrics(response);
                    setIsLoading(false);
                }
            } catch {
                if (isMounted) {
                    setMetrics(null);
                    setIsLoading(false);
                }
            }
        };

        loadMetrics();

        const poller = window.setInterval(loadMetrics, 30000);

        return () => {
            isMounted = false;
            window.clearInterval(poller);
        };
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {isLoading ? (
                    <div className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-4">
                            {Array.from({ length: 4 }).map((_, index) => (
                                <Skeleton key={index} className="h-28 rounded-xl" />
                            ))}
                        </div>
                        <Skeleton className="h-80 rounded-xl" />
                    </div>
                ) : metrics === null ? (
                    <Alert variant="destructive">
                        <AlertTitle>Dashboard data is unavailable</AlertTitle>
                        <AlertDescription>
                            The metrics endpoint could not be loaded. Check the backend logs and try refreshing.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <>
                        <Card className="border-none bg-gradient-to-r from-slate-950 via-slate-900 to-emerald-950 text-white shadow-lg">
                            <CardHeader>
                                <div className="flex flex-wrap items-center gap-3">
                                    <CardTitle className="font-serif text-2xl">LaraClaw Runtime</CardTitle>
                                    <Badge variant={metrics.ollama_health.status === 'ok' ? 'secondary' : 'destructive'}>
                                        {metrics.ollama_health.status}
                                    </Badge>
                                </div>
                                <CardDescription className="text-slate-300">
                                    {metrics.ollama_health.host} · agent {metrics.ollama_health.agent_model} · embeddings {metrics.ollama_health.embedding_model}
                                </CardDescription>
                            </CardHeader>
                        </Card>

                        {metrics.ollama_health.status !== 'ok' ? (
                            <Alert variant="destructive">
                                <AlertTitle>Ollama is not currently reachable</AlertTitle>
                                <AlertDescription>
                                    {metrics.ollama_health.message ?? 'Start Ollama and confirm the configured models are available.'}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            {[
                                ['Avg tokens/sec', `${metrics.avg_tokens_per_sec} tok/s`],
                                ['Avg latency', `${metrics.avg_latency_ms} ms`],
                                ['Total conversations', metrics.total_conversations.toLocaleString()],
                                ['Task error rate', `${metrics.task_error_rate}%`],
                            ].map(([label, value]) => (
                                <Card key={label}>
                                    <CardHeader className="pb-2">
                                        <CardDescription>{label}</CardDescription>
                                        <CardTitle className="text-3xl">{value}</CardTitle>
                                    </CardHeader>
                                </Card>
                            ))}
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Tokens over time</CardTitle>
                                    <CardDescription>Recent completion output by hour.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {metrics.tokens_over_time.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">No token metrics recorded yet.</p>
                                    ) : (
                                        metrics.tokens_over_time.slice(-8).map((entry) => (
                                            <div key={entry.hour} className="flex items-center justify-between rounded-lg bg-muted/60 px-3 py-2 text-sm">
                                                <span>{entry.hour}</span>
                                                <Badge variant="outline">{entry.tokens}</Badge>
                                            </div>
                                        ))
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Latency over time</CardTitle>
                                    <CardDescription>Average response duration by hour.</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {metrics.latency_over_time.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">No latency metrics recorded yet.</p>
                                    ) : (
                                        metrics.latency_over_time.slice(-8).map((entry) => (
                                            <div key={entry.hour} className="flex items-center justify-between rounded-lg bg-muted/60 px-3 py-2 text-sm">
                                                <span>{entry.hour}</span>
                                                <Badge variant="outline">{entry.avg_ms} ms</Badge>
                                            </div>
                                        ))
                                    )}
                                </CardContent>
                            </Card>
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle>Tool usage</CardTitle>
                                <CardDescription>Aggregated task activity across the current dataset.</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {metrics.tool_usage.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">No tool activity recorded yet.</p>
                                ) : (
                                    metrics.tool_usage.map((tool) => (
                                        <div key={tool.tool_name} className="flex items-center justify-between rounded-lg border px-4 py-3">
                                            <div>
                                                <p className="font-mono text-sm">{tool.tool_name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {tool.count} calls · avg {tool.avg_ms} ms
                                                </p>
                                            </div>
                                            <Badge variant={tool.errors > 0 ? 'destructive' : 'secondary'}>
                                                {tool.errors} errors
                                            </Badge>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

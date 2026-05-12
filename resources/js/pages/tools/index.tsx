import { Head } from '@inertiajs/react';
import {
    Bot,
    BrainCircuit,
    FileSearch,
    FileText,
    FolderKanban,
    Globe,
    HardDrive,
    ShieldAlert,
    Wrench,
    Zap,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { index as toolsRoute } from '@/routes/tools';
import type { BreadcrumbItem } from '@/types';

type ToolParameter = {
    name: string;
    type: string;
    description: string;
    required: boolean;
    enum: string[];
};

type ToolItem = {
    name: string;
    description: string;
    enabled: boolean;
    actions: string[];
    required_fields: string[];
    parameter_count: number;
    parameters: ToolParameter[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tools', href: toolsRoute() }];

const TOOL_META: Record<string, { icon: React.ElementType; accent: string; panel: string }> = {
    browser: { icon: Globe, accent: 'text-cyan-500', panel: 'border-cyan-500/20 bg-cyan-500/5' },
    document: { icon: FileText, accent: 'text-sky-500', panel: 'border-sky-500/20 bg-sky-500/5' },
    file: { icon: HardDrive, accent: 'text-amber-500', panel: 'border-amber-500/20 bg-amber-500/5' },
    memory: { icon: BrainCircuit, accent: 'text-violet-500', panel: 'border-violet-500/20 bg-violet-500/5' },
    project: { icon: FolderKanban, accent: 'text-emerald-500', panel: 'border-emerald-500/20 bg-emerald-500/5' },
    scheduled_task: { icon: Zap, accent: 'text-orange-500', panel: 'border-orange-500/20 bg-orange-500/5' },
    shell: { icon: Wrench, accent: 'text-rose-500', panel: 'border-rose-500/20 bg-rose-500/5' },
    skill: { icon: Bot, accent: 'text-fuchsia-500', panel: 'border-fuchsia-500/20 bg-fuchsia-500/5' },
    trigger: { icon: ShieldAlert, accent: 'text-teal-500', panel: 'border-teal-500/20 bg-teal-500/5' },
    web: { icon: FileSearch, accent: 'text-blue-500', panel: 'border-blue-500/20 bg-blue-500/5' },
};

export default function ToolsIndex({ tools }: { tools: ToolItem[] }) {
    const enabledTools = tools.filter((tool) => tool.enabled);
    const toolsWithActions = tools.filter((tool) => tool.actions.length > 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tools" />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <Card className="border-none bg-gradient-to-r from-slate-950 via-slate-900 to-cyan-950 text-white shadow-lg">
                    <CardHeader className="gap-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="rounded-2xl bg-white/10 p-3">
                                <Wrench className="size-6" />
                            </div>
                            <div>
                                <CardTitle className="font-serif text-2xl">Agent Tools</CardTitle>
                                <CardDescription className="text-slate-300">
                                    Browse the tools LaraClaw can call, what each tool is for, and the parameters the agent can send.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                </Card>

                <div className="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
                    {[
                        ['Total tools', tools.length],
                        ['Enabled', enabledTools.length],
                        ['With actions', toolsWithActions.length],
                        ['Parameters exposed', tools.reduce((sum, tool) => sum + tool.parameter_count, 0)],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader className="pb-2">
                                <CardDescription>{label}</CardDescription>
                                <CardTitle className="text-3xl">{value}</CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    {tools.map((tool) => {
                        const meta = TOOL_META[tool.name] ?? {
                            icon: Wrench,
                            accent: 'text-muted-foreground',
                            panel: 'border-border bg-muted/20',
                        };
                        const Icon = meta.icon;

                        return (
                            <Card key={tool.name} className={meta.panel}>
                                <CardHeader className="gap-4">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex items-start gap-3">
                                            <div className="rounded-2xl bg-background/80 p-3 shadow-sm">
                                                <Icon className={`size-5 ${meta.accent}`} />
                                            </div>
                                            <div className="space-y-2">
                                                <CardTitle className="font-mono text-lg">{tool.name}</CardTitle>
                                                <CardDescription className="text-sm leading-6 text-foreground/80">
                                                    {tool.description}
                                                </CardDescription>
                                            </div>
                                        </div>
                                        <Badge variant={tool.enabled ? 'secondary' : 'destructive'}>
                                            {tool.enabled ? 'Enabled' : 'Disabled'}
                                        </Badge>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        {tool.required_fields.length > 0 ? (
                                            <Badge variant="outline">
                                                Required: {tool.required_fields.join(', ')}
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">No required fields</Badge>
                                        )}
                                        <Badge variant="outline">{tool.parameter_count} parameters</Badge>
                                        <Badge variant="outline">{tool.actions.length} actions</Badge>
                                    </div>
                                </CardHeader>

                                <CardContent className="space-y-4">
                                    <div className="space-y-2">
                                        <p className="text-xs font-medium uppercase tracking-[0.22em] text-muted-foreground">
                                            Supported actions
                                        </p>
                                        {tool.actions.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">This tool uses free-form parameters without a declared action enum.</p>
                                        ) : (
                                            <div className="flex flex-wrap gap-2">
                                                {tool.actions.map((action) => (
                                                    <Badge key={action} variant="secondary" className="font-mono">
                                                        {action}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    <Collapsible>
                                        <CollapsibleTrigger className="w-full rounded-xl border bg-background/70 px-4 py-3 text-left text-sm font-medium transition-colors hover:bg-background">
                                            View parameters
                                        </CollapsibleTrigger>
                                        <CollapsibleContent className="mt-3 space-y-3">
                                            {tool.parameters.map((parameter) => (
                                                <div key={parameter.name} className="rounded-xl border bg-background/70 p-4">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="font-mono text-sm">{parameter.name}</p>
                                                        <Badge variant="outline">{parameter.type}</Badge>
                                                        {parameter.required ? (
                                                            <Badge variant="destructive">Required</Badge>
                                                        ) : (
                                                            <Badge variant="secondary">Optional</Badge>
                                                        )}
                                                    </div>

                                                    {parameter.description !== '' ? (
                                                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                                            {parameter.description}
                                                        </p>
                                                    ) : null}

                                                    {parameter.enum.length > 0 ? (
                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                            {parameter.enum.map((value) => (
                                                                <Badge key={value} variant="outline" className="font-mono">
                                                                    {value}
                                                                </Badge>
                                                            ))}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            ))}
                                        </CollapsibleContent>
                                    </Collapsible>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            </div>
        </AppLayout>
    );
}

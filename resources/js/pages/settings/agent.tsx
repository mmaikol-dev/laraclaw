import { Head } from '@inertiajs/react';
import { AlertTriangle, Bot, Globe, HardDrive, Save, ShieldAlert, TerminalSquare } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Toggle } from '@/components/ui/toggle';
import { api } from '@/lib/api';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { edit as editAgentSettings } from '@/routes/agent-settings';
import type { BreadcrumbItem } from '@/types';

type AgentSettingsPayload = {
    working_dir: string;
    allowed_paths: string;
    max_file_size_mb: number;
    enable_shell: boolean;
    enable_web: boolean;
    shell_timeout: number;
    temperature: string;
    context_length: number;
    system_prompt: string;
};

type AgentSettingsResponse = {
    data: AgentSettingsPayload;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Agent settings',
        href: editAgentSettings(),
    },
];

const emptySettings: AgentSettingsPayload = {
    working_dir: '/tmp/laraclaw',
    allowed_paths: '/tmp/laraclaw',
    max_file_size_mb: 10,
    enable_shell: true,
    enable_web: true,
    shell_timeout: 30,
    temperature: '0.7',
    context_length: 8192,
    system_prompt: '',
};

export default function AgentSettings() {
    const [settings, setSettings] = useState<AgentSettingsPayload>(emptySettings);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api<AgentSettingsResponse>('/settings')
            .then((response) => setSettings(response.data))
            .catch(() => setError('Unable to load agent settings right now.'))
            .finally(() => setIsLoading(false));
    }, []);

    function updateSetting<K extends keyof AgentSettingsPayload>(key: K, value: AgentSettingsPayload[K]): void {
        setSettings((current) => ({
            ...current,
            [key]: value,
        }));
    }

    async function handleSave(event: FormEvent<HTMLFormElement>): Promise<void> {
        event.preventDefault();
        setIsSaving(true);
        setNotice(null);
        setError(null);

        try {
            await api('/settings', {
                method: 'POST',
                body: JSON.stringify({
                    settings,
                }),
            });

            setNotice('Settings saved.');
        } catch {
            setError('Unable to save agent settings.');
        } finally {
            setIsSaving(false);
        }
    }

    async function handleDangerousAction(path: '/settings/tasks' | '/settings/conversations', confirmationMessage: string): Promise<void> {
        if (! window.confirm(confirmationMessage)) {
            return;
        }

        setError(null);
        setNotice(null);

        try {
            await api(path, {
                method: 'DELETE',
            });

            setNotice(path === '/settings/tasks' ? 'Task logs cleared.' : 'Conversation history archived.');
        } catch {
            setError('That action could not be completed.');
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agent settings" />

            <SettingsLayout>
                <form className="space-y-8" onSubmit={handleSave}>
                    <Heading
                        variant="small"
                        title="LaraClaw agent settings"
                        description="Control file access, tool toggles, model behavior, and the system prompt used for every conversation."
                    />

                    {isLoading ? (
                        <Card>
                            <CardContent className="p-6 text-sm text-muted-foreground">
                                Loading settings...
                            </CardContent>
                        </Card>
                    ) : (
                        <>
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-3">
                                        <HardDrive className="size-5 text-teal-600" />
                                        <div>
                                            <CardTitle>File system</CardTitle>
                                            <CardDescription>
                                                Define the default workspace and how much file access the agent gets.
                                            </CardDescription>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div className="space-y-2">
                                        <Label htmlFor="working_dir">Working directory</Label>
                                        <Input
                                            id="working_dir"
                                            value={settings.working_dir}
                                            onChange={(event) => updateSetting('working_dir', event.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="allowed_paths">Allowed paths</Label>
                                        <textarea
                                            id="allowed_paths"
                                            value={settings.allowed_paths}
                                            onChange={(event) => updateSetting('allowed_paths', event.target.value)}
                                            rows={4}
                                            className="w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Use a comma-separated list of absolute paths LaraClaw can touch.
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="max_file_size_mb">Max file size (MB)</Label>
                                        <Input
                                            id="max_file_size_mb"
                                            type="number"
                                            value={settings.max_file_size_mb}
                                            onChange={(event) => updateSetting('max_file_size_mb', Number(event.target.value))}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-3">
                                        <TerminalSquare className="size-5 text-amber-600" />
                                        <div>
                                            <CardTitle>Shell and web</CardTitle>
                                            <CardDescription>
                                                Enable or restrict the tools that can leave the chat context and act on the machine or the web.
                                            </CardDescription>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div className="flex items-center justify-between rounded-lg border p-4">
                                        <div>
                                            <Label htmlFor="enable_shell">Enable shell tool</Label>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Disable this to prevent LaraClaw from running commands.
                                            </p>
                                        </div>
                                        <Toggle
                                            id="enable_shell"
                                            pressed={settings.enable_shell}
                                            onPressedChange={(value) => updateSetting('enable_shell', value)}
                                            variant="outline"
                                        >
                                            {settings.enable_shell ? 'On' : 'Off'}
                                        </Toggle>
                                    </div>
                                    <div className="flex items-center justify-between rounded-lg border p-4">
                                        <div>
                                            <Label htmlFor="enable_web">Enable web tool</Label>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Disable this to stop external web search and page fetches.
                                            </p>
                                        </div>
                                        <Toggle
                                            id="enable_web"
                                            pressed={settings.enable_web}
                                            onPressedChange={(value) => updateSetting('enable_web', value)}
                                            variant="outline"
                                        >
                                            {settings.enable_web ? 'On' : 'Off'}
                                        </Toggle>
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="shell_timeout">Shell timeout (seconds)</Label>
                                        <Input
                                            id="shell_timeout"
                                            type="number"
                                            value={settings.shell_timeout}
                                            onChange={(event) => updateSetting('shell_timeout', Number(event.target.value))}
                                        />
                                    </div>
                                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                        <Globe className="size-4" />
                                        Get your free Brave Search API key at api.search.brave.com.
                                    </p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-3">
                                        <Bot className="size-5 text-sky-600" />
                                        <div>
                                            <CardTitle>Model parameters</CardTitle>
                                            <CardDescription>
                                                Tune creativity and context size for the active local model loop.
                                            </CardDescription>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="temperature">Temperature</Label>
                                            <span className="text-sm text-muted-foreground">{settings.temperature}</span>
                                        </div>
                                        <input
                                            id="temperature"
                                            type="range"
                                            min="0"
                                            max="1"
                                            step="0.1"
                                            value={settings.temperature}
                                            onChange={(event) => updateSetting('temperature', event.target.value)}
                                            className="w-full accent-teal-600"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="context_length">Context length</Label>
                                        <Input
                                            id="context_length"
                                            type="number"
                                            value={settings.context_length}
                                            onChange={(event) => updateSetting('context_length', Number(event.target.value))}
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <div className="flex items-center gap-3">
                                        <ShieldAlert className="size-5 text-rose-600" />
                                        <div>
                                            <CardTitle>System prompt</CardTitle>
                                            <CardDescription>
                                                This instruction set is injected at the start of each conversation before the model sees user messages.
                                            </CardDescription>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    <Label htmlFor="system_prompt">System prompt</Label>
                                    <textarea
                                        id="system_prompt"
                                        rows={10}
                                        value={settings.system_prompt}
                                        onChange={(event) => updateSetting('system_prompt', event.target.value)}
                                        className="w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                    />
                                </CardContent>
                            </Card>

                            <Card className="border-red-200/70">
                                <CardHeader>
                                    <div className="flex items-center gap-3">
                                        <AlertTriangle className="size-5 text-red-600" />
                                        <div>
                                            <CardTitle>Danger zone</CardTitle>
                                            <CardDescription>
                                                These actions affect stored LaraClaw history for the whole app.
                                            </CardDescription>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="flex flex-wrap gap-3">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            onClick={() =>
                                                handleDangerousAction(
                                                    '/settings/tasks',
                                                    'Clear all task logs? This cannot be undone.',
                                                )
                                            }
                                        >
                                            Clear task logs
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            onClick={() =>
                                                handleDangerousAction(
                                                    '/settings/conversations',
                                                    'Archive all conversations? This will hide the existing chat history.',
                                                )
                                            }
                                        >
                                            Archive conversations
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </>
                    )}

                    {notice ? <p className="text-sm text-emerald-600">{notice}</p> : null}
                    {error ? <p className="text-sm text-destructive">{error}</p> : null}

                    <div className="flex justify-end">
                        <Button type="submit" disabled={isSaving || isLoading}>
                            <Save className="size-4" />
                            {isSaving ? 'Saving...' : 'Save all settings'}
                        </Button>
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}

import { router } from '@inertiajs/react';
import { Loader2, Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

type WhenOption = 'manual' | 'daily' | 'weekly' | 'file' | 'url' | 'webhook';

type FormState = {
    task: string;
    when: WhenOption;
    time: string;
    day: string;
    directory: string;
    url: string;
    memory: string;
};

const WHEN_OPTIONS: { value: WhenOption; label: string }[] = [
    { value: 'manual', label: 'Right now / manually' },
    { value: 'daily', label: 'Every day at a set time' },
    { value: 'weekly', label: 'Once a week' },
    { value: 'file', label: 'When a file arrives in a folder' },
    { value: 'url', label: 'When a website changes' },
    { value: 'webhook', label: 'When a webhook fires' },
];

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const EMPTY: FormState = {
    task: '',
    when: 'manual',
    time: '09:00',
    day: 'monday',
    directory: '',
    url: '',
    memory: '',
};

async function apiPost<T>(path: string, body: Record<string, unknown>): Promise<T> {
    const res = await fetch(`/api/v1${path}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
    });
    if (!res.ok) throw new Error('Request failed');
    return res.json() as Promise<T>;
}

type Props = {
    open: boolean;
    onClose: () => void;
};

export function EmployeeFormModal({ open, onClose }: Props) {
    const [form, setForm] = useState<FormState>(EMPTY);
    const [enhancing, setEnhancing] = useState<Record<string, boolean>>({});
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function set<K extends keyof FormState>(key: K, value: FormState[K]) {
        setForm((f) => ({ ...f, [key]: value }));
    }

    async function enhance(field: 'task' | 'memory', context: string) {
        const text = form[field].trim();
        if (!text) return;
        setEnhancing((e) => ({ ...e, [field]: true }));
        try {
            const res = await apiPost<{ enhanced: string }>('/employee/enhance', { text, context });
            set(field, res.enhanced);
        } catch {
            // silently ignore
        } finally {
            setEnhancing((e) => ({ ...e, [field]: false }));
        }
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!form.task.trim()) return;
        setSubmitting(true);
        setError(null);
        try {
            const res = await apiPost<{ conversation_id: number; prompt: string }>('/employee/create', form);

            // Send the prompt as a message in the new conversation
            await fetch(`/api/v1/conversations/${res.conversation_id}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ message: res.prompt }),
            });

            onClose();
            setForm(EMPTY);
            router.visit(`/chat/${res.conversation_id}`);
        } catch {
            setError('Something went wrong. Please try again.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Set up a new employee task</DialogTitle>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-5 pt-1">
                    {/* What */}
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium">What should the agent do?</label>
                        <div className="relative">
                            <textarea
                                value={form.task}
                                onChange={(e) => set('task', e.target.value)}
                                rows={3}
                                placeholder="e.g. Organise new files in my Downloads folder by type"
                                className="w-full resize-none rounded-lg border bg-background px-3 py-2.5 pr-24 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => enhance('task', 'task description')}
                                disabled={!!enhancing['task'] || !form.task.trim()}
                                className="absolute right-2 top-2 flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-xs font-medium text-violet-600 hover:bg-violet-100 disabled:opacity-40 dark:bg-violet-950/40 dark:text-violet-400 dark:hover:bg-violet-950/60"
                            >
                                {enhancing['task'] ? <Loader2 className="h-3 w-3 animate-spin" /> : <Sparkles className="h-3 w-3" />}
                                Enhance
                            </button>
                        </div>
                    </div>

                    {/* When */}
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium">When should it run?</label>
                        <select
                            value={form.when}
                            onChange={(e) => set('when', e.target.value as WhenOption)}
                            className="w-full rounded-lg border bg-background px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                        >
                            {WHEN_OPTIONS.map((o) => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>
                    </div>

                    {/* Conditional fields */}
                    {(form.when === 'daily' || form.when === 'weekly') && (
                        <div className="flex gap-3">
                            {form.when === 'weekly' && (
                                <div className="flex-1 space-y-1.5">
                                    <label className="text-sm font-medium">Day</label>
                                    <select
                                        value={form.day}
                                        onChange={(e) => set('day', e.target.value)}
                                        className="w-full rounded-lg border bg-background px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                                    >
                                        {DAYS.map((d) => (
                                            <option key={d} value={d.toLowerCase()}>{d}</option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="flex-1 space-y-1.5">
                                <label className="text-sm font-medium">Time</label>
                                <input
                                    type="time"
                                    value={form.time}
                                    onChange={(e) => set('time', e.target.value)}
                                    className="w-full rounded-lg border bg-background px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                                />
                            </div>
                        </div>
                    )}

                    {form.when === 'file' && (
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Folder to watch</label>
                            <input
                                type="text"
                                value={form.directory}
                                onChange={(e) => set('directory', e.target.value)}
                                placeholder="e.g. /home/user/Downloads"
                                className="w-full rounded-lg border bg-background px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                                required
                            />
                        </div>
                    )}

                    {form.when === 'url' && (
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium">Website URL to monitor</label>
                            <input
                                type="url"
                                value={form.url}
                                onChange={(e) => set('url', e.target.value)}
                                placeholder="https://example.com/page"
                                className="w-full rounded-lg border bg-background px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                                required
                            />
                        </div>
                    )}

                    {/* Remember */}
                    <div className="space-y-1.5">
                        <label className="text-sm font-medium">
                            Anything to remember? <span className="font-normal text-muted-foreground">(optional)</span>
                        </label>
                        <div className="relative">
                            <textarea
                                value={form.memory}
                                onChange={(e) => set('memory', e.target.value)}
                                rows={2}
                                placeholder="e.g. I prefer PDFs over Word documents"
                                className="w-full resize-none rounded-lg border bg-background px-3 py-2.5 pr-24 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                            />
                            <button
                                type="button"
                                onClick={() => enhance('memory', 'preference or note')}
                                disabled={!!enhancing['memory'] || !form.memory.trim()}
                                className="absolute right-2 top-2 flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-xs font-medium text-violet-600 hover:bg-violet-100 disabled:opacity-40 dark:bg-violet-950/40 dark:text-violet-400 dark:hover:bg-violet-950/60"
                            >
                                {enhancing['memory'] ? <Loader2 className="h-3 w-3 animate-spin" /> : <Sparkles className="h-3 w-3" />}
                                Enhance
                            </button>
                        </div>
                    </div>

                    {error && <p className="text-sm text-destructive">{error}</p>}

                    <div className="flex justify-end gap-2 pt-1">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost" size="sm">Cancel</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            size="sm"
                            className="bg-teal-600 hover:bg-teal-700"
                            disabled={submitting || !form.task.trim()}
                        >
                            {submitting ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : null}
                            {submitting ? 'Setting up…' : 'Set up task'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

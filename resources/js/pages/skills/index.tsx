import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Bot,
    BookOpen,
    ChevronDown,
    Code2,
    Database,
    Edit3,
    FileCode2,
    FlaskConical,
    Globe,
    LayoutGrid,
    MessageSquare,
    Pencil,
    Plus,
    Settings2,
    Sparkles,
    Terminal,
    Trash2,
    User,
    X,
    Zap,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import { index as skillsRoute } from '@/routes/skills';
import type { BreadcrumbItem } from '@/types';

type SkillScript = {
    id: number;
    filename: string;
    description: string;
    content: string;
};

type Skill = {
    id: number;
    name: string;
    description: string;
    category: string;
    instructions: string;
    is_active: boolean;
    created_by: 'user' | 'agent';
    usage_count: number;
    scripts: SkillScript[];
    created_at: string | null;
    updated_at: string | null;
};

type Props = {
    skills: Skill[];
    categories: string[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Skills', href: skillsRoute() }];

const CATEGORY_META: Record<string, { icon: React.ElementType; color: string; bg: string }> = {
    coding: { icon: Code2, color: 'text-blue-500', bg: 'bg-blue-50 dark:bg-blue-950/40' },
    research: { icon: Globe, color: 'text-teal-500', bg: 'bg-teal-50 dark:bg-teal-950/40' },
    writing: { icon: BookOpen, color: 'text-violet-500', bg: 'bg-violet-50 dark:bg-violet-950/40' },
    system: { icon: Terminal, color: 'text-orange-500', bg: 'bg-orange-50 dark:bg-orange-950/40' },
    data: { icon: Database, color: 'text-cyan-500', bg: 'bg-cyan-50 dark:bg-cyan-950/40' },
    analysis: { icon: FlaskConical, color: 'text-pink-500', bg: 'bg-pink-50 dark:bg-pink-950/40' },
    communication: { icon: MessageSquare, color: 'text-emerald-500', bg: 'bg-emerald-50 dark:bg-emerald-950/40' },
    general: { icon: LayoutGrid, color: 'text-muted-foreground', bg: 'bg-muted/50' },
};

const EMPTY_FORM = { name: '', description: '', category: 'general', instructions: '', is_active: true };
const EMPTY_SCRIPT_FORM = { filename: '', description: '', content: '' };

export default function SkillsIndex({ skills, categories }: Props) {
    const [selectedCategory, setSelectedCategory] = useState<string | null>(null);

    // skill modal state
    const [showForm, setShowForm] = useState(false);
    const [editingSkill, setEditingSkill] = useState<Skill | null>(null);
    const [form, setForm] = useState(EMPTY_FORM);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    // script modal state (lifted here so modal renders outside Collapsible)
    const [scriptForm, setScriptForm] = useState<typeof EMPTY_SCRIPT_FORM | null>(null);
    const [scriptSkillId, setScriptSkillId] = useState<number | null>(null);
    const [editingScript, setEditingScript] = useState<SkillScript | null>(null);
    const [scriptErrors, setScriptErrors] = useState<Record<string, string>>({});
    const [savingScript, setSavingScript] = useState(false);
    const [deletingScriptId, setDeletingScriptId] = useState<number | null>(null);

    const filtered = selectedCategory ? skills.filter((s) => s.category === selectedCategory) : skills;

    const categoryGroups = categories.reduce<Record<string, number>>((acc, cat) => {
        acc[cat] = skills.filter((s) => s.category === cat).length;
        return acc;
    }, {});

    function openCreate(): void {
        setEditingSkill(null);
        setForm(EMPTY_FORM);
        setErrors({});
        setShowForm(true);
    }

    function openEdit(skill: Skill): void {
        setEditingSkill(skill);
        setForm({
            name: skill.name,
            description: skill.description,
            category: skill.category,
            instructions: skill.instructions,
            is_active: skill.is_active,
        });
        setErrors({});
        setShowForm(true);
    }

    function closeForm(): void {
        setShowForm(false);
        setEditingSkill(null);
        setErrors({});
    }

    async function handleSave(): Promise<void> {
        setErrors({});
        setSaving(true);

        try {
            const url = editingSkill ? `/api/v1/skills/${editingSkill.id}` : '/api/v1/skills';
            const method = editingSkill ? 'PUT' : 'POST';

            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify(form),
            });

            if (res.status === 422) {
                const body = await res.json() as { errors: Record<string, string[]> };
                const flat: Record<string, string> = {};
                for (const [key, msgs] of Object.entries(body.errors)) {
                    flat[key] = msgs[0] ?? '';
                }
                setErrors(flat);
                return;
            }

            if (!res.ok) {
                throw new Error('Save failed');
            }

            closeForm();
            router.reload({ only: ['skills'] });
        } catch {
            setErrors({ general: 'Failed to save skill. Please try again.' });
        } finally {
            setSaving(false);
        }
    }

    async function handleDelete(skill: Skill): Promise<void> {
        if (!confirm(`Delete skill "${skill.name}"? This cannot be undone.`)) {
            return;
        }
        setDeletingId(skill.id);
        try {
            await fetch(`/api/v1/skills/${skill.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });
            router.reload({ only: ['skills'] });
        } finally {
            setDeletingId(null);
        }
    }

    async function handleToggle(skill: Skill): Promise<void> {
        await fetch(`/api/v1/skills/${skill.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ is_active: !skill.is_active }),
        });
        router.reload({ only: ['skills'] });
    }

    function openAddScript(skill: Skill): void {
        setScriptSkillId(skill.id);
        setEditingScript(null);
        setScriptForm(EMPTY_SCRIPT_FORM);
        setScriptErrors({});
    }

    function openEditScript(skill: Skill, script: SkillScript): void {
        setScriptSkillId(skill.id);
        setEditingScript(script);
        setScriptForm({ filename: script.filename, description: script.description, content: script.content });
        setScriptErrors({});
    }

    function closeScriptForm(): void {
        setScriptForm(null);
        setScriptSkillId(null);
        setEditingScript(null);
        setScriptErrors({});
    }

    async function handleSaveScript(): Promise<void> {
        if (!scriptForm || !scriptSkillId) { return; }
        setScriptErrors({});
        setSavingScript(true);
        try {
            const url = editingScript
                ? `/api/v1/skills/${scriptSkillId}/scripts/${editingScript.id}`
                : `/api/v1/skills/${scriptSkillId}/scripts`;
            const method = editingScript ? 'PUT' : 'POST';
            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                body: JSON.stringify(scriptForm),
            });
            if (res.status === 422) {
                const body = await res.json() as { errors: Record<string, string[]> };
                const flat: Record<string, string> = {};
                for (const [key, msgs] of Object.entries(body.errors)) {
                    flat[key] = msgs[0] ?? '';
                }
                setScriptErrors(flat);
                return;
            }
            if (!res.ok) { throw new Error('Save failed'); }
            closeScriptForm();
            router.reload({ only: ['skills'] });
        } catch {
            setScriptErrors({ general: 'Failed to save script. Please try again.' });
        } finally {
            setSavingScript(false);
        }
    }

    async function handleDeleteScript(skill: Skill, script: SkillScript): Promise<void> {
        if (!confirm(`Delete script "${script.filename}"?`)) { return; }
        setDeletingScriptId(script.id);
        try {
            await fetch(`/api/v1/skills/${skill.id}/scripts/${script.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken() },
            });
            router.reload({ only: ['skills'] });
        } finally {
            setDeletingScriptId(null);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Skills" />

            <div className="flex h-full overflow-hidden">
                {/* ── Category sidebar ── */}
                <aside className="flex w-56 shrink-0 flex-col border-r bg-sidebar">
                    <div className="border-b px-3 py-2.5">
                        <span className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <Zap className="size-3.5 text-teal-500" />
                            Categories
                        </span>
                    </div>

                    <div className="flex-1 overflow-y-auto py-1">
                        <ul className="space-y-px px-2 py-1">
                            <li>
                                <button
                                    onClick={() => setSelectedCategory(null)}
                                    className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors ${
                                        selectedCategory === null
                                            ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300'
                                            : 'text-foreground hover:bg-muted/60'
                                    }`}
                                >
                                    <Sparkles className="size-3.5 shrink-0 text-muted-foreground" />
                                    <span className="grow">All skills</span>
                                    <span className="text-xs text-muted-foreground">{skills.length}</span>
                                </button>
                            </li>

                            {categories.map((cat) => {
                                const meta = CATEGORY_META[cat] ?? CATEGORY_META.general;
                                const Icon = meta.icon;
                                const count = categoryGroups[cat] ?? 0;

                                return (
                                    <li key={cat}>
                                        <button
                                            onClick={() => setSelectedCategory(cat)}
                                            className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm capitalize transition-colors ${
                                                selectedCategory === cat
                                                    ? 'bg-teal-50 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300'
                                                    : 'text-foreground hover:bg-muted/60'
                                            }`}
                                        >
                                            <Icon className={`size-3.5 shrink-0 ${meta.color}`} />
                                            <span className="grow">{cat}</span>
                                            {count > 0 && (
                                                <span className="text-xs text-muted-foreground">{count}</span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    <div className="border-t p-2">
                        <Button onClick={openCreate} size="sm" className="w-full gap-1.5 bg-teal-600 hover:bg-teal-700">
                            <Plus className="size-3.5" />
                            New skill
                        </Button>
                    </div>
                </aside>

                {/* ── Main content ── */}
                <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <div className="flex-1 overflow-y-auto px-6 py-5">
                        {filtered.length === 0 ? (
                            <div className="flex min-h-[50vh] flex-col items-center justify-center gap-4 text-center">
                                <div className="rounded-2xl bg-teal-50 p-5 text-teal-600 dark:bg-teal-950/50 dark:text-teal-400">
                                    <Sparkles className="size-10" />
                                </div>
                                <div className="space-y-1">
                                    <h2 className="text-base font-semibold">No skills yet</h2>
                                    <p className="text-sm text-muted-foreground">
                                        Create a skill to teach the AI how to handle specific tasks.
                                        <br />
                                        The agent can also create and update skills itself.
                                    </p>
                                </div>
                                <Button onClick={openCreate} className="mt-2 gap-1.5 bg-teal-600 hover:bg-teal-700">
                                    <Plus className="size-4" />
                                    Create first skill
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h1 className="text-sm font-semibold text-muted-foreground">
                                        {selectedCategory
                                            ? `${selectedCategory} · ${filtered.length} skill${filtered.length !== 1 ? 's' : ''}`
                                            : `All skills · ${filtered.length}`}
                                    </h1>
                                </div>

                                {filtered.map((skill) => (
                                    <SkillCard
                                        key={skill.id}
                                        skill={skill}
                                        onEdit={() => openEdit(skill)}
                                        onDelete={() => void handleDelete(skill)}
                                        onToggle={() => void handleToggle(skill)}
                                        deleting={deletingId === skill.id}
                                        onAddScript={() => openAddScript(skill)}
                                        onEditScript={(script) => openEditScript(skill, script)}
                                        onDeleteScript={(script) => void handleDeleteScript(skill, script)}
                                        deletingScriptId={deletingScriptId}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* ── Create / Edit skill modal ── */}
            {showForm && (
                <SkillFormModal
                    form={form}
                    errors={errors}
                    categories={categories}
                    isEdit={editingSkill !== null}
                    saving={saving}
                    onChange={(field, value) => setForm((f) => ({ ...f, [field]: value }))}
                    onSave={() => void handleSave()}
                    onClose={closeForm}
                />
            )}

            {/* ── Create / Edit script modal ── */}
            {scriptForm !== null && (
                <ScriptFormModal
                    form={scriptForm}
                    errors={scriptErrors}
                    isEdit={editingScript !== null}
                    saving={savingScript}
                    onChange={(field, value) => setScriptForm((f) => f ? { ...f, [field]: value } : f)}
                    onSave={() => void handleSaveScript()}
                    onClose={closeScriptForm}
                />
            )}
        </AppLayout>
    );
}

// ── Skill card ────────────────────────────────────────────────────────────────

function SkillCard({
    skill,
    onEdit,
    onDelete,
    onToggle,
    deleting,
    onAddScript,
    onEditScript,
    onDeleteScript,
    deletingScriptId,
}: {
    skill: Skill;
    onEdit: () => void;
    onDelete: () => void;
    onToggle: () => void;
    deleting: boolean;
    onAddScript: () => void;
    onEditScript: (script: SkillScript) => void;
    onDeleteScript: (script: SkillScript) => void;
    deletingScriptId: number | null;
}) {
    const meta = CATEGORY_META[skill.category] ?? CATEGORY_META.general;
    const Icon = meta.icon;

    return (
        <Collapsible>
                <div className={`overflow-hidden rounded-xl border transition-opacity ${!skill.is_active ? 'opacity-60' : ''}`}>
                    {/* Header row */}
                    <div className="flex items-center gap-3 px-4 py-3">
                        <div className={`flex size-8 shrink-0 items-center justify-center rounded-lg ${meta.bg}`}>
                            <Icon className={`size-4 ${meta.color}`} />
                        </div>

                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="truncate font-medium text-sm">{skill.name}</span>
                                {skill.created_by === 'agent' && (
                                    <Badge variant="secondary" className="gap-1 text-[10px] py-0">
                                        <Bot className="size-2.5" />
                                        AI
                                    </Badge>
                                )}
                                {skill.created_by === 'user' && (
                                    <Badge variant="outline" className="gap-1 text-[10px] py-0">
                                        <User className="size-2.5" />
                                        You
                                    </Badge>
                                )}
                                <Badge
                                    variant={skill.is_active ? 'default' : 'secondary'}
                                    className={`ml-auto text-[10px] py-0 ${skill.is_active ? 'bg-teal-600' : ''}`}
                                >
                                    {skill.is_active ? 'Active' : 'Inactive'}
                                </Badge>
                            </div>
                            <p className="truncate text-xs text-muted-foreground">{skill.description}</p>
                        </div>

                        <div className="flex shrink-0 items-center gap-1">
                            {skill.usage_count > 0 && (
                                <span className="text-[10px] text-muted-foreground/60">{skill.usage_count}×</span>
                            )}
                            <Button variant="ghost" size="icon" className="size-7" onClick={onToggle} title={skill.is_active ? 'Deactivate' : 'Activate'}>
                                <Settings2 className="size-3.5 text-muted-foreground" />
                            </Button>
                            <Button variant="ghost" size="icon" className="size-7" onClick={onEdit}>
                                <Pencil className="size-3.5 text-muted-foreground" />
                            </Button>
                            <Button variant="ghost" size="icon" className="size-7" onClick={onDelete} disabled={deleting}>
                                <Trash2 className="size-3.5 text-muted-foreground hover:text-destructive" />
                            </Button>
                            <CollapsibleTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-7">
                                    <ChevronDown className="size-3.5 text-muted-foreground transition-transform [[data-state=open]_&]:rotate-180" />
                                </Button>
                            </CollapsibleTrigger>
                        </div>
                    </div>

                    {/* Expandable instructions + scripts */}
                    <CollapsibleContent>
                        <div className="border-t bg-muted/30 px-4 py-3">
                            <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Instructions</p>
                            <pre className="whitespace-pre-wrap font-mono text-xs leading-relaxed text-foreground">{skill.instructions}</pre>
                        </div>

                        <div className="border-t bg-amber-50/40 px-4 py-3 dark:bg-amber-950/20">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    <FileCode2 className="size-3 text-amber-500" />
                                    Scripts {skill.scripts.length > 0 && `(${skill.scripts.length})`}
                                </p>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-6 gap-1 px-2 text-[11px] text-amber-600 hover:bg-amber-100 hover:text-amber-700 dark:text-amber-400 dark:hover:bg-amber-900/40"
                                    onClick={onAddScript}
                                >
                                    <Plus className="size-3" />
                                    Add script
                                </Button>
                            </div>

                            {skill.scripts.length === 0 ? (
                                <p className="text-[11px] text-muted-foreground/70">
                                    No scripts yet. Scripts are files the agent can run as part of this skill — Python, Bash, JavaScript, etc.
                                </p>
                            ) : (
                                <div className="space-y-1.5">
                                    {skill.scripts.map((script) => (
                                        <div
                                            key={script.id}
                                            className="flex items-center gap-2 rounded-lg border border-amber-200/60 bg-white/60 px-3 py-2 dark:border-amber-800/40 dark:bg-amber-950/30"
                                        >
                                            <FileCode2 className="size-3.5 shrink-0 text-amber-500" />
                                            <div className="min-w-0 flex-1">
                                                <span className="font-mono text-xs font-medium text-amber-700 dark:text-amber-300">{script.filename}</span>
                                                {script.description && (
                                                    <p className="truncate text-[11px] text-muted-foreground">{script.description}</p>
                                                )}
                                            </div>
                                            <div className="flex shrink-0 items-center gap-0.5">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-6 text-muted-foreground hover:text-foreground"
                                                    onClick={() => onEditScript(script)}
                                                >
                                                    <Pencil className="size-3" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-6 text-muted-foreground hover:text-destructive"
                                                    onClick={() => onDeleteScript(script)}
                                                    disabled={deletingScriptId === script.id}
                                                >
                                                    <Trash2 className="size-3" />
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </CollapsibleContent>
                </div>
            </Collapsible>
    );
}

// ── Script form modal ─────────────────────────────────────────────────────────

function ScriptFormModal({
    form,
    errors,
    isEdit,
    saving,
    onChange,
    onSave,
    onClose,
}: {
    form: typeof EMPTY_SCRIPT_FORM;
    errors: Record<string, string>;
    isEdit: boolean;
    saving: boolean;
    onChange: (field: string, value: string) => void;
    onSave: () => void;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border bg-background shadow-2xl">
                {/* Header */}
                <div className="flex items-center justify-between border-b px-5 py-4">
                    <div className="flex items-center gap-2">
                        <FileCode2 className="size-4 text-amber-500" />
                        <h2 className="font-semibold">{isEdit ? 'Edit script' : 'Add script'}</h2>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="size-4" />
                    </button>
                </div>

                {/* Body */}
                <div className="flex-1 overflow-y-auto space-y-4 px-5 py-4">
                    {errors.general && (
                        <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{errors.general}</p>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2 sm:col-span-1">
                            <label className="mb-1.5 block text-xs font-medium">Filename</label>
                            <input
                                value={form.filename}
                                onChange={(e) => onChange('filename', e.target.value)}
                                placeholder="e.g. extract.py, run.sh, process.js"
                                className="w-full rounded-xl border bg-muted/30 px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-amber-500/40"
                            />
                            {errors.filename && <p className="mt-1 text-xs text-destructive">{errors.filename}</p>}
                        </div>

                        <div className="col-span-2 sm:col-span-1">
                            <label className="mb-1.5 block text-xs font-medium">Description <span className="font-normal text-muted-foreground">(optional)</span></label>
                            <input
                                value={form.description}
                                onChange={(e) => onChange('description', e.target.value)}
                                placeholder="What does this script do?"
                                className="w-full rounded-xl border bg-muted/30 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-500/40"
                            />
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-xs font-medium">Content</label>
                        <textarea
                            value={form.content}
                            onChange={(e) => onChange('content', e.target.value)}
                            rows={18}
                            spellCheck={false}
                            placeholder="#!/usr/bin/env python3&#10;# Script content here..."
                            className="w-full rounded-xl border bg-zinc-950 px-4 py-3 font-mono text-xs leading-relaxed text-zinc-100 outline-none focus:ring-2 focus:ring-amber-500/40 dark:bg-zinc-900"
                        />
                        {errors.content && <p className="mt-1 text-xs text-destructive">{errors.content}</p>}
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-2 border-t px-5 py-3">
                    <Button variant="ghost" size="sm" onClick={onClose} disabled={saving}>Cancel</Button>
                    <Button
                        size="sm"
                        onClick={onSave}
                        disabled={saving}
                        className="bg-amber-600 hover:bg-amber-700"
                    >
                        {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Add script'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

// ── Skill form modal ──────────────────────────────────────────────────────────

function SkillFormModal({
    form,
    errors,
    categories,
    isEdit,
    saving,
    onChange,
    onSave,
    onClose,
}: {
    form: typeof EMPTY_FORM;
    errors: Record<string, string>;
    categories: string[];
    isEdit: boolean;
    saving: boolean;
    onChange: (field: string, value: string | boolean) => void;
    onSave: () => void;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm">
            <div className="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border bg-background shadow-2xl">
                {/* Modal header */}
                <div className="flex items-center justify-between border-b px-5 py-4">
                    <div className="flex items-center gap-2">
                        <Edit3 className="size-4 text-teal-500" />
                        <h2 className="font-semibold">{isEdit ? 'Edit skill' : 'New skill'}</h2>
                    </div>
                    <button onClick={onClose} className="rounded-lg p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="size-4" />
                    </button>
                </div>

                {/* Form body */}
                <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    {errors.general && (
                        <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{errors.general}</p>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="col-span-2">
                            <label className="mb-1.5 block text-xs font-medium">Name</label>
                            <input
                                value={form.name}
                                onChange={(e) => onChange('name', e.target.value)}
                                placeholder="e.g. debug-php-error"
                                className="w-full rounded-xl border bg-muted/30 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                            />
                            {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
                        </div>

                        <div className="col-span-2">
                            <label className="mb-1.5 block text-xs font-medium">Description</label>
                            <input
                                value={form.description}
                                onChange={(e) => onChange('description', e.target.value)}
                                placeholder="One sentence — what does this skill do?"
                                className="w-full rounded-xl border bg-muted/30 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-500/40"
                            />
                            {errors.description && <p className="mt-1 text-xs text-destructive">{errors.description}</p>}
                        </div>

                        <div>
                            <label className="mb-1.5 block text-xs font-medium">Category</label>
                            <select
                                value={form.category}
                                onChange={(e) => onChange('category', e.target.value)}
                                className="w-full rounded-xl border bg-muted/30 px-3 py-2 text-sm outline-none capitalize focus:ring-2 focus:ring-teal-500/40"
                            >
                                {categories.map((c) => (
                                    <option key={c} value={c} className="capitalize">{c}</option>
                                ))}
                            </select>
                        </div>

                        <div className="flex items-end">
                            <label className="flex cursor-pointer items-center gap-2.5">
                                <div
                                    onClick={() => onChange('is_active', !form.is_active)}
                                    className={`relative h-5 w-9 rounded-full transition-colors ${form.is_active ? 'bg-teal-500' : 'bg-muted-foreground/30'}`}
                                >
                                    <span
                                        className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform ${form.is_active ? 'translate-x-4' : 'translate-x-0.5'}`}
                                    />
                                </div>
                                <span className="text-sm font-medium">Active</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label className="mb-1.5 block text-xs font-medium">Instructions</label>
                        <p className="mb-2 text-xs text-muted-foreground">
                            Step-by-step instructions the AI will follow when applying this skill. Be specific and detailed.
                        </p>
                        <textarea
                            value={form.instructions}
                            onChange={(e) => onChange('instructions', e.target.value)}
                            rows={10}
                            placeholder="1. First, understand the problem by...\n2. Then check...\n3. Finally..."
                            className="w-full rounded-xl border bg-muted/30 px-3 py-2 font-mono text-xs leading-relaxed outline-none focus:ring-2 focus:ring-teal-500/40"
                        />
                        {errors.instructions && <p className="mt-1 text-xs text-destructive">{errors.instructions}</p>}
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-2 border-t px-5 py-3">
                    <Button variant="ghost" size="sm" onClick={onClose} disabled={saving}>Cancel</Button>
                    <Button
                        size="sm"
                        onClick={onSave}
                        disabled={saving}
                        className="bg-teal-600 hover:bg-teal-700"
                    >
                        {saving ? 'Saving…' : isEdit ? 'Save changes' : 'Create skill'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

function csrfToken(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
}

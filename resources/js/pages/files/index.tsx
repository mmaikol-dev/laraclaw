import { Head } from '@inertiajs/react';
import {
    Braces,
    ChevronRight,
    Database,
    File,
    FileCode,
    FileImage,
    FileLock,
    FileText,
    Folder,
    FolderOpen,
    FolderPlus,
    Home,
    ImageIcon,
    LoaderCircle,
    MoreHorizontal,
    Plus,
    Save,
    Terminal,
    Trash2,
} from 'lucide-react';
import { KeyboardEvent, useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import AppLayout from '@/layouts/app-layout';
import { index as filesRoute } from '@/routes/files';
import type { BreadcrumbItem } from '@/types';

type FileItem = {
    name: string;
    path: string;
    type: 'file' | 'directory';
    size: number | null;
    extension: string | null;
    modified_at: string;
};

type BrowseResponse = { path: string; parent: string | null; items: FileItem[] };
type ReadResponse = { path: string; content: string; extension: string | null; size: number };
type SelectedFile = { path: string; content: string; extension: string | null; originalContent: string };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Files', href: filesRoute() }];

export default function FilesIndex() {
    const [currentPath, setCurrentPath] = useState('/tmp/laraclaw');
    const [parentPath, setParentPath] = useState<string | null>(null);
    const [items, setItems] = useState<FileItem[]>([]);
    const [selectedFile, setSelectedFile] = useState<SelectedFile | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const noticeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const isDirty = selectedFile !== null && selectedFile.content !== selectedFile.originalContent;

    function showNotice(msg: string) {
        setNotice(msg);
        setError(null);
        if (noticeTimer.current) {
            clearTimeout(noticeTimer.current);
        }
        noticeTimer.current = setTimeout(() => setNotice(null), 3000);
    }

    async function loadDirectory(path: string): Promise<void> {
        setIsLoading(true);
        setError(null);
        try {
            const res = await api<BrowseResponse>(`/files/browse?path=${encodeURIComponent(path)}`);
            setCurrentPath(res.path);
            setParentPath(res.parent);
            setItems(res.items);
        } catch {
            setError('Unable to browse that path.');
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        void loadDirectory(currentPath);
    }, []);

    async function openFile(path: string): Promise<void> {
        setError(null);
        try {
            const res = await api<ReadResponse>(`/files/read?path=${encodeURIComponent(path)}`);
            setSelectedFile({ path: res.path, content: res.content, originalContent: res.content, extension: res.extension });
        } catch {
            setError('Unable to read that file.');
        }
    }

    async function handleSave(): Promise<void> {
        if (selectedFile === null) {
            return;
        }
        setIsSaving(true);
        try {
            await api('/files/write', {
                method: 'POST',
                body: JSON.stringify({ path: selectedFile.path, content: selectedFile.content }),
            });
            setSelectedFile({ ...selectedFile, originalContent: selectedFile.content });
            showNotice('File saved.');
            void loadDirectory(currentPath);
        } catch {
            setError('Unable to save that file.');
        } finally {
            setIsSaving(false);
        }
    }

    async function createFile(): Promise<void> {
        const filename = window.prompt('New file name');
        if (!filename) {
            return;
        }
        try {
            await api('/files/create', {
                method: 'POST',
                body: JSON.stringify({ path: `${currentPath}/${filename}` }),
            });
            showNotice(`Created ${filename}`);
            void loadDirectory(currentPath);
        } catch {
            setError('Unable to create that file.');
        }
    }

    async function createDirectory(): Promise<void> {
        const name = window.prompt('New folder name');
        if (!name) {
            return;
        }
        try {
            await api('/files/mkdir', {
                method: 'POST',
                body: JSON.stringify({ path: `${currentPath}/${name}` }),
            });
            showNotice(`Created ${name}/`);
            void loadDirectory(currentPath);
        } catch {
            setError('Unable to create that folder.');
        }
    }

    async function renameItem(item: FileItem): Promise<void> {
        const newName = window.prompt('Rename to', item.name);
        if (!newName || newName === item.name) {
            return;
        }
        const destination = `${currentPath}/${newName}`;
        try {
            await api('/files/move', {
                method: 'POST',
                body: JSON.stringify({ path: item.path, destination }),
            });
            showNotice('Renamed.');
            void loadDirectory(currentPath);
            if (selectedFile?.path === item.path) {
                setSelectedFile({ ...selectedFile, path: destination });
            }
        } catch {
            setError('Unable to rename that item.');
        }
    }

    async function deleteItem(item: FileItem): Promise<void> {
        if (!window.confirm(`Delete "${item.name}"?`)) {
            return;
        }
        try {
            await api(`/files?path=${encodeURIComponent(item.path)}`, { method: 'DELETE' });
            showNotice('Deleted.');
            void loadDirectory(currentPath);
            if (selectedFile?.path === item.path) {
                setSelectedFile(null);
            }
        } catch {
            setError('Unable to delete that item.');
        }
    }

    function onEditorKeyDown(event: KeyboardEvent<HTMLTextAreaElement>): void {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            void handleSave();
        }
        if (event.key === 'Tab') {
            event.preventDefault();
            const el = event.currentTarget;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const newContent = selectedFile!.content.substring(0, start) + '    ' + selectedFile!.content.substring(end);
            setSelectedFile({ ...selectedFile!, content: newContent });
            requestAnimationFrame(() => {
                el.selectionStart = el.selectionEnd = start + 4;
            });
        }
    }

    const pathSegments = useMemo(() => {
        const parts = currentPath.split('/').filter(Boolean);
        return parts.map((part, i) => ({
            label: part,
            path: '/' + parts.slice(0, i + 1).join('/'),
        }));
    }, [currentPath]);

    const folders = items.filter((i) => i.type === 'directory');
    const files = items.filter((i) => i.type === 'file');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Files" />

            <div className="flex h-full flex-col">
                {/* Toast notifications */}
                {(notice !== null || error !== null) && (
                    <div className={`mx-4 mt-3 rounded-lg border px-4 py-2 text-sm ${
                        error !== null
                            ? 'border-destructive/30 bg-destructive/10 text-destructive'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-400'
                    }`}>
                        {error ?? notice}
                    </div>
                )}

                <div className="flex flex-1 overflow-hidden gap-0">
                    {/* ── File explorer panel ── */}
                    <div className="flex w-72 shrink-0 flex-col border-r bg-background">
                        {/* Toolbar */}
                        <div className="flex items-center gap-1.5 border-b px-3 py-2.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => parentPath && void loadDirectory(parentPath)}
                                disabled={parentPath === null}
                                title="Go up"
                            >
                                <Home className="size-3.5" />
                            </Button>
                            <div className="h-4 w-px bg-border" />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => void createFile()}
                                title="New file"
                            >
                                <Plus className="size-3.5" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={() => void createDirectory()}
                                title="New folder"
                            >
                                <FolderPlus className="size-3.5" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="ml-auto size-7"
                                onClick={() => void loadDirectory(currentPath)}
                                title="Refresh"
                            >
                                <LoaderCircle className={`size-3.5 ${isLoading ? 'animate-spin' : ''}`} />
                            </Button>
                        </div>

                        {/* Breadcrumb path */}
                        <div className="flex items-center gap-0.5 overflow-x-auto border-b bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground">
                            <button
                                type="button"
                                className="shrink-0 hover:text-foreground"
                                onClick={() => void loadDirectory('/')}
                            >
                                /
                            </button>
                            {pathSegments.map((seg, i) => (
                                <span key={seg.path} className="flex items-center gap-0.5">
                                    <ChevronRight className="size-3 opacity-40" />
                                    <button
                                        type="button"
                                        className={`shrink-0 truncate hover:text-foreground ${
                                            i === pathSegments.length - 1 ? 'font-medium text-foreground' : ''
                                        }`}
                                        onClick={() => void loadDirectory(seg.path)}
                                    >
                                        {seg.label}
                                    </button>
                                </span>
                            ))}
                        </div>

                        {/* File list */}
                        <div className="flex-1 overflow-y-auto py-1">
                            {isLoading ? (
                                <div className="space-y-1 px-2 py-2">
                                    {Array.from({ length: 7 }).map((_, i) => (
                                        <Skeleton key={i} className="h-8 rounded-md" />
                                    ))}
                                </div>
                            ) : items.length === 0 ? (
                                <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                                    <FolderOpen className="size-8 text-muted-foreground/40" />
                                    <p className="text-xs text-muted-foreground">Empty folder</p>
                                </div>
                            ) : (
                                <>
                                    {/* Folders first */}
                                    {folders.length > 0 && (
                                        <div className="px-1">
                                            {folders.map((item) => (
                                                <FileRow
                                                    key={item.path}
                                                    item={item}
                                                    isSelected={selectedFile?.path === item.path}
                                                    onOpen={() => void loadDirectory(item.path)}
                                                    onRename={() => void renameItem(item)}
                                                    onDelete={() => void deleteItem(item)}
                                                />
                                            ))}
                                        </div>
                                    )}

                                    {/* Divider between folders and files */}
                                    {folders.length > 0 && files.length > 0 && (
                                        <div className="mx-3 my-1 border-t border-dashed opacity-40" />
                                    )}

                                    {/* Files */}
                                    {files.length > 0 && (
                                        <div className="px-1">
                                            {files.map((item) => (
                                                <FileRow
                                                    key={item.path}
                                                    item={item}
                                                    isSelected={selectedFile?.path === item.path}
                                                    onOpen={() => void openFile(item.path)}
                                                    onRename={() => void renameItem(item)}
                                                    onDelete={() => void deleteItem(item)}
                                                />
                                            ))}
                                        </div>
                                    )}
                                </>
                            )}
                        </div>

                        {/* Footer: item count */}
                        <div className="border-t px-3 py-1.5 text-[11px] text-muted-foreground">
                            {folders.length > 0 && `${folders.length} folder${folders.length !== 1 ? 's' : ''}`}
                            {folders.length > 0 && files.length > 0 && ' · '}
                            {files.length > 0 && `${files.length} file${files.length !== 1 ? 's' : ''}`}
                            {items.length === 0 && !isLoading && 'Empty'}
                        </div>
                    </div>

                    {/* ── Editor panel ── */}
                    <div className="flex flex-1 flex-col overflow-hidden">
                        {selectedFile === null ? (
                            <EmptyEditor />
                        ) : (
                            <>
                                {/* Editor toolbar */}
                                <div className="flex items-center gap-3 border-b bg-muted/20 px-4 py-2.5">
                                    <FileTypeIcon extension={selectedFile.extension} size="sm" />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium leading-tight">
                                            {selectedFile.path.split('/').pop()}
                                        </p>
                                        <p className="truncate text-[11px] text-muted-foreground">
                                            {selectedFile.path}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {isDirty && (
                                            <span className="size-2 rounded-full bg-amber-400" title="Unsaved changes" />
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="text-muted-foreground"
                                            onClick={() => setSelectedFile(null)}
                                        >
                                            Close
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() => void handleSave()}
                                            disabled={!isDirty || isSaving}
                                        >
                                            {isSaving ? (
                                                <LoaderCircle className="size-3.5 animate-spin" />
                                            ) : (
                                                <Save className="size-3.5" />
                                            )}
                                            Save
                                        </Button>
                                    </div>
                                </div>

                                {/* Hint bar */}
                                <div className="border-b bg-muted/10 px-4 py-1 text-[11px] text-muted-foreground">
                                    Ctrl+S to save · Tab inserts 4 spaces
                                </div>

                                {/* Textarea editor */}
                                <textarea
                                    value={selectedFile.content}
                                    onChange={(e) => setSelectedFile({ ...selectedFile, content: e.target.value })}
                                    onKeyDown={onEditorKeyDown}
                                    className="flex-1 resize-none border-0 bg-background p-4 font-mono text-sm leading-6 outline-none"
                                    spellCheck={false}
                                />
                            </>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function FileRow({
    item,
    isSelected,
    onOpen,
    onRename,
    onDelete,
}: {
    item: FileItem;
    isSelected: boolean;
    onOpen: () => void;
    onRename: () => void;
    onDelete: () => void;
}) {
    return (
        <div
            className={`group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors ${
                isSelected
                    ? 'bg-accent text-accent-foreground'
                    : 'hover:bg-muted/60'
            }`}
        >
            <button
                type="button"
                className="flex min-w-0 flex-1 items-center gap-2 text-left"
                onClick={onOpen}
            >
                <span className="shrink-0">
                    <FileTypeIcon item={item} />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate leading-tight">
                        {item.name}
                        {item.type === 'directory' ? '/' : ''}
                    </span>
                    {item.type === 'file' && item.size !== null && (
                        <span className="block text-[10px] leading-tight text-muted-foreground">
                            {formatBytes(item.size)}
                            {item.extension ? ` · .${item.extension}` : ''}
                        </span>
                    )}
                </span>
                {item.type === 'file' && (
                    <span className="shrink-0 text-[10px] text-muted-foreground opacity-0 group-hover:opacity-100">
                        {timeAgo(item.modified_at)}
                    </span>
                )}
            </button>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-6 opacity-0 group-hover:opacity-100"
                    >
                        <MoreHorizontal className="size-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-36">
                    <DropdownMenuItem onClick={onRename}>Rename</DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onClick={onDelete}>
                        <Trash2 className="size-3.5" />
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

function FileTypeIcon({ item, extension, size = 'md' }: { item?: FileItem; extension?: string | null; size?: 'sm' | 'md' }) {
    const ext = (item?.extension ?? extension ?? '').toLowerCase();
    const isDir = item?.type === 'directory';
    const cls = size === 'sm' ? 'size-4' : 'size-4';

    if (isDir) {
        return <Folder className={`${cls} fill-amber-200 stroke-amber-500 dark:fill-amber-900/60 dark:stroke-amber-400`} />;
    }

    if (['ts', 'tsx'].includes(ext)) {
        return <FileCode className={`${cls} text-blue-500`} />;
    }

    if (['js', 'jsx', 'mjs'].includes(ext)) {
        return <FileCode className={`${cls} text-yellow-500`} />;
    }

    if (ext === 'php') {
        return <FileCode className={`${cls} text-violet-500`} />;
    }

    if (ext === 'py') {
        return <FileCode className={`${cls} text-green-500`} />;
    }

    if (['sh', 'bash', 'zsh'].includes(ext)) {
        return <Terminal className={`${cls} text-emerald-500`} />;
    }

    if (['json', 'jsonc'].includes(ext)) {
        return <Braces className={`${cls} text-orange-500`} />;
    }

    if (['yml', 'yaml', 'toml'].includes(ext)) {
        return <Braces className={`${cls} text-rose-400`} />;
    }

    if (['md', 'mdx'].includes(ext)) {
        return <FileText className={`${cls} text-slate-400`} />;
    }

    if (ext === 'txt') {
        return <FileText className={`${cls} text-muted-foreground`} />;
    }

    if (['sql', 'sqlite', 'db'].includes(ext)) {
        return <Database className={`${cls} text-cyan-500`} />;
    }

    if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico'].includes(ext)) {
        return <ImageIcon className={`${cls} text-pink-400`} />;
    }

    if (['.env', 'env', 'pem', 'key', 'cert'].includes(ext) || item?.name?.startsWith('.env')) {
        return <FileLock className={`${cls} text-red-400`} />;
    }

    if (['html', 'htm'].includes(ext)) {
        return <FileCode className={`${cls} text-orange-400`} />;
    }

    if (['css', 'scss', 'sass', 'less'].includes(ext)) {
        return <FileCode className={`${cls} text-pink-500`} />;
    }

    if (['xml', 'svg'].includes(ext)) {
        return <FileCode className={`${cls} text-teal-500`} />;
    }

    return <File className={`${cls} text-muted-foreground`} />;
}

function EmptyEditor() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-4 text-center">
            <div className="rounded-2xl border-2 border-dashed p-6 text-muted-foreground/40">
                <FolderOpen className="mx-auto size-10" />
            </div>
            <div className="space-y-1">
                <p className="text-sm font-medium text-muted-foreground">No file open</p>
                <p className="text-xs text-muted-foreground/60">
                    Click a file in the explorer to view and edit it
                </p>
            </div>
        </div>
    );
}

function formatBytes(bytes: number): string {
    if (bytes === 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const value = bytes / 1024 ** i;
    return `${value.toFixed(value >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
}

function timeAgo(dateString: string): string {
    const diff = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
    if (diff < 60) {
        return 'just now';
    }
    if (diff < 3600) {
        return `${Math.floor(diff / 60)}m ago`;
    }
    if (diff < 86400) {
        return `${Math.floor(diff / 3600)}h ago`;
    }
    if (diff < 604800) {
        return `${Math.floor(diff / 86400)}d ago`;
    }
    return new Date(dateString).toLocaleDateString();
}

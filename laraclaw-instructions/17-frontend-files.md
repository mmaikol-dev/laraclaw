# 17 — Frontend: File Explorer Page

File: `resources/js/pages/Files/FilesPage.tsx`

---

## What this page does
A full file explorer with a tree/list view on the left and a Monaco code editor on the right. Users can browse, create, edit, and delete files directly.

---

## Layout
Two-panel layout:
- **Left panel** (~280px wide): directory browser
- **Right panel** (remaining width): file editor or empty state

---

## Left panel — Directory browser

### Toolbar
- Current path displayed (truncated if long)
- "Up" button — navigate to parent directory
- "New file" button — prompt for filename, POST `/api/v1/files/create`
- "New folder" button — prompt for folder name, POST `/api/v1/files/mkdir`

### File list
- Fetch from `GET /api/v1/files/browse?path={currentPath}`
- Show directories first, then files, alphabetically
- Each row shows: appropriate icon (folder vs file type), name, and file size for files
- Clicking a **directory** → navigate into it (update `currentPath` state and refetch)
- Clicking a **file** → load it into the editor
- Right-click or "..." menu on each item:
  - Rename (prompt for new name, then call write/move)
  - Delete (confirm dialog, then DELETE `/api/v1/files?path=...`)

### File type icons
Use different Lucide icons for common extensions: `.js/.ts` (Code), `.json` (Braces), `.md` (FileText), `.pdf` (FileText), directories (Folder). Default to `File` for anything else.

---

## Right panel — Monaco Editor

### When a file is selected
- Fetch file content from `GET /api/v1/files/read?path={path}`
- Display in `@monaco-editor/react` component
- Detect language from file extension (e.g. `ts`, `json`, `php`, `md`, `python`, `bash`)
- Show a toolbar above the editor with:
  - File path (breadcrumb style)
  - "Save" button — POST `/api/v1/files/write` with `{path, content}` — show a toast on success
  - "Close" button — clear selected file

### Keyboard shortcut
Ctrl+S inside the editor should trigger save.

### When no file is selected
Show an empty state: folder icon and text "Select a file to edit".

---

## State management
Keep these in local state:
- `currentPath: string` — the currently browsed directory
- `selectedFile: { path, content, extension } | null` — the open file
- `isDirty: boolean` — whether the editor content differs from what was last saved

---

## Error handling
- Show an error toast if browse/read/write fails
- Show a loading skeleton while fetching directory contents

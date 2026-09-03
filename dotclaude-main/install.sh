#!/usr/bin/env bash
set -euo pipefail

# dotclaude installer
# https://github.com/RajeshKalidandi/dotclaude

REPO="RajeshKalidandi/dotclaude"
BRANCH="main"
CLAUDE_DIR="$HOME/.claude"
BACKUP_DIR="$CLAUDE_DIR/backups/dotclaude-$(date +%Y%m%d-%H%M%S)"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info()  { echo -e "${BLUE}[dotclaude]${NC} $1"; }
ok()    { echo -e "${GREEN}[dotclaude]${NC} $1"; }
warn()  { echo -e "${YELLOW}[dotclaude]${NC} $1"; }
error() { echo -e "${RED}[dotclaude]${NC} $1"; exit 1; }

echo ""
echo -e "${BLUE}  ┌─────────────────────────────────────────┐${NC}"
echo -e "${BLUE}  │          dotclaude installer             │${NC}"
echo -e "${BLUE}  │   Dotfiles for Claude Code power users   │${NC}"
echo -e "${BLUE}  └─────────────────────────────────────────┘${NC}"
echo ""

# Check prerequisites
command -v curl >/dev/null 2>&1 || error "curl is required but not installed."
command -v jq >/dev/null 2>&1 || warn "jq not found. Hook merging will use fallback method."

# Ensure ~/.claude exists
if [ ! -d "$CLAUDE_DIR" ]; then
    info "Creating $CLAUDE_DIR..."
    mkdir -p "$CLAUDE_DIR"
fi

# Create backup
info "Backing up existing config to $BACKUP_DIR..."
mkdir -p "$BACKUP_DIR"
[ -f "$CLAUDE_DIR/CLAUDE.md" ] && cp "$CLAUDE_DIR/CLAUDE.md" "$BACKUP_DIR/CLAUDE.md" && ok "Backed up CLAUDE.md"
[ -f "$CLAUDE_DIR/settings.json" ] && cp "$CLAUDE_DIR/settings.json" "$BACKUP_DIR/settings.json" && ok "Backed up settings.json"

# Download files from GitHub
BASE_URL="https://raw.githubusercontent.com/$REPO/$BRANCH"

download() {
    local src="$1"
    local dest="$2"
    local dir=$(dirname "$dest")
    mkdir -p "$dir"
    if curl -fsSL "$BASE_URL/$src" -o "$dest" 2>/dev/null; then
        ok "Installed $src"
    else
        warn "Failed to download $src (skipping)"
    fi
}

# Install CLAUDE.md
info "Installing CLAUDE.md..."
download "CLAUDE.md" "$CLAUDE_DIR/CLAUDE.md"

# Install skills
info "Installing skills..."
mkdir -p "$CLAUDE_DIR/skills/project-autopilot"
download "skills/project-autopilot/SKILL.md" "$CLAUDE_DIR/skills/project-autopilot/SKILL.md"

# Install memory templates
info "Installing memory templates..."
mkdir -p "$CLAUDE_DIR/memory-templates"
download "memory/templates/MEMORY.md" "$CLAUDE_DIR/memory-templates/MEMORY.md"
download "memory/templates/project_overview.md" "$CLAUDE_DIR/memory-templates/project_overview.md"
download "memory/templates/project_architecture.md" "$CLAUDE_DIR/memory-templates/project_architecture.md"
download "memory/templates/user_profile.md" "$CLAUDE_DIR/memory-templates/user_profile.md"
download "memory/templates/patterns_engineering.md" "$CLAUDE_DIR/memory-templates/patterns_engineering.md"
download "memory/templates/patterns_debugging.md" "$CLAUDE_DIR/memory-templates/patterns_debugging.md"

# Merge hooks into settings.json
info "Merging quality hooks into settings.json..."

if [ -f "$CLAUDE_DIR/settings.json" ] && command -v jq >/dev/null 2>&1; then
    # Download hooks config
    HOOKS_JSON=$(curl -fsSL "$BASE_URL/hooks/quality-fortress.json" 2>/dev/null || echo "")

    if [ -n "$HOOKS_JSON" ]; then
        # Merge hooks into existing settings
        EXISTING=$(cat "$CLAUDE_DIR/settings.json")

        # Extract new PreToolUse and PostToolUse hooks
        NEW_PRE=$(echo "$HOOKS_JSON" | jq '.PreToolUse // []')
        NEW_POST=$(echo "$HOOKS_JSON" | jq '.PostToolUse // []')

        # Merge with existing (append new hooks, avoid duplicates by matcher)
        MERGED=$(echo "$EXISTING" | jq --argjson pre "$NEW_PRE" --argjson post "$NEW_POST" '
            .hooks.PreToolUse = ((.hooks.PreToolUse // []) + $pre | unique_by(.matcher + (.hooks[0].command // "")))
            | .hooks.PostToolUse = ((.hooks.PostToolUse // []) + $post | unique_by(.matcher + (.hooks[0].command // "")))
        ')

        echo "$MERGED" | jq '.' > "$CLAUDE_DIR/settings.json"
        ok "Merged hooks into settings.json"
    else
        warn "Could not download hooks config. Skipping hook installation."
        warn "You can manually add hooks from hooks/quality-fortress.json"
    fi
else
    if [ ! -f "$CLAUDE_DIR/settings.json" ]; then
        # Create fresh settings with hooks
        download "hooks/quality-fortress.json" "/tmp/dotclaude-hooks.json"
        if [ -f "/tmp/dotclaude-hooks.json" ]; then
            echo "{\"hooks\": $(cat /tmp/dotclaude-hooks.json)}" | jq '.' > "$CLAUDE_DIR/settings.json" 2>/dev/null || \
            echo "{\"hooks\": $(cat /tmp/dotclaude-hooks.json)}" > "$CLAUDE_DIR/settings.json"
            rm -f /tmp/dotclaude-hooks.json
            ok "Created settings.json with hooks"
        fi
    else
        warn "jq not available for safe merge. Please manually add hooks."
        warn "See: https://github.com/$REPO/blob/main/hooks/README.md"
    fi
fi

echo ""
echo -e "${GREEN}  ┌─────────────────────────────────────────┐${NC}"
echo -e "${GREEN}  │        dotclaude installed!              │${NC}"
echo -e "${GREEN}  └─────────────────────────────────────────┘${NC}"
echo ""
info "Your previous config is backed up at:"
info "  $BACKUP_DIR"
echo ""
info "What's installed:"
info "  CLAUDE.md          -> Production-grade global instructions"
info "  project-autopilot  -> Auto-scaffold projects on session start"
info "  quality hooks      -> Enforced quality gates on every action"
info "  memory templates   -> Ready-to-use project memory scaffolds"
echo ""
info "Next: Open any project with Claude Code. The autopilot takes it from here."
echo ""

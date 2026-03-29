#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  LaraClaw Installer
#  Usage: curl -fsSL https://raw.githubusercontent.com/mmaikol-dev/laraclaw/main/install.sh | bash
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "${GREEN}✔${NC}  $*"; }
info() { echo -e "${BLUE}→${NC}  $*"; }
warn() { echo -e "${YELLOW}⚠${NC}  $*"; }
err()  { echo -e "${RED}✖${NC}  $*" >&2; }
step() { echo -e "\n${BOLD}${CYAN}━━  $*${NC}"; }

REPO="https://github.com/mmaikol-dev/laraclaw.git"
INSTALL_DIR="${INSTALL_DIR:-$HOME/Projects/laraclaw}"
APP_PORT=8100

# ── banner ────────────────────────────────────────────────────────────────────
echo -e "
${CYAN}${BOLD}
 _                  ____ _
| |    __ _ _ __ __ / ___| | __ ___      __
| |   / _\` | '__/ _\` | |   | |/ _\` \\ \\ /\\ / /
| |__| (_| | | | (_| | |___| | (_| |\\ V  V /
|_____\\__,_|_|  \\__,_|\\____|_|\\__,_| \\_/\\_/
${NC}
  Local AI Agent — installer
  ${BLUE}${REPO}${NC}
"

# ── OS check ──────────────────────────────────────────────────────────────────
if ! command -v apt-get &>/dev/null; then
    err "This installer requires a Debian/Ubuntu system (apt-get not found)."
    exit 1
fi

# ── interactive config ────────────────────────────────────────────────────────
step "Configuration"

read -rp "$(echo -e "${BOLD}Install directory${NC} [${INSTALL_DIR}]: ")" input
INSTALL_DIR="${input:-$INSTALL_DIR}"

read -rp "$(echo -e "${BOLD}App port${NC} [${APP_PORT}]: ")" input
APP_PORT="${input:-$APP_PORT}"

echo ""
info "Ollama agent model — pick one or enter your own:"
echo ""
echo -e "  ${BOLD}── Local models (downloaded to your machine) ──${NC}"
echo "   1) llama3.2:3b        (fast, ~2 GB, good tool use)"
echo "   2) llama3.3:70b       (powerful, ~43 GB)"
echo "   3) qwen2.5:7b         (balanced, ~5 GB)"
echo "   4) qwen2.5:14b        (smart, ~9 GB)"
echo "   5) qwen3:8b           (Qwen3, ~5 GB)"
echo "   6) deepseek-r1:8b     (reasoning, ~5 GB)"
echo "   7) deepseek-r1:32b    (strong reasoning, ~20 GB)"
echo "   8) mistral:7b         (fast + efficient, ~4 GB)"
echo "   9) gemma3:9b          (Google Gemma 3, ~6 GB)"
echo "  10) phi4:14b           (Microsoft Phi-4, ~9 GB)"
echo "  11) glm4:9b            (GLM-4 local, ~6 GB)"
echo ""
echo -e "  ${BOLD}── Cloud models (API — no download required) ──${NC}"
echo "  12) glm-4:cloud        (ChatGLM-4 via cloud)"
echo "  13) glm-4-flash:cloud  (ChatGLM-4 Flash, faster)"
echo "  14) glm-5:cloud        (ChatGLM-5 via cloud)"
echo "  15) qwen-plus:cloud    (Qwen Plus via Alibaba cloud)"
echo "  16) qwen-turbo:cloud   (Qwen Turbo, fast + cheap)"
echo "  17) qwen-max:cloud     (Qwen Max, most capable)"
echo "  18) gemini-2.0-flash:cloud   (Google Gemini 2.0 Flash)"
echo "  19) gemini-2.5-pro:cloud     (Google Gemini 2.5 Pro)"
echo "  20) Enter manually     (any model name from ollama.com/search)"
echo ""
read -rp "$(echo -e "${BOLD}Choice${NC} [1]: ")" model_choice
case "${model_choice:-1}" in
    1)  AGENT_MODEL="llama3.2:3b" ;;
    2)  AGENT_MODEL="llama3.3:70b" ;;
    3)  AGENT_MODEL="qwen2.5:7b" ;;
    4)  AGENT_MODEL="qwen2.5:14b" ;;
    5)  AGENT_MODEL="qwen3:8b" ;;
    6)  AGENT_MODEL="deepseek-r1:8b" ;;
    7)  AGENT_MODEL="deepseek-r1:32b" ;;
    8)  AGENT_MODEL="mistral:7b" ;;
    9)  AGENT_MODEL="gemma3:9b" ;;
    10) AGENT_MODEL="phi4:14b" ;;
    11) AGENT_MODEL="glm4:9b" ;;
    12) AGENT_MODEL="glm-4:cloud" ;;
    13) AGENT_MODEL="glm-4-flash:cloud" ;;
    14) AGENT_MODEL="glm-5:cloud" ;;
    15) AGENT_MODEL="qwen-plus:cloud" ;;
    16) AGENT_MODEL="qwen-turbo:cloud" ;;
    17) AGENT_MODEL="qwen-max:cloud" ;;
    18) AGENT_MODEL="gemini-2.0-flash:cloud" ;;
    19) AGENT_MODEL="gemini-2.5-pro:cloud" ;;
    20) read -rp "Model name: " AGENT_MODEL ;;
    *)  AGENT_MODEL="llama3.2:3b" ;;
esac
EMBEDDING_MODEL="qwen3-embedding:0.6b"

read -rsp "$(echo -e "${BOLD}MySQL root password${NC} (leave blank if none): ")" MYSQL_ROOT_PASS
echo ""
read -rp "$(echo -e "${BOLD}Database name${NC} [laraclaw]: ")" DB_NAME
DB_NAME="${DB_NAME:-laraclaw}"
read -rp "$(echo -e "${BOLD}Database user${NC} [laraclaw]: ")" DB_USER
DB_USER="${DB_USER:-laraclaw}"
read -rsp "$(echo -e "${BOLD}Database password${NC} [laraclaw_secret]: ")" DB_PASS
echo ""
DB_PASS="${DB_PASS:-laraclaw_secret}"

read -rp "$(echo -e "${BOLD}Tavily API key${NC} (optional, for web search — press enter to skip): ")" TAVILY_KEY

echo ""
ok "Configuration saved. Starting installation…"

# ── helpers ───────────────────────────────────────────────────────────────────
apt_install() {
    info "Installing: $*"
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$@"
}

command_exists() { command -v "$1" &>/dev/null; }

# ── 1. System packages ────────────────────────────────────────────────────────
step "1/9  System packages"

sudo apt-get update -q

# curl, git, unzip (prerequisites)
apt_install curl git unzip gnupg2 ca-certificates lsb-release software-properties-common

ok "Base packages installed"

# ── 2. PHP 8.3 ────────────────────────────────────────────────────────────────
step "2/9  PHP 8.3"

if ! command_exists php || ! php -r "exit(PHP_MAJOR_VERSION >= 8 && PHP_MINOR_VERSION >= 3 ? 0 : 1);" 2>/dev/null; then
    info "Adding PHP PPA…"
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update -q
    apt_install \
        php8.3-cli php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring \
        php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
        php8.3-tokenizer php8.3-fileinfo php8.3-pdo
else
    ok "PHP $(php -r 'echo PHP_VERSION;') already installed"
fi

# Composer
if ! command_exists composer; then
    info "Installing Composer…"
    curl -sS https://getcomposer.org/installer | php -- --quiet
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
fi
ok "Composer $(composer --version --no-ansi | awk '{print $3}') ready"

# ── 3. Node.js 20 ─────────────────────────────────────────────────────────────
step "3/9  Node.js 20"

if ! command_exists node || [[ "$(node -e 'process.stdout.write(process.version.slice(1).split(".")[0])')" -lt 20 ]]; then
    info "Installing Node.js 20 via NodeSource…"
    curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
    apt_install nodejs
fi
ok "Node $(node --version) / npm $(npm --version) ready"

# ── 4. MySQL ──────────────────────────────────────────────────────────────────
step "4/9  MySQL"

if ! command_exists mysql; then
    apt_install mysql-server
    sudo systemctl enable --now mysql
fi

info "Configuring database…"
MYSQL_CMD="sudo mysql"
if [[ -n "$MYSQL_ROOT_PASS" ]]; then
    MYSQL_CMD="mysql -uroot -p${MYSQL_ROOT_PASS}"
fi

$MYSQL_CMD <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "Database '${DB_NAME}' and user '${DB_USER}' ready"

# ── 5. Python + Playwright ────────────────────────────────────────────────────
step "5/9  Python 3 + Playwright"

apt_install python3 python3-pip python3-venv

if ! python3 -c "import playwright" &>/dev/null; then
    info "Installing Playwright Python package…"
    pip3 install --quiet playwright
fi

info "Installing Playwright browser dependencies…"
python3 -m playwright install --with-deps chromium 2>&1 | tail -5
ok "Playwright ready"

# ── 6. Google Chrome ─────────────────────────────────────────────────────────
step "6/9  Google Chrome"

if ! command_exists google-chrome; then
    info "Installing Google Chrome…"
    curl -fsSL https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -o /tmp/chrome.deb
    sudo apt-get install -y /tmp/chrome.deb || sudo apt-get install -fy
    rm -f /tmp/chrome.deb
fi
ok "Google Chrome $(google-chrome --version 2>/dev/null | awk '{print $3}') ready"

# ── 7. Ollama ─────────────────────────────────────────────────────────────────
step "7/9  Ollama"

if ! command_exists ollama; then
    info "Installing Ollama…"
    curl -fsSL https://ollama.com/install.sh | sh
fi

# Start Ollama service if not running
if ! pgrep -x ollama &>/dev/null; then
    info "Starting Ollama…"
    systemctl --user enable --now ollama 2>/dev/null || ollama serve &>/dev/null &
    sleep 3
fi

info "Pulling agent model: ${AGENT_MODEL} (this may take a while)…"
if [[ "$AGENT_MODEL" == *":cloud"* ]]; then
    warn "Cloud model detected — skipping pull. Make sure your Ollama gateway is configured for ${AGENT_MODEL}."
else
    ollama pull "${AGENT_MODEL}"
fi

info "Pulling embedding model: ${EMBEDDING_MODEL}…"
ollama pull "${EMBEDDING_MODEL}"

ok "Ollama ready with ${AGENT_MODEL} + ${EMBEDDING_MODEL}"

# ── 8. LaraClaw ───────────────────────────────────────────────────────────────
step "8/9  LaraClaw"

if [[ -d "$INSTALL_DIR/.git" ]]; then
    info "Updating existing installation at ${INSTALL_DIR}…"
    git -C "$INSTALL_DIR" pull --ff-only
else
    info "Cloning into ${INSTALL_DIR}…"
    git clone "$REPO" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR"

info "Installing PHP dependencies…"
composer install --no-interaction --prefer-dist --optimize-autoloader --quiet

info "Installing JS dependencies…"
npm install --silent

# .env
if [[ ! -f .env ]]; then
    cp .env.example .env
fi

# Update .env values
set_env() {
    local key="$1" val="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${val}|" .env
    else
        echo "${key}=${val}" >> .env
    fi
}

php artisan key:generate --no-interaction --quiet

set_env APP_URL        "http://localhost:${APP_PORT}"
set_env APP_PORT       "${APP_PORT}"
set_env DB_CONNECTION  "mysql"
set_env DB_HOST        "127.0.0.1"
set_env DB_PORT        "3306"
set_env DB_DATABASE    "${DB_NAME}"
set_env DB_USERNAME    "${DB_USER}"
set_env DB_PASSWORD    "${DB_PASS}"
set_env OLLAMA_HOST    "http://127.0.0.1:11434"
set_env OLLAMA_AGENT_MODEL      "${AGENT_MODEL}"
set_env OLLAMA_EMBEDDING_MODEL  "${EMBEDDING_MODEL}"
set_env BROWSER_HEADED "true"
set_env CHROME_CDP_PORT ""
[[ -n "$TAVILY_KEY" ]] && set_env TAVILY_API_KEY "${TAVILY_KEY}"

info "Running database migrations…"
php artisan migrate --no-interaction --force --quiet

info "Seeding database…"
php artisan db:seed --no-interaction --force --quiet

info "Building frontend assets…"
npm run build --silent

ok "LaraClaw installed at ${INSTALL_DIR}"

# ── 9. Autostart ──────────────────────────────────────────────────────────────
step "9/9  Autostart (systemd + desktop)"

mkdir -p ~/.config/systemd/user

cat > ~/.config/systemd/user/laraclaw-server.service <<EOF
[Unit]
Description=LaraClaw PHP Server
After=network.target mysql.service

[Service]
Type=simple
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php artisan serve --port=${APP_PORT}
Restart=on-failure
RestartSec=3

[Install]
WantedBy=default.target
EOF

cat > ~/.config/systemd/user/laraclaw-queue.service <<EOF
[Unit]
Description=LaraClaw Queue Worker
After=network.target mysql.service laraclaw-server.service

[Service]
Type=simple
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php artisan queue:listen --tries=1 --timeout=0
Restart=on-failure
RestartSec=3

[Install]
WantedBy=default.target
EOF

systemctl --user daemon-reload
systemctl --user enable laraclaw-server laraclaw-queue
systemctl --user start laraclaw-server laraclaw-queue

# Allow services to run without an active login session
loginctl enable-linger "$(whoami)"

# Open Chrome on login
mkdir -p ~/.config/autostart
cat > ~/.config/autostart/laraclaw.desktop <<EOF
[Desktop Entry]
Type=Application
Name=LaraClaw
Exec=bash -c "sleep 4 && google-chrome http://localhost:${APP_PORT}"
Icon=google-chrome
Hidden=false
X-GNOME-Autostart-enabled=true
EOF

ok "Autostart enabled — LaraClaw will launch on every login"

# ── done ──────────────────────────────────────────────────────────────────────
echo -e "
${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  LaraClaw is ready!
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}

  ${BOLD}URL:${NC}      http://localhost:${APP_PORT}
  ${BOLD}Model:${NC}    ${AGENT_MODEL}
  ${BOLD}Install:${NC}  ${INSTALL_DIR}

  ${CYAN}Open it now:${NC}
  google-chrome http://localhost:${APP_PORT} &

  ${CYAN}Stop / Start:${NC}
  systemctl --user stop laraclaw-server laraclaw-queue
  systemctl --user start laraclaw-server laraclaw-queue

  ${CYAN}Logs:${NC}
  journalctl --user -u laraclaw-server -f
"

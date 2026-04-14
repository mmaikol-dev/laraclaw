#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  LaraClaw Installer — Linux, macOS, Windows (Git Bash / WSL), Android (Termux)
#  Usage: curl -fsSL https://raw.githubusercontent.com/mmaikol-dev/laraclaw/main/install.sh | bash
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "${GREEN}[ok]${NC}  $*"; }
info() { echo -e "${BLUE}[..]${NC}  $*"; }
warn() { echo -e "${YELLOW}[!!]${NC}  $*"; }
err()  { echo -e "${RED}[xx]${NC}  $*" >&2; }
step() { echo -e "\n${BOLD}${CYAN}==  $*${NC}"; }
prompt() {
    local label="$1" default="${2:-}"
    if [[ -n "$default" ]]; then
        printf "%b%s%b [%s]: " "${BOLD}" "$label" "${NC}" "$default"
    else
        printf "%b%s%b: " "${BOLD}" "$label" "${NC}"
    fi
}

command_exists() { command -v "$1" &>/dev/null; }

REPO="https://github.com/mmaikol-dev/laraclaw.git"
APP_PORT=8100

# ── detect OS ─────────────────────────────────────────────────────────────────
OS="unknown"
DISTRO=""

# Android/Termux must be detected first — it reports as Linux via uname -s
if [[ "$(uname -o 2>/dev/null)" == "Android" ]] || [[ -n "${TERMUX_VERSION:-}" ]]; then
    OS="android"
else
    case "$(uname -s)" in
        Linux*)
            OS="linux"
            if grep -qi microsoft /proc/version 2>/dev/null; then
                OS="wsl"
            fi
            if command_exists apt-get; then
                DISTRO="debian"
            elif command_exists dnf; then
                DISTRO="fedora"
            elif command_exists pacman; then
                DISTRO="arch"
            fi
            ;;
        Darwin*)
            OS="macos"
            ;;
        MINGW*|MSYS*|CYGWIN*)
            OS="windows"
            ;;
    esac
fi

# ── default install dir by OS ─────────────────────────────────────────────────
case "$OS" in
    android) INSTALL_DIR="${INSTALL_DIR:-$HOME/laraclaw}" ;;
    *)       INSTALL_DIR="${INSTALL_DIR:-$HOME/Projects/laraclaw}" ;;
esac

# ── auto-detect system timezone ───────────────────────────────────────────────
detect_timezone() {
    if [[ "$OS" == "android" ]]; then
        # Termux: read Android system property
        getprop persist.sys.timezone 2>/dev/null || echo "UTC"
        return
    fi
    if [[ -f /etc/timezone ]]; then
        cat /etc/timezone
    elif command_exists timedatectl; then
        timedatectl show --property=Timezone --value 2>/dev/null || echo "UTC"
    elif [[ -f /etc/localtime ]]; then
        readlink /etc/localtime 2>/dev/null | sed 's|.*/zoneinfo/||' || echo "UTC"
    else
        echo "UTC"
    fi
}
DETECTED_TZ="$(detect_timezone)"

# ── banner ────────────────────────────────────────────────────────────────────
DISTRO_LABEL=""
[[ -n "$DISTRO" ]] && DISTRO_LABEL=" ($DISTRO)"

echo -e "
${CYAN}${BOLD}
 _                  ____ _
| |    __ _ _ __ __ / ___| | __ ___      __
| |   / _\` | '__/ _\` | |   | |/ _\` \\ \\ /\\ / /
| |__| (_| | | | (_| | |___| | (_| |\\ V  V /
|_____\\__,_|_|  \\__,_|\\____|_|\\__,_| \\_/\\_/
${NC}
  Local AI Agent installer
  ${BLUE}${REPO}${NC}
  ${YELLOW}Detected OS: ${OS}${DISTRO_LABEL}${NC}
"

if [[ "$OS" == "android" ]]; then
    echo -e "  ${YELLOW}Android / Termux detected.${NC}"
    echo -e "  ${YELLOW}  • SQLite will be used instead of MySQL${NC}"
    echo -e "  ${YELLOW}  • Browser automation is unavailable on Android${NC}"
    echo -e "  ${YELLOW}  • Supervisor will manage processes instead of systemd${NC}"
    echo ""
fi

# ── interactive config ────────────────────────────────────────────────────────
step "Configuration"

prompt "Install directory" "${INSTALL_DIR}"
read -r input </dev/tty
INSTALL_DIR="${input:-$INSTALL_DIR}"

prompt "App port" "${APP_PORT}"
read -r input </dev/tty
APP_PORT="${input:-$APP_PORT}"

prompt "Timezone" "${DETECTED_TZ}"
read -r input </dev/tty
APP_TIMEZONE="${input:-$DETECTED_TZ}"

echo ""
info "Ollama agent model — pick one or enter your own:"
echo ""

if [[ "$OS" == "android" ]]; then
    echo -e "  ${YELLOW}Tip: on a phone, models ≤ 4 GB work best (options 1, 2, 12 or any cloud model).${NC}"
    echo ""
fi

echo -e "  ${BOLD}── Local models (downloaded to your machine) ──${NC}"
echo "   1) llama3.2:3b        (fast, ~2 GB, good tool use)"
echo "   2) qwen2.5:3b         (lightweight, ~2 GB — recommended for Android)"
echo "   3) qwen2.5:7b         (balanced, ~5 GB)"
echo "   4) qwen2.5:14b        (smart, ~9 GB)"
echo "   5) qwen3:8b           (Qwen3, ~5 GB)"
echo "   6) deepseek-r1:8b     (reasoning, ~5 GB)"
echo "   7) deepseek-r1:32b    (strong reasoning, ~20 GB)"
echo "   8) llama3.3:70b       (powerful, ~43 GB)"
echo "   9) mistral:7b         (fast + efficient, ~4 GB)"
echo "  10) gemma3:9b          (Google Gemma 3, ~6 GB)"
echo "  11) phi4:14b           (Microsoft Phi-4, ~9 GB)"
echo "  12) glm4:9b            (GLM-4 local, ~6 GB)"
echo ""
echo -e "  ${BOLD}── Cloud models (API — no download required) ──${NC}"
echo "  13) glm-4:cloud        (ChatGLM-4 via cloud)"
echo "  14) glm-4-flash:cloud  (ChatGLM-4 Flash, faster)"
echo "  15) glm-5:cloud        (ChatGLM-5 via cloud)"
echo "  16) qwen-plus:cloud    (Qwen Plus via Alibaba cloud)"
echo "  17) qwen-turbo:cloud   (Qwen Turbo, fast + cheap)"
echo "  18) qwen-max:cloud     (Qwen Max, most capable)"
echo "  19) gemini-2.0-flash:cloud   (Google Gemini 2.0 Flash)"
echo "  20) gemini-2.5-pro:cloud     (Google Gemini 2.5 Pro)"
echo "  21) Enter manually     (any model name from ollama.com/search)"
echo ""

if [[ "$OS" == "android" ]]; then
    DEFAULT_MODEL_CHOICE="2"
else
    DEFAULT_MODEL_CHOICE="1"
fi
prompt "Choice" "$DEFAULT_MODEL_CHOICE"
read -r model_choice </dev/tty
model_choice="${model_choice:-$DEFAULT_MODEL_CHOICE}"
case "$model_choice" in
    1)  AGENT_MODEL="llama3.2:3b" ;;
    2)  AGENT_MODEL="qwen2.5:3b" ;;
    3)  AGENT_MODEL="qwen2.5:7b" ;;
    4)  AGENT_MODEL="qwen2.5:14b" ;;
    5)  AGENT_MODEL="qwen3:8b" ;;
    6)  AGENT_MODEL="deepseek-r1:8b" ;;
    7)  AGENT_MODEL="deepseek-r1:32b" ;;
    8)  AGENT_MODEL="llama3.3:70b" ;;
    9)  AGENT_MODEL="mistral:7b" ;;
    10) AGENT_MODEL="gemma3:9b" ;;
    11) AGENT_MODEL="phi4:14b" ;;
    12) AGENT_MODEL="glm4:9b" ;;
    13) AGENT_MODEL="glm-4:cloud" ;;
    14) AGENT_MODEL="glm-4-flash:cloud" ;;
    15) AGENT_MODEL="glm-5:cloud" ;;
    16) AGENT_MODEL="qwen-plus:cloud" ;;
    17) AGENT_MODEL="qwen-turbo:cloud" ;;
    18) AGENT_MODEL="qwen-max:cloud" ;;
    19) AGENT_MODEL="gemini-2.0-flash:cloud" ;;
    20) AGENT_MODEL="gemini-2.5-pro:cloud" ;;
    21) prompt "Model name"; read -r AGENT_MODEL </dev/tty ;;
    *)  AGENT_MODEL="${DEFAULT_MODEL_CHOICE}" ;;
esac

# Embedding model — lighter option for Android
if [[ "$OS" == "android" ]]; then
    EMBEDDING_MODEL="nomic-embed-text"
else
    EMBEDDING_MODEL="qwen3-embedding:0.6b"
fi

# MySQL questions are skipped on Android (SQLite is used instead)
if [[ "$OS" != "android" ]]; then
    prompt "MySQL root password (leave blank if none)"
    read -rs MYSQL_ROOT_PASS </dev/tty
    echo ""
    prompt "Database name" "laraclaw"
    read -r DB_NAME </dev/tty
    DB_NAME="${DB_NAME:-laraclaw}"
    prompt "Database user" "laraclaw"
    read -r DB_USER </dev/tty
    DB_USER="${DB_USER:-laraclaw}"
    prompt "Database password" "LaraClaw@2024!"
    read -rs DB_PASS </dev/tty
    echo ""
    DB_PASS="${DB_PASS:-LaraClaw@2024!}"
fi

prompt "Tavily API key (optional, for web search — press enter to skip)"
read -r TAVILY_KEY </dev/tty

echo ""
ok "Configuration saved. Starting installation…"

# ── OS-specific package installers ───────────────────────────────────────────

install_pkg_linux() {
    case "$DISTRO" in
        debian) sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$@" ;;
        fedora) sudo dnf install -y -q "$@" ;;
        arch)   sudo pacman -S --noconfirm --quiet "$@" ;;
        *)      err "Unsupported Linux distro. Install manually: $*"; exit 1 ;;
    esac
}

install_pkg_macos() {
    if ! command_exists brew; then
        info "Installing Homebrew…"
        /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
        eval "$(/opt/homebrew/bin/brew shellenv 2>/dev/null || /usr/local/bin/brew shellenv)"
    fi
    brew install "$@" 2>/dev/null || brew upgrade "$@" 2>/dev/null || true
}

install_pkg_android() {
    pkg install -y "$@"
}

# ── 1. System packages ────────────────────────────────────────────────────────
step "1/9  System packages"

case "$OS" in
    linux|wsl)
        sudo apt-get update -q
        install_pkg_linux curl git unzip gnupg2 ca-certificates lsb-release software-properties-common
        ;;
    macos)
        install_pkg_macos git curl unzip
        ;;
    android)
        pkg update -y
        install_pkg_android curl git unzip python
        ;;
    windows)
        if ! command_exists git; then
            err "Git not found. Install Git for Windows from https://git-scm.com and re-run."
            exit 1
        fi
        ;;
esac
ok "Base packages installed"

# ── 2. PHP 8.3 ────────────────────────────────────────────────────────────────
step "2/9  PHP 8.3"

php_ok() { command_exists php && php -r "exit(PHP_MAJOR_VERSION >= 8 && PHP_MINOR_VERSION >= 3 ? 0 : 1);" 2>/dev/null; }

if ! php_ok; then
    case "$OS" in
        linux|wsl)
            info "Adding PHP PPA…"
            sudo add-apt-repository -y ppa:ondrej/php
            sudo apt-get update -q
            install_pkg_linux \
                php8.3-cli php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring \
                php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
                php8.3-tokenizer php8.3-fileinfo php8.3-pdo php8.3-pcntl \
                php8.3-posix php8.3-sockets
            ;;
        macos)
            install_pkg_macos php@8.3
            brew link --force --overwrite php@8.3 2>/dev/null || true
            ;;
        android)
            # Termux ships PHP with SQLite, mbstring, curl, etc. built in
            install_pkg_android php
            ;;
        windows)
            err "PHP 8.3 not found. Download from https://windows.php.net/download and add to PATH."
            exit 1
            ;;
    esac
else
    ok "PHP $(php -r 'echo PHP_VERSION;') already installed"
fi

# Composer
if ! command_exists composer; then
    info "Installing Composer…"
    curl -sS https://getcomposer.org/installer | php -- --quiet
    case "$OS" in
        android) mv composer.phar "$PREFIX/bin/composer" && chmod +x "$PREFIX/bin/composer" ;;
        windows) mv composer.phar "$HOME/bin/composer" 2>/dev/null || mv composer.phar /usr/local/bin/composer ;;
        *)       sudo mv composer.phar /usr/local/bin/composer && sudo chmod +x /usr/local/bin/composer ;;
    esac
fi
ok "Composer $(composer --version --no-ansi | awk '{print $3}') ready"

# ── 3. Node.js 20 ─────────────────────────────────────────────────────────────
step "3/9  Node.js 20"

node_ok() { command_exists node && [[ "$(node -e 'process.stdout.write(process.version.slice(1).split(".")[0])')" -ge 20 ]]; }

if ! node_ok; then
    case "$OS" in
        linux|wsl)
            info "Installing Node.js 20 via NodeSource…"
            curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
            install_pkg_linux nodejs
            ;;
        macos)
            install_pkg_macos node@20
            brew link --force --overwrite node@20 2>/dev/null || true
            ;;
        android)
            # Termux nodejs package — usually a recent LTS
            install_pkg_android nodejs
            ;;
        windows)
            err "Node.js 20+ not found. Download from https://nodejs.org and re-run."
            exit 1
            ;;
    esac
fi
ok "Node $(node --version) / npm $(npm --version) ready"

# ── 4. Database ───────────────────────────────────────────────────────────────
step "4/9  Database"

if [[ "$OS" == "android" ]]; then
    # SQLite is compiled into Termux's PHP — nothing to install
    info "SQLite will be used (built into Termux PHP)…"
    ok "SQLite ready"
else
    if ! command_exists mysql; then
        case "$OS" in
            linux|wsl)
                install_pkg_linux mysql-server
                sudo systemctl enable --now mysql
                ;;
            macos)
                install_pkg_macos mysql
                brew services start mysql
                sleep 3
                ;;
            windows)
                err "MySQL not found. Download MySQL Installer from https://dev.mysql.com/downloads/installer/ and re-run."
                exit 1
                ;;
        esac
    fi

    info "Configuring database…"
    MYSQL_CMD="sudo mysql"
    if [[ -n "$MYSQL_ROOT_PASS" ]]; then
        MYSQL_CMD="mysql -uroot -p${MYSQL_ROOT_PASS}"
    elif [[ "$OS" == "macos" ]]; then
        MYSQL_CMD="mysql -uroot"
    fi

    $MYSQL_CMD <<SQL 2>/dev/null || warn "Some DB setup steps were skipped (may already exist)"
SET GLOBAL validate_password.policy = LOW;
SET GLOBAL validate_password.length = 4;
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    ok "Database '${DB_NAME}' and user '${DB_USER}' ready"
fi

# ── 5. Python + Playwright / Supervisor ──────────────────────────────────────
step "5/9  Python 3 + Playwright"

if [[ "$OS" == "android" ]]; then
    # Playwright and Chrome are unavailable on Android — install Supervisor instead
    # to manage Laravel processes (replaces systemd)
    info "Installing Supervisor (process manager for Android)…"
    pip install --quiet supervisor
    ok "Supervisor ready (Playwright / Chrome not available on Android)"
else
    case "$OS" in
        linux|wsl)
            install_pkg_linux python3 python3-pip python3-venv
            ;;
        macos)
            install_pkg_macos python3
            ;;
        windows)
            if ! command_exists python3 && ! command_exists python; then
                err "Python 3 not found. Download from https://python.org and re-run."
                exit 1
            fi
            ;;
    esac

    PY="python3"
    command_exists python3 || PY="python"

    if ! $PY -c "import playwright" &>/dev/null; then
        info "Installing Playwright…"
        $PY -m pip install --quiet playwright
    fi

    info "Installing Playwright browser dependencies…"
    $PY -m playwright install --with-deps chromium 2>&1 | tail -5
    ok "Playwright ready"
fi

# ── 6. Google Chrome ─────────────────────────────────────────────────────────
step "6/9  Google Chrome"

if [[ "$OS" == "android" ]]; then
    info "Skipping Chrome install — use the Chrome app already on your phone."
    ok "Open http://localhost:${APP_PORT} in Chrome to access LaraClaw"
else
    chrome_installed() {
        command_exists google-chrome || \
        [[ -f "/opt/google/chrome/google-chrome" ]] || \
        [[ -f "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" ]] || \
        [[ -f "C:/Program Files/Google/Chrome/Application/chrome.exe" ]]
    }

    if ! chrome_installed; then
        case "$OS" in
            linux|wsl)
                info "Installing Google Chrome…"
                curl -fsSL https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb -o /tmp/chrome.deb
                sudo apt-get install -y /tmp/chrome.deb || sudo apt-get install -fy
                rm -f /tmp/chrome.deb
                ;;
            macos)
                info "Installing Google Chrome via Homebrew Cask…"
                brew install --cask google-chrome
                ;;
            windows)
                warn "Google Chrome not found. Download from https://google.com/chrome — the browser tool will fall back to Chromium."
                ;;
        esac
    fi
    ok "Google Chrome ready"
fi

# ── 7. Ollama ─────────────────────────────────────────────────────────────────
step "7/9  Ollama"

if ! command_exists ollama; then
    info "Installing Ollama…"
    case "$OS" in
        linux|wsl)
            curl -fsSL https://ollama.com/install.sh | sh
            ;;
        macos)
            brew install --cask ollama 2>/dev/null || \
            curl -fsSL https://ollama.com/install.sh | sh
            ;;
        android)
            # The standard install.sh requires glibc; Termux uses bionic — use pkg instead
            install_pkg_android ollama
            ;;
        windows)
            err "Ollama not found. Download from https://ollama.com/download/windows and re-run."
            exit 1
            ;;
    esac
fi

# Start Ollama if not running
if ! pgrep -x ollama &>/dev/null; then
    info "Starting Ollama…"
    case "$OS" in
        linux|wsl) sudo systemctl enable --now ollama 2>/dev/null || (ollama serve &>/dev/null & sleep 3) ;;
        macos)     (ollama serve &>/dev/null & sleep 3) ;;
        android)   (ollama serve &>/dev/null & sleep 5) ;;
    esac
fi

info "Pulling agent model: ${AGENT_MODEL}…"
if [[ "$AGENT_MODEL" == *":cloud"* ]]; then
    warn "Cloud model — skipping pull. Configure your Ollama gateway for ${AGENT_MODEL}."
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
if [[ "$OS" == "android" ]]; then
    # --no-dev saves ~50 MB of memory-heavy dev packages on the phone
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
    composer install --no-interaction --prefer-dist --optimize-autoloader --quiet
fi

info "Installing JS dependencies…"
npm install --silent

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

set_env() {
    local key="$1" val="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${val}|" .env
    else
        echo "${key}=${val}" >> .env
    fi
}

comment_env() {
    local key="$1"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|# ${key}=|" .env
    fi
}

# Configure the database BEFORE key:generate so Laravel can bootstrap cleanly
if [[ "$OS" == "android" ]]; then
    touch "${INSTALL_DIR}/database/database.sqlite"
    set_env DB_CONNECTION "sqlite"
    set_env DB_DATABASE   "${INSTALL_DIR}/database/database.sqlite"
    comment_env DB_HOST
    comment_env DB_PORT
    comment_env DB_USERNAME
    comment_env DB_PASSWORD
fi

php artisan key:generate --no-interaction --quiet

set_env APP_URL      "http://localhost:${APP_PORT}"
set_env APP_PORT     "${APP_PORT}"
set_env APP_TIMEZONE "${APP_TIMEZONE}"
set_env OLLAMA_HOST            "http://127.0.0.1:11434"
set_env OLLAMA_AGENT_MODEL     "${AGENT_MODEL}"
set_env OLLAMA_EMBEDDING_MODEL "${EMBEDDING_MODEL}"
[[ -n "$TAVILY_KEY" ]] && set_env TAVILY_API_KEY "${TAVILY_KEY}"

if [[ "$OS" == "android" ]]; then
    # Agent working directory inside Termux home
    AGENT_WORK_DIR="${HOME}/laraclaw-workspace"
    mkdir -p "${AGENT_WORK_DIR}"
    set_env AGENT_WORKING_DIR   "${AGENT_WORK_DIR}"
    set_env AGENT_ALLOWED_PATHS "${AGENT_WORK_DIR},${HOME}"
    # Limit context window to keep memory usage in check on the phone
    set_env OLLAMA_CONTEXT_LENGTH "8192"
    # Browser automation is unavailable on Android
    set_env BROWSER_HEADED  "false"
    set_env CHROME_CDP_PORT ""
else
    set_env DB_CONNECTION "mysql"
    set_env DB_HOST       "127.0.0.1"
    set_env DB_PORT       "3306"
    set_env DB_DATABASE   "${DB_NAME}"
    set_env DB_USERNAME   "${DB_USER}"
    set_env DB_PASSWORD   "${DB_PASS}"
    set_env BROWSER_HEADED  "true"
    set_env CHROME_CDP_PORT ""
fi

info "Running database migrations…"
php artisan migrate --no-interaction --force --quiet

if [[ "$OS" != "android" ]]; then
    info "Seeding database…"
    php artisan db:seed --no-interaction --force --quiet
fi

if [[ "$OS" == "android" ]]; then
    info "Caching config for performance…"
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache  --quiet
fi

info "Building frontend assets…"
npm run build --silent

ok "LaraClaw installed at ${INSTALL_DIR}"

# ── 9. Autostart + Scheduler ──────────────────────────────────────────────────
step "9/9  Autostart + Scheduler"

PHP_BIN="$(command -v php)"

case "$OS" in
    # ── Android (Termux) ──────────────────────────────────────────────────────
    android)
        SUPERVISOR_CONF="${PREFIX}/etc/supervisor/supervisord.conf"
        SUPERVISOR_LOG="${PREFIX}/var/log"
        SUPERVISOR_RUN="${PREFIX}/var/run"

        mkdir -p "${PREFIX}/etc/supervisor" "${SUPERVISOR_LOG}" "${SUPERVISOR_RUN}"

        cat > "${SUPERVISOR_CONF}" <<EOF
[unix_http_server]
file=${SUPERVISOR_RUN}/supervisor.sock

[supervisord]
logfile=${SUPERVISOR_LOG}/supervisord.log
logfile_maxbytes=10MB
logfile_backups=3
loglevel=info
pidfile=${SUPERVISOR_RUN}/supervisord.pid
nodaemon=false

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix://${SUPERVISOR_RUN}/supervisor.sock

[program:laraclaw-serve]
command=${PHP_BIN} artisan serve --host=0.0.0.0 --port=${APP_PORT}
directory=${INSTALL_DIR}
autostart=true
autorestart=true
startretries=5
stderr_logfile=${SUPERVISOR_LOG}/laraclaw-serve.err.log
stdout_logfile=${SUPERVISOR_LOG}/laraclaw-serve.out.log

[program:laraclaw-queue]
command=${PHP_BIN} artisan queue:work --sleep=3 --tries=3 --timeout=90
directory=${INSTALL_DIR}
autostart=true
autorestart=true
startretries=5
stderr_logfile=${SUPERVISOR_LOG}/laraclaw-queue.err.log
stdout_logfile=${SUPERVISOR_LOG}/laraclaw-queue.out.log

[program:laraclaw-scheduler]
command=${PHP_BIN} artisan schedule:work
directory=${INSTALL_DIR}
autostart=true
autorestart=true
startretries=5
stderr_logfile=${SUPERVISOR_LOG}/laraclaw-scheduler.err.log
stdout_logfile=${SUPERVISOR_LOG}/laraclaw-scheduler.out.log

[program:ollama]
command=ollama serve
autostart=true
autorestart=true
startretries=10
stderr_logfile=${SUPERVISOR_LOG}/ollama.err.log
stdout_logfile=${SUPERVISOR_LOG}/ollama.out.log
EOF

        # Termux:Boot autostart — also opens Chrome after server is ready
        mkdir -p "${HOME}/.termux/boot"
        cat > "${HOME}/.termux/boot/start-laraclaw.sh" <<EOF
#!/data/data/com.termux/files/usr/bin/sh

# Prevent Android from killing Termux while idle
termux-wake-lock

# Give the system a moment to settle after boot
sleep 10

# Start Supervisor — manages ollama, laraclaw-serve, laraclaw-queue, laraclaw-scheduler
# Ollama is included as a supervised program so it restarts automatically if it crashes
supervisord -c ${SUPERVISOR_CONF}

# Wait for the web server to come up, then open Chrome
# Android detects the PWA manifest and shows the "Add to Home Screen" banner
sleep 12
am start -a android.intent.action.VIEW -d "http://localhost:${APP_PORT}" com.android.chrome 2>/dev/null || true
EOF
        chmod +x "${HOME}/.termux/boot/start-laraclaw.sh"

        # Start Supervisor now (for this session)
        if ! pgrep -f supervisord &>/dev/null; then
            info "Starting Supervisor…"
            supervisord -c "${SUPERVISOR_CONF}"
        fi

        # Wait for the server to be ready, then open Chrome so the PWA install prompt appears
        info "Waiting for server to start…"
        SERVER_READY=false
        for i in $(seq 1 20); do
            if curl -sf "http://localhost:${APP_PORT}" &>/dev/null; then
                SERVER_READY=true
                break
            fi
            sleep 1
        done

        if [[ "$SERVER_READY" == true ]]; then
            info "Opening LaraClaw in Chrome — tap 'Add to Home Screen' when the banner appears…"
            am start -a android.intent.action.VIEW \
                -d "http://localhost:${APP_PORT}" \
                com.android.chrome 2>/dev/null \
            || termux-open-url "http://localhost:${APP_PORT}" 2>/dev/null \
            || true
        else
            warn "Server did not respond in time — open http://localhost:${APP_PORT} in Chrome manually."
        fi

        ok "Supervisor + Termux:Boot autostart configured"

        echo ""
        warn "ACTION REQUIRED — disable battery optimisation or Android will kill Termux:"
        echo "  Settings → Apps → Termux        → Battery → Unrestricted"
        echo "  Settings → Apps → Termux:Boot   → Battery → Unrestricted"
        echo ""
        warn "Open Termux:Boot at least once so it registers as a boot listener."
        ;;

    # ── Linux / WSL ───────────────────────────────────────────────────────────
    linux|wsl)
        mkdir -p ~/.config/systemd/user

        cat > ~/.config/systemd/user/laraclaw-server.service <<EOF
[Unit]
Description=LaraClaw PHP Server
After=network.target mysql.service

[Service]
Type=simple
WorkingDirectory=${INSTALL_DIR}
ExecStart=${PHP_BIN} artisan serve --port=${APP_PORT}
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
ExecStart=${PHP_BIN} artisan queue:listen --tries=1 --timeout=0
Restart=on-failure
RestartSec=3

[Install]
WantedBy=default.target
EOF

        systemctl --user daemon-reload
        systemctl --user enable laraclaw-server laraclaw-queue
        systemctl --user start laraclaw-server laraclaw-queue
        loginctl enable-linger "$(whoami)" 2>/dev/null || true

        # Scheduler crontab — runs every minute for scheduled tasks, triggers, reports
        CRON_LINE="* * * * * cd ${INSTALL_DIR} && ${PHP_BIN} artisan schedule:run >> /tmp/laraclaw-scheduler.log 2>&1"
        if ! crontab -l 2>/dev/null | grep -qF "artisan schedule:run"; then
            (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
            ok "Scheduler crontab installed"
        else
            ok "Scheduler crontab already present"
        fi

        # Find Chrome binary
        CHROME_BIN=""
        for _bin in google-chrome google-chrome-stable chromium chromium-browser; do
            command_exists "$_bin" && CHROME_BIN="$_bin" && break
        done
        [[ -z "$CHROME_BIN" && -f /opt/google/chrome/google-chrome ]] && CHROME_BIN=/opt/google/chrome/google-chrome

        # Install app icon
        ICON_DIR="${HOME}/.local/share/icons/hicolor/192x192/apps"
        mkdir -p "${ICON_DIR}"
        [[ -f "${INSTALL_DIR}/public/apple-touch-icon.png" ]] && \
            cp "${INSTALL_DIR}/public/apple-touch-icon.png" "${ICON_DIR}/laraclaw.png"

        # Register LaraClaw as a system app — shows in GNOME/KDE app menu, opens without browser chrome
        mkdir -p "${HOME}/.local/share/applications"
        cat > "${HOME}/.local/share/applications/laraclaw.desktop" <<EOF
[Desktop Entry]
Version=1.0
Type=Application
Name=LaraClaw
Comment=Local AI Agent
Exec=${CHROME_BIN:-google-chrome} --app=http://localhost:${APP_PORT}
Icon=${ICON_DIR}/laraclaw.png
StartupNotify=true
StartupWMClass=laraclaw
Categories=Utility;Office;
Keywords=AI;Agent;Chat;
EOF
        update-desktop-database "${HOME}/.local/share/applications" 2>/dev/null || true
        ok "LaraClaw registered in app launcher (search for 'LaraClaw' in your apps)"

        # Autostart on login — launches via the app launcher entry
        mkdir -p ~/.config/autostart
        cat > ~/.config/autostart/laraclaw.desktop <<EOF
[Desktop Entry]
Type=Application
Name=LaraClaw
Exec=bash -c "sleep 4 && ${CHROME_BIN:-google-chrome} --app=http://localhost:${APP_PORT}"
Icon=${ICON_DIR}/laraclaw.png
Hidden=false
X-GNOME-Autostart-enabled=true
EOF

        # Open Chrome now in app mode — no browser chrome, just like an installed PWA
        if [[ -n "$CHROME_BIN" ]]; then
            info "Opening LaraClaw…"
            "$CHROME_BIN" --app="http://localhost:${APP_PORT}" &>/dev/null &
        fi

        ok "Systemd services + app launcher + autostart + scheduler enabled"
        ;;

    # ── macOS ─────────────────────────────────────────────────────────────────
    macos)
        PLIST_DIR="$HOME/Library/LaunchAgents"
        mkdir -p "$PLIST_DIR"

        OLLAMA_BIN="$(command -v ollama)"
        cat > "$PLIST_DIR/io.laraclaw.ollama.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>          <string>io.laraclaw.ollama</string>
    <key>ProgramArguments</key>
    <array>
        <string>${OLLAMA_BIN}</string>
        <string>serve</string>
    </array>
    <key>RunAtLoad</key>      <true/>
    <key>KeepAlive</key>      <true/>
    <key>StandardOutPath</key><string>/tmp/laraclaw-ollama.log</string>
    <key>StandardErrorPath</key><string>/tmp/laraclaw-ollama.log</string>
</dict>
</plist>
EOF
        launchctl load "$PLIST_DIR/io.laraclaw.ollama.plist" 2>/dev/null || true

        cat > "$PLIST_DIR/io.laraclaw.server.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>          <string>io.laraclaw.server</string>
    <key>ProgramArguments</key>
    <array>
        <string>${PHP_BIN}</string>
        <string>artisan</string>
        <string>serve</string>
        <string>--port=${APP_PORT}</string>
    </array>
    <key>WorkingDirectory</key> <string>${INSTALL_DIR}</string>
    <key>RunAtLoad</key>        <true/>
    <key>KeepAlive</key>        <true/>
    <key>StandardOutPath</key>  <string>/tmp/laraclaw-server.log</string>
    <key>StandardErrorPath</key><string>/tmp/laraclaw-server.log</string>
</dict>
</plist>
EOF

        cat > "$PLIST_DIR/io.laraclaw.queue.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>          <string>io.laraclaw.queue</string>
    <key>ProgramArguments</key>
    <array>
        <string>${PHP_BIN}</string>
        <string>artisan</string>
        <string>queue:listen</string>
        <string>--tries=1</string>
        <string>--timeout=0</string>
    </array>
    <key>WorkingDirectory</key> <string>${INSTALL_DIR}</string>
    <key>RunAtLoad</key>        <true/>
    <key>KeepAlive</key>        <true/>
    <key>StandardOutPath</key>  <string>/tmp/laraclaw-queue.log</string>
    <key>StandardErrorPath</key><string>/tmp/laraclaw-queue.log</string>
</dict>
</plist>
EOF

        # Scheduler — macOS launchd plist running every minute
        cat > "$PLIST_DIR/io.laraclaw.scheduler.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>          <string>io.laraclaw.scheduler</string>
    <key>ProgramArguments</key>
    <array>
        <string>${PHP_BIN}</string>
        <string>artisan</string>
        <string>schedule:run</string>
    </array>
    <key>WorkingDirectory</key> <string>${INSTALL_DIR}</string>
    <key>StartInterval</key>    <integer>60</integer>
    <key>RunAtLoad</key>        <true/>
    <key>StandardOutPath</key>  <string>/tmp/laraclaw-scheduler.log</string>
    <key>StandardErrorPath</key><string>/tmp/laraclaw-scheduler.log</string>
</dict>
</plist>
EOF

        launchctl load "$PLIST_DIR/io.laraclaw.server.plist"    2>/dev/null || true
        launchctl load "$PLIST_DIR/io.laraclaw.queue.plist"     2>/dev/null || true
        launchctl load "$PLIST_DIR/io.laraclaw.scheduler.plist" 2>/dev/null || true

        # Open in Chrome app mode — no browser chrome, same experience as an installed PWA
        info "Opening LaraClaw…"
        sleep 3
        open -a "Google Chrome" --args --app="http://localhost:${APP_PORT}" 2>/dev/null \
        || open "http://localhost:${APP_PORT}" 2>/dev/null \
        || true

        ok "launchd services + scheduler enabled (start on login)"
        ;;

    # ── Windows ───────────────────────────────────────────────────────────────
    windows)
        STARTUP_DIR="$APPDATA/Microsoft/Windows/Start Menu/Programs/Startup"
        mkdir -p "$STARTUP_DIR"

        cat > "$STARTUP_DIR/laraclaw.bat" <<EOF
@echo off
start "" /B "${PHP_BIN}" "${INSTALL_DIR}/artisan" serve --port=${APP_PORT}
start "" /B "${PHP_BIN}" "${INSTALL_DIR}/artisan" queue:listen --tries=1 --timeout=0
timeout /t 4 /nobreak >nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" --app=http://localhost:${APP_PORT}
EOF

        TASK_CMD="\"${PHP_BIN}\" \"${INSTALL_DIR}/artisan\" schedule:run"
        schtasks /Create /F /SC MINUTE /MO 1 \
            /TN "LaraClaw Scheduler" \
            /TR "$TASK_CMD" \
            /ST 00:00 2>/dev/null && ok "Windows Task Scheduler entry created" \
            || warn "Could not create Task Scheduler entry — run manually as admin or add it via Task Scheduler GUI"

        ok "Startup batch script created in Windows Startup folder"
        ;;
esac

# ── done ──────────────────────────────────────────────────────────────────────
echo -e "
${GREEN}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  LaraClaw is ready!
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}

  ${BOLD}URL:${NC}       http://localhost:${APP_PORT}
  ${BOLD}Model:${NC}     ${AGENT_MODEL}
  ${BOLD}Timezone:${NC}  ${APP_TIMEZONE}
  ${BOLD}OS:${NC}        ${OS}
  ${BOLD}Install:${NC}   ${INSTALL_DIR}
"

case "$OS" in
    android)
        echo -e "  ${CYAN}Open in Chrome on your phone:${NC}
  http://localhost:${APP_PORT}

  ${CYAN}Install as PWA (recommended):${NC}
  1. Tap the three-dot menu in Chrome
  2. Select \"Add to Home Screen\"
  3. LaraClaw appears as a standalone app icon

  ${CYAN}Process management:${NC}
  supervisorctl -c ${PREFIX}/etc/supervisor/supervisord.conf status
  supervisorctl -c ${PREFIX}/etc/supervisor/supervisord.conf restart all
  supervisorctl -c ${PREFIX}/etc/supervisor/supervisord.conf restart laraclaw-serve

  ${CYAN}Logs:${NC}
  tail -f ${PREFIX}/var/log/laraclaw-serve.out.log
  tail -f ${PREFIX}/var/log/laraclaw-queue.out.log

  ${YELLOW}Remember: Settings → Apps → Termux → Battery → Unrestricted${NC}
  ${YELLOW}          Settings → Apps → Termux:Boot → Battery → Unrestricted${NC}
"
        ;;
    linux|wsl)
        echo -e "  ${CYAN}Open it now:${NC}
  google-chrome --app=http://localhost:${APP_PORT} &

  ${CYAN}Install as PWA (recommended):${NC}
  1. Open http://localhost:${APP_PORT} in Chrome
  2. Click the install icon (⊕) in the address bar
  3. LaraClaw will launch as a standalone app on every boot

  ${CYAN}Agentic Employee:${NC}
  Visit http://localhost:${APP_PORT}/employee to manage
  scheduled tasks, projects, triggers, memories and reports.
  The scheduler runs every minute automatically.

  ${CYAN}Stop / Start:${NC}
  systemctl --user stop laraclaw-server laraclaw-queue
  systemctl --user start laraclaw-server laraclaw-queue

  ${CYAN}Logs:${NC}
  journalctl --user -u laraclaw-server -f
  tail -f /tmp/laraclaw-scheduler.log
"
        ;;
    macos)
        echo -e "  ${CYAN}Stop / Start:${NC}
  launchctl unload ~/Library/LaunchAgents/io.laraclaw.server.plist
  launchctl load ~/Library/LaunchAgents/io.laraclaw.server.plist

  ${CYAN}Logs:${NC}
  tail -f /tmp/laraclaw-server.log
  tail -f /tmp/laraclaw-scheduler.log
"
        ;;
    windows)
        echo -e "  ${CYAN}Stop:${NC}
  taskkill /F /IM php.exe

  ${CYAN}Logs:${NC}
  Check Event Viewer or run manually in a terminal
"
        ;;
esac

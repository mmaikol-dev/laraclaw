<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class BrowserTool extends BaseTool
{
    private string $agentScript;

    public function __construct()
    {
        $this->agentScript = storage_path('app/browser/agent.py');
        $this->publishAgentScript();
    }

    public function getName(): string
    {
        return 'browser';
    }

    public function getDescription(): string
    {
        return 'Control a real Chrome browser — navigate to URLs, click elements, fill forms, take screenshots, extract text and links, run JavaScript, and close the browser. Chrome launches automatically when needed; you do NOT need to call launch first. Use this tool for any task that requires interacting with a website visually — never fall back to web search when this tool is available.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'launch', 'navigate', 'click', 'fill', 'type', 'press',
                        'select', 'screenshot', 'get_text', 'get_url', 'get_links',
                        'wait_for', 'scroll', 'evaluate', 'go_back', 'go_forward', 'close',
                    ],
                    'description' => implode(' ', [
                        'launch: open Chrome (optionally pass url to start at a page).',
                        'navigate: go to a url.',
                        'click: click an element — use text for visible text, selector for CSS/XPath.',
                        'fill: fill an input — use label for the field label, selector for CSS, value for the text to enter.',
                        'type: type text character by character (for typeahead/autocomplete fields).',
                        'press: press a keyboard key e.g. Enter, Tab, Escape, ArrowDown.',
                        'select: choose a dropdown option — pass selector + value or label.',
                        'screenshot: capture the current page, returns file path.',
                        'get_text: extract visible text from the page or a specific selector.',
                        'get_url: get the current URL and page title.',
                        'get_links: list all clickable links on the page.',
                        'wait_for: wait until a text, selector, or url appears.',
                        'scroll: scroll the page — direction: down, up, top, bottom.',
                        'evaluate: run JavaScript in the browser and return the result.',
                        'go_back / go_forward: browser history navigation.',
                        'close: close the browser session.',
                    ]),
                ],
                'session_id' => [
                    'type' => 'string',
                    'description' => 'Browser session name. Defaults to "default". Use different names to run multiple independent browser sessions.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'URL to navigate to (navigate/launch/wait_for).',
                ],
                'selector' => [
                    'type' => 'string',
                    'description' => 'CSS selector or Playwright locator string targeting an element.',
                ],
                'text' => [
                    'type' => 'string',
                    'description' => 'Visible text of the element to click or wait for.',
                ],
                'label' => [
                    'type' => 'string',
                    'description' => 'Form field label text (for fill action — finds the input associated with a label).',
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'Text to fill into an input, or option value for select.',
                ],
                'key' => [
                    'type' => 'string',
                    'description' => 'Keyboard key to press: Enter, Tab, Escape, Space, ArrowDown, ArrowUp, Backspace, etc.',
                ],
                'direction' => [
                    'type' => 'string',
                    'enum' => ['down', 'up', 'top', 'bottom'],
                    'description' => 'Scroll direction (scroll action).',
                ],
                'amount' => [
                    'type' => 'integer',
                    'description' => 'Scroll amount in pixels (default 600).',
                ],
                'full_page' => [
                    'type' => 'boolean',
                    'description' => 'Capture full scrollable page in screenshot (default false).',
                ],
                'code' => [
                    'type' => 'string',
                    'description' => 'JavaScript expression to evaluate in the browser context.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in milliseconds for wait_for (default 15000).',
                ],
                'exact' => [
                    'type' => 'boolean',
                    'description' => 'Require exact text match for click by text (default false).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $action = $arguments['action'] ?? null;

        if (! $action) {
            throw new RuntimeException('action is required.');
        }

        $params = array_filter([
            'url' => $arguments['url'] ?? null,
            'selector' => $arguments['selector'] ?? null,
            'text' => $arguments['text'] ?? null,
            'label' => $arguments['label'] ?? null,
            'value' => $arguments['value'] ?? null,
            'key' => $arguments['key'] ?? null,
            'direction' => $arguments['direction'] ?? null,
            'amount' => $arguments['amount'] ?? null,
            'full_page' => $arguments['full_page'] ?? null,
            'code' => $arguments['code'] ?? null,
            'timeout' => $arguments['timeout'] ?? null,
            'exact' => $arguments['exact'] ?? null,
        ], fn ($v) => $v !== null);

        // Cast to object so empty params encodes as {} not [] in JSON
        $payload = json_encode([
            'action' => $action,
            'session_id' => $arguments['session_id'] ?? 'default',
            'params' => (object) $params,
        ]);

        $process = new Process(
            ['python3', $this->agentScript, $payload],
            null,
            array_merge(getenv(), [
                'BROWSER_HEADED' => config('services.browser.headed', 'true'),
                'CHROME_CDP_PORT' => (string) config('services.browser.cdp_port', ''),
                'DISPLAY' => getenv('DISPLAY') ?: ':0',
                'XAUTHORITY' => getenv('XAUTHORITY') ?: (getenv('HOME') ?: '/home/'.get_current_user()).'/.Xauthority',
                'DBUS_SESSION_BUS_ADDRESS' => getenv('DBUS_SESSION_BUS_ADDRESS') ?: 'unix:path=/run/user/'.posix_getuid().'/bus',
            ]),
        );

        $process->setTimeout(60);
        $process->run();

        $raw = trim($process->getOutput());

        if (! $process->isSuccessful() && $raw === '') {
            throw new RuntimeException('Browser agent error: '.trim($process->getErrorOutput()));
        }

        $result = json_decode($raw, true);

        if (! is_array($result)) {
            throw new RuntimeException('Browser agent returned invalid response: '.$raw);
        }

        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function publishAgentScript(): void
    {
        File::ensureDirectoryExists(storage_path('app/browser'));
        File::put($this->agentScript, $this->agentScriptContent());
    }

    private function agentScriptContent(): string
    {
        return <<<'PYTHON'
#!/usr/bin/env python3
"""LaraClaw Browser Agent — Playwright CDP bridge.
Each invocation connects to an already-running Chrome process via CDP,
performs one action, then exits. Chrome stays open between calls.
"""

import sys
import json
import os
import socket
import signal
import subprocess
import time

BASE = os.path.dirname(os.path.abspath(__file__))


# ── visual feedback helpers ───────────────────────────────────────────────────

_RIPPLE_CSS = """
@keyframes lc-ripple {
  0%   { transform: scale(0.3); opacity: 1; }
  100% { transform: scale(2.8); opacity: 0; }
}
@keyframes lc-cursor-pop {
  0%   { transform: scale(1); }
  40%  { transform: scale(0.7); }
  100% { transform: scale(1); }
}
"""

def _inject_css_once(page):
    """Inject the animation stylesheet once per page load."""
    try:
        page.evaluate("""() => {
            if (document.getElementById('__lc_styles')) return;
            const s = document.createElement('style');
            s.id = '__lc_styles';
            s.textContent = """ + json.dumps(_RIPPLE_CSS) + """;
            document.head.appendChild(s);
        }""")
    except Exception:
        pass


def _show_click(page, x, y):
    """Flash an orange ripple at (x, y) and show a small cursor dot."""
    _inject_css_once(page)
    try:
        page.evaluate(f"""() => {{
            const r = document.createElement('div');
            r.style.cssText = `
                position: fixed;
                left: {x - 20}px; top: {y - 20}px;
                width: 40px; height: 40px;
                border-radius: 50%;
                background: rgba(255, 120, 20, 0.55);
                pointer-events: none;
                z-index: 2147483647;
                animation: lc-ripple 0.55s ease-out forwards;
            `;
            const dot = document.createElement('div');
            dot.style.cssText = `
                position: fixed;
                left: {x - 6}px; top: {y - 6}px;
                width: 12px; height: 12px;
                border-radius: 50%;
                background: rgba(255, 80, 0, 0.9);
                pointer-events: none;
                z-index: 2147483647;
                animation: lc-cursor-pop 0.35s ease-out forwards;
            `;
            document.body.appendChild(r);
            document.body.appendChild(dot);
            setTimeout(() => {{ r.remove(); dot.remove(); }}, 600);
        }}""")
        time.sleep(0.25)
    except Exception:
        pass


def _show_typing_banner(page, text):
    """Show a dark banner in the top-right corner with the text being typed."""
    _inject_css_once(page)
    try:
        safe = json.dumps(str(text)[:80])
        page.evaluate(f"""() => {{
            const old = document.getElementById('__lc_typing');
            if (old) old.remove();
            const b = document.createElement('div');
            b.id = '__lc_typing';
            b.style.cssText = `
                position: fixed;
                top: 14px; right: 14px;
                background: rgba(15, 15, 15, 0.88);
                color: #fff;
                padding: 7px 13px;
                border-radius: 8px;
                font: 13px/1.4 monospace;
                max-width: 320px;
                word-break: break-all;
                pointer-events: none;
                z-index: 2147483647;
                border: 1px solid rgba(255,255,255,0.15);
            `;
            b.textContent = '⌨ ' + {safe};
            document.body.appendChild(b);
            setTimeout(() => b.remove(), 2500);
        }}""")
    except Exception:
        pass


def _element_center(locator):
    """Return (x, y) center of a locator's bounding box, or None."""
    try:
        box = locator.bounding_box(timeout=5000)
        if box:
            return box['x'] + box['width'] / 2, box['y'] + box['height'] / 2
    except Exception:
        pass
    return None, None


# ── helpers ──────────────────────────────────────────────────────────────────

def _session_dir(sid):
    d = os.path.join(BASE, 'sessions', sid)
    os.makedirs(d, exist_ok=True)
    return d


def _load_meta(sid):
    p = os.path.join(_session_dir(sid), 'meta.json')
    return json.load(open(p)) if os.path.exists(p) else {}


def _save_meta(sid, meta):
    with open(os.path.join(_session_dir(sid), 'meta.json'), 'w') as f:
        json.dump(meta, f)


def _alive(pid):
    try:
        os.kill(int(pid), 0)
        return True
    except Exception:
        return False


def _find_free_port():
    for port in range(9200, 9300):
        with socket.socket() as s:
            try:
                s.bind(('127.0.0.1', port))
                return port
            except OSError:
                continue
    raise RuntimeError('No free port in range 9200-9300')


def _find_chrome_exe():
    """Return the best Chrome/Chromium binary available, preferring system Chrome."""
    import shutil
    # Check absolute paths first (reliable when $PATH is restricted)
    absolute_candidates = [
        '/opt/google/chrome/google-chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/local/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/snap/bin/chromium',
    ]
    for path in absolute_candidates:
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return path
    # Try PATH-based lookup as fallback
    for name in ('google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium'):
        path = shutil.which(name)
        if path:
            return path
    # Last resort: Playwright's bundled Chromium
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        return pw.chromium.executable_path


def _port_open(port):
    """Return True if something is already listening on localhost:port."""
    try:
        with socket.socket() as s:
            s.settimeout(0.5)
            s.connect(('127.0.0.1', int(port)))
        return True
    except OSError:
        return False


def _wait_for_port(port, retries=50, delay=0.3):
    for _ in range(retries):
        try:
            with socket.socket() as s:
                s.connect(('127.0.0.1', port))
            return True
        except OSError:
            time.sleep(delay)
    return False


# ── main action dispatcher ────────────────────────────────────────────────────

def _run(payload):
    from playwright.sync_api import sync_playwright, TimeoutError as PWT

    action  = payload['action']
    sid     = payload.get('session_id', 'default')
    par     = payload.get('params') or {}
    if not isinstance(par, dict):
        par = {}
    sdir    = _session_dir(sid)
    ssdir   = os.path.join(sdir, 'screenshots')
    os.makedirs(ssdir, exist_ok=True)
    meta    = _load_meta(sid)
    headed  = os.environ.get('BROWSER_HEADED', 'true').lower() != 'false'
    cdp_env = os.environ.get('CHROME_CDP_PORT', '').strip()

    # ── launch ───────────────────────────────────────────────────────────────
    if action == 'launch':
        start_url = par.get('url', 'about:blank')

        # Option A: user has Chrome running with --remote-debugging-port=NNNN
        if cdp_env and _port_open(cdp_env):
            port = int(cdp_env)
            meta = {'pid': None, 'port': port, 'url': start_url, 'external': True}
            _save_meta(sid, meta)
            return {
                'status': 'connected',
                'session_id': sid,
                'port': port,
                'url': start_url,
                'note': f'Attached to your running Chrome on port {port}.',
            }

        # Kill previous session if still alive
        if meta.get('pid') and _alive(meta['pid']):
            try:
                os.kill(int(meta['pid']), signal.SIGTERM)
            except Exception:
                pass
            time.sleep(0.5)

        # Option B: launch system Chrome (or fallback to Playwright Chromium)
        port = int(cdp_env) if cdp_env else _find_free_port()
        exe  = _find_chrome_exe()
        cmd  = [
            exe,
            f'--remote-debugging-port={port}',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--no-first-run',
            '--no-default-browser-check',
            '--user-data-dir=' + os.path.join(sdir, 'profile'),
        ]
        if not headed:
            cmd.append('--headless')
        cmd.append(start_url)

        proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

        if not _wait_for_port(port):
            return {'error': 'Chrome did not start in time'}

        meta = {'pid': proc.pid, 'port': port, 'url': start_url, 'external': False}
        _save_meta(sid, meta)
        return {
            'status': 'launched',
            'session_id': sid,
            'pid': proc.pid,
            'port': port,
            'url': start_url,
            'headed': headed,
            'exe': exe,
        }

    # ── all other actions need an existing session ────────────────────────────
    port = meta.get('port')
    pid  = meta.get('pid')

    # Auto-relaunch if Chrome is gone or we have no session
    needs_launch = (not port) or (pid and not _alive(pid))
    if not needs_launch:
        # Double-check by trying to actually reach the CDP endpoint
        try:
            with socket.socket() as s:
                s.settimeout(1)
                s.connect(('127.0.0.1', int(port)))
        except OSError:
            needs_launch = True

    if needs_launch:
        # Check if the user's own Chrome is available first
        if cdp_env and _port_open(cdp_env):
            port = int(cdp_env)
            meta = {'pid': None, 'port': port, 'url': meta.get('url', 'about:blank'), 'external': True}
            _save_meta(sid, meta)
        else:
            # Kill stale process if any
            if pid and _alive(pid):
                try:
                    os.kill(int(pid), signal.SIGTERM)
                except Exception:
                    pass
                time.sleep(0.3)

            port = int(cdp_env) if cdp_env else _find_free_port()
            exe  = _find_chrome_exe()
            cmd  = [
                exe,
                f'--remote-debugging-port={port}',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--user-data-dir=' + os.path.join(sdir, 'profile'),
            ]
            if not headed:
                cmd.append('--headless')
            cmd.append('about:blank')

            proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if not _wait_for_port(port):
                return {'error': 'Chrome could not be started automatically.'}

            meta = {'pid': proc.pid, 'port': port, 'url': 'about:blank', 'external': False}
            _save_meta(sid, meta)

    with sync_playwright() as pw:
        try:
            browser = pw.chromium.connect_over_cdp(f'http://localhost:{port}')
        except Exception as e:
            return {'error': f'Cannot connect to Chrome on port {port}: {e}'}

        ctx  = browser.contexts[0] if browser.contexts else browser.new_context()
        page = ctx.pages[0] if ctx.pages else ctx.new_page()

        res = {}
        try:
            if action == 'navigate':
                page.goto(par['url'], wait_until=par.get('wait_until', 'domcontentloaded'), timeout=30000)
                meta['url'] = page.url
                res = {'url': page.url, 'title': page.title()}

            elif action == 'click':
                if 'text' in par:
                    loc = page.get_by_text(par['text'], exact=par.get('exact', False)).first
                    cx, cy = _element_center(loc)
                    if cx is not None:
                        _show_click(page, cx, cy)
                    loc.click(timeout=10000)
                elif 'selector' in par:
                    loc = page.locator(par['selector']).first
                    cx, cy = _element_center(loc)
                    if cx is not None:
                        _show_click(page, cx, cy)
                    loc.click(timeout=10000)
                else:
                    return {'error': 'click requires text or selector'}
                try:
                    page.wait_for_load_state('domcontentloaded', timeout=5000)
                except Exception:
                    pass
                meta['url'] = page.url
                res = {'clicked': par.get('text') or par.get('selector'), 'url': page.url, 'title': page.title()}

            elif action == 'fill':
                val = par.get('value', '')
                _show_typing_banner(page, val)
                if 'label' in par:
                    loc = page.get_by_label(par['label'])
                    cx, cy = _element_center(loc)
                    if cx is not None:
                        _show_click(page, cx, cy)
                    loc.fill(val, timeout=10000)
                    res = {'filled': par['label'], 'value': val}
                elif 'selector' in par:
                    loc = page.locator(par['selector'])
                    cx, cy = _element_center(loc)
                    if cx is not None:
                        _show_click(page, cx, cy)
                    loc.fill(val, timeout=10000)
                    res = {'filled': par['selector'], 'value': val}
                else:
                    return {'error': 'fill requires label or selector'}

            elif action == 'type':
                text = par.get('text', '')
                _show_typing_banner(page, text)
                page.keyboard.type(text)
                res = {'typed': text}

            elif action == 'press':
                key = par.get('key', 'Enter')
                page.keyboard.press(key)
                try:
                    page.wait_for_load_state('domcontentloaded', timeout=5000)
                except Exception:
                    pass
                meta['url'] = page.url
                res = {'pressed': key, 'url': page.url}

            elif action == 'select':
                if 'selector' not in par:
                    return {'error': 'select requires selector'}
                loc = page.locator(par['selector'])
                if 'value' in par:
                    loc.select_option(value=par['value'])
                elif 'label' in par:
                    loc.select_option(label=par['label'])
                else:
                    return {'error': 'select requires value or label'}
                res = {'selected': par['selector']}

            elif action == 'screenshot':
                count = len([f for f in os.listdir(ssdir) if f.endswith('.png')])
                path  = os.path.join(ssdir, f'shot_{count:03d}.png')
                page.screenshot(path=path, full_page=par.get('full_page', False))
                res = {'path': path, 'url': page.url, 'title': page.title()}

            elif action == 'get_text':
                sel = par.get('selector', 'body')
                txt = page.locator(sel).first.inner_text(timeout=10000)
                res = {'text': txt[:8000], 'url': page.url}

            elif action == 'get_url':
                res = {'url': page.url, 'title': page.title()}

            elif action == 'get_links':
                links = page.evaluate(
                    "Array.from(document.querySelectorAll('a[href]'))"
                    ".map(a=>({text:a.innerText.trim().slice(0,80),href:a.href}))"
                    ".filter(l=>l.text&&l.href.startsWith('http')).slice(0,60)"
                )
                res = {'links': links, 'count': len(links), 'url': page.url}

            elif action == 'wait_for':
                to = par.get('timeout', 15000)
                if 'text' in par:
                    page.get_by_text(par['text']).wait_for(timeout=to)
                elif 'selector' in par:
                    page.locator(par['selector']).wait_for(timeout=to)
                elif 'url' in par:
                    page.wait_for_url(par['url'], timeout=to)
                else:
                    return {'error': 'wait_for requires text, selector, or url'}
                meta['url'] = page.url
                res = {'waited': True, 'url': page.url}

            elif action == 'scroll':
                d   = par.get('direction', 'down')
                amt = par.get('amount', 600)
                if d == 'down':
                    page.evaluate(f'window.scrollBy(0,{amt})')
                elif d == 'up':
                    page.evaluate(f'window.scrollBy(0,-{amt})')
                elif d == 'top':
                    page.evaluate('window.scrollTo(0,0)')
                elif d == 'bottom':
                    page.evaluate('window.scrollTo(0,document.body.scrollHeight)')
                res = {'scrolled': d, 'amount': amt}

            elif action == 'evaluate':
                if 'code' not in par:
                    return {'error': 'evaluate requires code'}
                val = page.evaluate(par['code'])
                res = {'result': str(val)[:3000]}

            elif action == 'go_back':
                page.go_back(wait_until='domcontentloaded', timeout=10000)
                meta['url'] = page.url
                res = {'url': page.url, 'title': page.title()}

            elif action == 'go_forward':
                page.go_forward(wait_until='domcontentloaded', timeout=10000)
                meta['url'] = page.url
                res = {'url': page.url, 'title': page.title()}

            elif action == 'close':
                pid = meta.get('pid')
                if pid and not meta.get('external') and _alive(pid):
                    os.kill(int(pid), signal.SIGTERM)
                meta = {}
                res  = {'closed': True, 'session_id': sid}

            else:
                res = {'error': f'Unknown action: {action}'}

        except PWT as e:
            res = {'error': f'Timeout: {e}'}
        except Exception as e:
            res = {'error': str(e)}

        _save_meta(sid, meta)
        return res


# ── entry point ───────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'No payload provided. Pass JSON as first argument.'}))
        sys.exit(1)
    result = _run(json.loads(sys.argv[1]))
    print(json.dumps(result))
PYTHON;
    }
}

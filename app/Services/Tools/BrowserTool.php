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
        return 'Control a real Chrome browser. Use batch to run multiple steps in one call — this is ALWAYS faster and preferred over calling individual actions one at a time. Chrome launches automatically. Use this for any task requiring website interaction — never fall back to web search when this tool is available.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'batch',
                        'launch', 'navigate', 'click', 'double_click', 'right_click', 'fill', 'type', 'press',
                        'select', 'screenshot', 'screenshot_element', 'get_text', 'get_url', 'get_links',
                        'wait_for', 'scroll', 'evaluate', 'go_back', 'go_forward',
                        'hover', 'drag', 'upload', 'download', 'pdf',
                        'cookies', 'local_storage',
                        'new_tab', 'switch_tab', 'close_tab', 'list_tabs',
                        'save_auth', 'load_auth',
                        'close',
                    ],
                    'description' => implode(' ', [
                        'batch: PREFERRED — run multiple steps in one call (fastest). Pass steps=[{action,url,...},{action,selector,...},...]. Stops on first error and returns a screenshot.',
                        'launch: open Chrome (optionally pass url).',
                        'navigate: go to a url.',
                        'click: click an element — use text or selector. Pass wait_nav=true to wait for navigation.',
                        'double_click: double-click an element.',
                        'right_click: right-click to open context menu.',
                        'fill: fill an input — use label or selector + value.',
                        'type: type text character by character (for autocomplete fields).',
                        'press: press a keyboard key e.g. Enter, Tab, Escape.',
                        'select: choose a dropdown option — pass selector + value or label.',
                        'screenshot: capture the current page.',
                        'screenshot_element: capture a specific element — pass selector.',
                        'get_text: extract visible text from page or selector. Returns cleaned text.',
                        'get_url: get current URL and title.',
                        'get_links: list clickable links on the page.',
                        'wait_for: wait until text, selector, or url appears.',
                        'scroll: scroll the page — direction: down, up, top, bottom.',
                        'evaluate: run JavaScript and return result.',
                        'go_back / go_forward: browser history navigation.',
                        'hover: hover over selector or text.',
                        'drag: drag source to target — pass source_selector and target_selector.',
                        'upload: upload file — pass selector and file_path.',
                        'download: click download link — pass selector or text, optionally save_path.',
                        'pdf: save current page as PDF.',
                        'cookies: get/set/delete/clear cookies — pass cookie_action.',
                        'local_storage: get/set/remove/clear localStorage — pass storage_action.',
                        'new_tab: open a new tab, optionally navigate to url.',
                        'switch_tab: switch tab by tab_index or url fragment.',
                        'close_tab: close a tab by tab_index.',
                        'list_tabs: list all open tabs.',
                        'save_auth: save auth state to named profile.',
                        'load_auth: restore a saved auth profile.',
                        'close: close the browser session.',
                    ]),
                ],
                'steps' => [
                    'type' => 'array',
                    'description' => 'For batch action: array of step objects. Each step has an "action" key plus any params for that action e.g. [{action:"navigate",url:"https://..."},{action:"fill",label:"Email",value:"user@example.com"},{action:"click",text:"Sign in"}].',
                    'items' => ['type' => 'object'],
                ],
                'session_id' => [
                    'type' => 'string',
                    'description' => 'Browser session name. Defaults to "default".',
                ],
                'url' => ['type' => 'string', 'description' => 'URL to navigate to.'],
                'selector' => ['type' => 'string', 'description' => 'CSS selector or Playwright locator.'],
                'text' => ['type' => 'string', 'description' => 'Visible text of the element.'],
                'label' => ['type' => 'string', 'description' => 'Form field label text.'],
                'value' => ['type' => 'string', 'description' => 'Text to fill or option value for select.'],
                'key' => ['type' => 'string', 'description' => 'Keyboard key: Enter, Tab, Escape, Space, ArrowDown, etc.'],
                'direction' => ['type' => 'string', 'enum' => ['down', 'up', 'top', 'bottom'], 'description' => 'Scroll direction.'],
                'amount' => ['type' => 'integer', 'description' => 'Scroll amount in pixels (default 600).'],
                'full_page' => ['type' => 'boolean', 'description' => 'Capture full scrollable page in screenshot.'],
                'code' => ['type' => 'string', 'description' => 'JavaScript to evaluate in the browser.'],
                'timeout' => ['type' => 'integer', 'description' => 'Timeout in milliseconds (default 15000 for wait_for, 30000 for navigate).'],
                'wait' => ['type' => 'string', 'description' => 'CSS selector to wait for after action.'],
                'wait_nav' => ['type' => 'boolean', 'description' => 'Wait for page navigation after click.'],
                'exact' => ['type' => 'boolean', 'description' => 'Require exact text match (default false).'],
                'source_selector' => ['type' => 'string', 'description' => 'Drag source element selector.'],
                'target_selector' => ['type' => 'string', 'description' => 'Drag target element selector.'],
                'file_path' => ['type' => 'string', 'description' => 'Absolute path to file for upload.'],
                'save_path' => ['type' => 'string', 'description' => 'Path where download or PDF should be saved.'],
                'cookie_action' => ['type' => 'string', 'enum' => ['get', 'set', 'delete', 'clear'], 'description' => 'Cookie operation.'],
                'cookies' => ['type' => 'array', 'description' => 'Array of {name, value, domain?, path?} to set.'],
                'name' => ['type' => 'string', 'description' => 'Cookie name or localStorage key.'],
                'storage_action' => ['type' => 'string', 'enum' => ['get', 'set', 'remove', 'clear', 'get_all'], 'description' => 'localStorage operation.'],
                'tab_index' => ['type' => 'integer', 'description' => 'Zero-based tab index.'],
                'profile' => ['type' => 'string', 'description' => 'Auth profile name for save_auth/load_auth.'],
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
            'source_selector' => $arguments['source_selector'] ?? null,
            'target_selector' => $arguments['target_selector'] ?? null,
            'file_path' => $arguments['file_path'] ?? null,
            'save_path' => $arguments['save_path'] ?? null,
            'cookie_action' => $arguments['cookie_action'] ?? null,
            'cookies' => $arguments['cookies'] ?? null,
            'name' => $arguments['name'] ?? null,
            'storage_action' => $arguments['storage_action'] ?? null,
            'tab_index' => $arguments['tab_index'] ?? null,
            'profile' => $arguments['profile'] ?? null,
            'wait' => $arguments['wait'] ?? null,
            'wait_nav' => $arguments['wait_nav'] ?? null,
            'steps' => $arguments['steps'] ?? null,
        ], fn ($v) => $v !== null);

        $payload = json_encode([
            'action' => $action,
            'session_id' => $arguments['session_id'] ?? 'default',
            'params' => (object) $params,
        ]);

        // Batch actions can take longer
        $timeout = $action === 'batch' ? 300 : 60;

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

        $process->setTimeout($timeout);
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
Each invocation connects to a running Chrome via CDP, performs one or more
actions (batch), then exits. Chrome stays open between calls.
"""

import sys
import json
import os
import re
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
"""

def _inject_css_once(page):
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
    """Flash a ripple at (x, y) — no sleep, purely visual."""
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
                animation: lc-ripple 0.45s ease-out forwards;
            `;
            document.body.appendChild(r);
            setTimeout(() => r.remove(), 500);
        }}""")
    except Exception:
        pass


def _show_typing_banner(page, text):
    try:
        safe = json.dumps(str(text)[:80])
        page.evaluate(f"""() => {{
            const old = document.getElementById('__lc_typing');
            if (old) old.remove();
            const b = document.createElement('div');
            b.id = '__lc_typing';
            b.style.cssText = `
                position: fixed; top: 14px; right: 14px;
                background: rgba(15,15,15,0.88); color:#fff;
                padding:7px 13px; border-radius:8px;
                font:13px/1.4 monospace; max-width:320px;
                word-break:break-all; pointer-events:none;
                z-index:2147483647;
            `;
            b.textContent = '⌨ ' + {safe};
            document.body.appendChild(b);
            setTimeout(() => b.remove(), 2000);
        }}""")
    except Exception:
        pass


def _element_center(locator):
    try:
        box = locator.bounding_box(timeout=5000)
        if box:
            return box['x'] + box['width'] / 2, box['y'] + box['height'] / 2
    except Exception:
        pass
    return None, None


def _clean_text(txt):
    """Remove excessive whitespace from extracted page text."""
    txt = re.sub(r'[ \t]+', ' ', txt)
    txt = re.sub(r'\n{3,}', '\n\n', txt)
    return txt.strip()


def _page_context(page):
    """Return a brief summary of the current page state."""
    try:
        return {'url': page.url, 'title': page.title()}
    except Exception:
        return {}


def _auto_screenshot(page, ssdir, prefix='error'):
    """Take a screenshot and return its path, or None on failure."""
    try:
        count = len([f for f in os.listdir(ssdir) if f.endswith('.png')])
        path = os.path.join(ssdir, f'{prefix}_{count:03d}.png')
        page.screenshot(path=path)
        return path
    except Exception:
        return None


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
    import shutil
    absolute_candidates = [
        '/opt/google/chrome/google-chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/local/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/snap/bin/chromium',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe',
    ]
    for path in absolute_candidates:
        if os.path.isfile(path) and os.access(path, os.X_OK):
            return path
    for name in ('google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium'):
        path = shutil.which(name)
        if path:
            return path
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        return pw.chromium.executable_path


def _port_open(port):
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


def _launch_chrome(sdir, port, headed, start_url='about:blank'):
    exe = _find_chrome_exe()
    cmd = [
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
    return proc


# ── single action dispatcher ─────────────────────────────────────────────────

def _dispatch(action, par, page, ctx, meta, sid, ssdir):
    """Execute one browser action. Returns a result dict."""
    from playwright.sync_api import TimeoutError as PWT

    # Navigation actions that need explicit load-state waiting
    NAV_ACTIONS = {'navigate', 'go_back', 'go_forward', 'new_tab'}

    def post_wait(explicit_nav=False):
        """Wait only when explicitly requested or after navigation."""
        if par.get('wait_nav') or explicit_nav:
            try:
                page.wait_for_load_state('domcontentloaded', timeout=15000)
            except Exception:
                pass
        if par.get('wait'):
            try:
                page.locator(par['wait']).wait_for(timeout=par.get('timeout', 10000))
            except Exception:
                pass

    try:
        res = {}

        if action == 'navigate':
            to = par.get('timeout', 30000)
            page.goto(par['url'], wait_until='domcontentloaded', timeout=to)
            meta['url'] = page.url
            res = {'url': page.url, 'title': page.title()}

        elif action in ('click', 'double_click', 'right_click'):
            if 'text' in par:
                loc = page.get_by_text(par['text'], exact=par.get('exact', False)).first
            elif 'selector' in par:
                loc = page.locator(par['selector']).first
            else:
                return {'error': f'{action} requires text or selector'}
            cx, cy = _element_center(loc)
            if cx is not None:
                _show_click(page, cx, cy)
            if action == 'double_click':
                loc.dblclick(timeout=10000)
            elif action == 'right_click':
                loc.click(button='right', timeout=10000)
            else:
                loc.click(timeout=10000)
            post_wait()
            meta['url'] = page.url
            res = {action: par.get('text') or par.get('selector'), **_page_context(page)}

        elif action == 'fill':
            val = par.get('value', '')
            _show_typing_banner(page, val)
            if 'label' in par:
                loc = page.get_by_label(par['label'])
                cx, cy = _element_center(loc)
                if cx is not None:
                    _show_click(page, cx, cy)
                loc.fill(val, timeout=10000)
                post_wait()
                res = {'filled': par['label'], 'value': val}
            elif 'selector' in par:
                loc = page.locator(par['selector'])
                cx, cy = _element_center(loc)
                if cx is not None:
                    _show_click(page, cx, cy)
                loc.fill(val, timeout=10000)
                post_wait()
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
            post_wait(explicit_nav=(key == 'Enter'))
            meta['url'] = page.url
            res = {'pressed': key, **_page_context(page)}

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
            res = {'path': path, **_page_context(page)}

        elif action == 'screenshot_element':
            sel = par.get('selector')
            if not sel:
                return {'error': 'screenshot_element requires selector'}
            count = len([f for f in os.listdir(ssdir) if f.endswith('.png')])
            path  = os.path.join(ssdir, f'elem_{count:03d}.png')
            loc   = page.locator(sel).first
            if par.get('full_page'):
                loc.scroll_into_view_if_needed()
                box = loc.bounding_box()
                if box:
                    page.screenshot(path=path, clip={'x': box['x'], 'y': box['y'], 'width': box['width'], 'height': box['height']})
                else:
                    loc.screenshot(path=path)
            else:
                loc.screenshot(path=path)
            res = {'path': path, 'selector': sel}

        elif action == 'get_text':
            sel = par.get('selector', 'body')
            txt = page.locator(sel).first.inner_text(timeout=10000)
            txt = _clean_text(txt)
            res = {'text': txt[:6000], 'truncated': len(txt) > 6000, **_page_context(page)}

        elif action == 'get_url':
            res = _page_context(page)

        elif action == 'get_links':
            links = page.evaluate(
                "Array.from(document.querySelectorAll('a[href]'))"
                ".map(a=>({text:a.innerText.trim().slice(0,80),href:a.href}))"
                ".filter(l=>l.text&&l.href.startsWith('http')).slice(0,60)"
            )
            res = {'links': links, 'count': len(links), **_page_context(page)}

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
            res = {'waited': True, **_page_context(page)}

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
            page.go_back(wait_until='domcontentloaded', timeout=15000)
            meta['url'] = page.url
            res = _page_context(page)

        elif action == 'go_forward':
            page.go_forward(wait_until='domcontentloaded', timeout=15000)
            meta['url'] = page.url
            res = _page_context(page)

        elif action == 'hover':
            if 'selector' in par:
                loc = page.locator(par['selector']).first
            elif 'text' in par:
                loc = page.get_by_text(par['text'], exact=par.get('exact', False)).first
            else:
                return {'error': 'hover requires selector or text'}
            cx, cy = _element_center(loc)
            if cx is not None:
                _show_click(page, cx, cy)
            loc.hover(timeout=10000)
            res = {'hovered': par.get('selector') or par.get('text')}

        elif action == 'drag':
            src = par.get('source_selector')
            tgt = par.get('target_selector')
            if not src or not tgt:
                return {'error': 'drag requires source_selector and target_selector'}
            page.drag_and_drop(src, tgt)
            res = {'dragged': src, 'to': tgt}

        elif action == 'upload':
            sel = par.get('selector')
            fp  = par.get('file_path')
            if not sel or not fp:
                return {'error': 'upload requires selector and file_path'}
            page.locator(sel).set_input_files(fp, timeout=par.get('timeout', 10000))
            res = {'uploaded': fp, 'selector': sel}

        elif action == 'download':
            to   = par.get('timeout', 30000)
            save = par.get('save_path') or os.path.join(_session_dir(sid), 'downloads', 'download')
            os.makedirs(os.path.dirname(save) if os.path.dirname(save) else '.', exist_ok=True)
            with page.expect_download(timeout=to) as dl_info:
                if 'selector' in par:
                    page.locator(par['selector']).first.click()
                elif 'text' in par:
                    page.get_by_text(par['text'], exact=par.get('exact', False)).first.click()
                else:
                    return {'error': 'download requires selector or text'}
            download = dl_info.value
            suggested = download.suggested_filename
            dest = save if os.path.splitext(save)[1] else os.path.join(save, suggested)
            os.makedirs(os.path.dirname(dest) if os.path.dirname(dest) else '.', exist_ok=True)
            download.save_as(dest)
            res = {'downloaded': dest, 'filename': suggested}

        elif action == 'pdf':
            save = par.get('save_path') or os.path.join(_session_dir(sid), 'screenshots', f'page_{int(time.time())}.pdf')
            os.makedirs(os.path.dirname(save), exist_ok=True)
            page.pdf(path=save, print_background=True)
            res = {'path': save, **_page_context(page)}

        elif action == 'cookies':
            op = par.get('cookie_action', 'get')
            if op == 'get':
                res = {'cookies': ctx.cookies()}
            elif op == 'set':
                raw_cookies = par.get('cookies', [])
                if not isinstance(raw_cookies, list):
                    return {'error': 'cookies must be an array'}
                ctx.add_cookies(raw_cookies)
                res = {'set': len(raw_cookies)}
            elif op == 'delete':
                name = par.get('name')
                if not name:
                    return {'error': 'delete requires name'}
                existing = [c for c in ctx.cookies() if c['name'] != name]
                ctx.clear_cookies()
                if existing:
                    ctx.add_cookies(existing)
                res = {'deleted': name}
            elif op == 'clear':
                ctx.clear_cookies()
                res = {'cleared': True}
            else:
                return {'error': f'Unknown cookie_action: {op}'}

        elif action == 'local_storage':
            op  = par.get('storage_action', 'get')
            key = par.get('name') or par.get('key')
            val = par.get('value')
            if op == 'get':
                if not key:
                    return {'error': 'get requires name/key'}
                result = page.evaluate(f"localStorage.getItem({json.dumps(key)})")
                res = {'key': key, 'value': result}
            elif op == 'get_all':
                result = page.evaluate("JSON.stringify(Object.fromEntries(Object.entries(localStorage)))")
                res = {'storage': json.loads(result) if result else {}}
            elif op == 'set':
                if not key:
                    return {'error': 'set requires name/key'}
                page.evaluate(f"localStorage.setItem({json.dumps(key)}, {json.dumps(str(val))})")
                res = {'set': key, 'value': val}
            elif op == 'remove':
                if not key:
                    return {'error': 'remove requires name/key'}
                page.evaluate(f"localStorage.removeItem({json.dumps(key)})")
                res = {'removed': key}
            elif op == 'clear':
                page.evaluate("localStorage.clear()")
                res = {'cleared': True}
            else:
                return {'error': f'Unknown storage_action: {op}'}

        elif action == 'new_tab':
            new_page = ctx.new_page()
            tab_idx  = ctx.pages.index(new_page)
            if 'url' in par:
                to = par.get('timeout', 30000)
                new_page.goto(par['url'], wait_until='domcontentloaded', timeout=to)
            res = {'tab_index': tab_idx, 'url': new_page.url, 'title': new_page.title()}

        elif action == 'switch_tab':
            pages = ctx.pages
            if 'tab_index' in par:
                idx = int(par['tab_index'])
                if idx >= len(pages):
                    return {'error': f'tab_index {idx} out of range (have {len(pages)} tabs)'}
                page = pages[idx]
            elif 'url' in par:
                frag = par['url']
                matches = [p for p in pages if frag in p.url]
                if not matches:
                    return {'error': f'No tab with url containing: {frag}'}
                page = matches[0]
            else:
                return {'error': 'switch_tab requires tab_index or url'}
            page.bring_to_front()
            res = {'tab_index': ctx.pages.index(page), **_page_context(page)}

        elif action == 'close_tab':
            pages = ctx.pages
            idx   = int(par.get('tab_index', ctx.pages.index(page)))
            if idx >= len(pages):
                return {'error': f'tab_index {idx} out of range'}
            pages[idx].close()
            remaining = [{'index': i, 'url': p.url, 'title': p.title()} for i, p in enumerate(ctx.pages)]
            res = {'closed_tab': idx, 'remaining_tabs': remaining}

        elif action == 'list_tabs':
            tabs = [{'index': i, 'url': p.url, 'title': p.title()} for i, p in enumerate(ctx.pages)]
            res  = {'tabs': tabs, 'count': len(tabs)}

        elif action == 'save_auth':
            profile  = par.get('profile', 'default')
            auth_dir = os.path.join(BASE, 'auth_profiles')
            os.makedirs(auth_dir, exist_ok=True)
            auth_path = os.path.join(auth_dir, f'{profile}.json')
            ctx.storage_state(path=auth_path)
            res = {'saved': profile, 'path': auth_path}

        elif action == 'load_auth':
            profile   = par.get('profile', 'default')
            auth_path = os.path.join(BASE, 'auth_profiles', f'{profile}.json')
            if not os.path.exists(auth_path):
                return {'error': f'Auth profile "{profile}" not found. Use save_auth first.'}
            state = json.load(open(auth_path))
            if state.get('cookies'):
                ctx.add_cookies(state['cookies'])
            if state.get('origins'):
                for origin in state['origins']:
                    for entry in origin.get('localStorage', []):
                        try:
                            page.goto(origin['origin'], wait_until='domcontentloaded', timeout=10000)
                            page.evaluate(f"localStorage.setItem({json.dumps(entry['name'])}, {json.dumps(entry['value'])})")
                        except Exception:
                            pass
            res = {'loaded': profile, 'cookies': len(state.get('cookies', []))}

        elif action == 'close':
            pid = meta.get('pid')
            if pid and not meta.get('external') and _alive(pid):
                os.kill(int(pid), signal.SIGTERM)
            meta.clear()
            res = {'closed': True}

        else:
            return {'error': f'Unknown action: {action}'}

        return {'session_id': sid, **res}

    except Exception as e:
        # Auto-screenshot on failure so agent can see what went wrong
        screenshot = _auto_screenshot(page, ssdir, prefix='error')
        err = {'error': str(e), 'session_id': sid}
        if screenshot:
            err['error_screenshot'] = screenshot
        return err


# ── main ─────────────────────────────────────────────────────────────────────

def _run(payload):
    from playwright.sync_api import sync_playwright

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

        if cdp_env and _port_open(cdp_env):
            port = int(cdp_env)
            meta = {'pid': None, 'port': port, 'url': start_url, 'external': True}
            _save_meta(sid, meta)
            return {'status': 'connected', 'session_id': sid, 'port': port}

        if meta.get('pid') and _alive(meta['pid']):
            try:
                os.kill(int(meta['pid']), signal.SIGTERM)
            except Exception:
                pass
            time.sleep(0.4)

        port = int(cdp_env) if cdp_env else _find_free_port()
        proc = _launch_chrome(sdir, port, headed, start_url)

        if not _wait_for_port(port):
            return {'error': 'Chrome did not start in time'}

        meta = {'pid': proc.pid, 'port': port, 'url': start_url, 'external': False}
        _save_meta(sid, meta)
        return {'status': 'launched', 'session_id': sid, 'pid': proc.pid, 'port': port, 'headed': headed}

    # ── ensure Chrome is running ──────────────────────────────────────────────
    port = meta.get('port')
    pid  = meta.get('pid')

    needs_launch = (not port) or (pid and not _alive(pid))
    if not needs_launch:
        try:
            with socket.socket() as s:
                s.settimeout(1)
                s.connect(('127.0.0.1', int(port)))
        except OSError:
            needs_launch = True

    if needs_launch:
        if cdp_env and _port_open(cdp_env):
            port = int(cdp_env)
            meta = {'pid': None, 'port': port, 'url': meta.get('url', 'about:blank'), 'external': True}
            _save_meta(sid, meta)
        else:
            if pid and _alive(pid):
                try:
                    os.kill(int(pid), signal.SIGTERM)
                except Exception:
                    pass
                time.sleep(0.3)
            port = int(cdp_env) if cdp_env else _find_free_port()
            proc = _launch_chrome(sdir, port, headed, 'about:blank')
            if not _wait_for_port(port):
                return {'error': 'Chrome could not be started automatically.'}
            meta = {'pid': proc.pid, 'port': port, 'url': 'about:blank', 'external': False}
            _save_meta(sid, meta)

    # ── connect and dispatch ──────────────────────────────────────────────────
    with sync_playwright() as pw:
        try:
            browser = pw.chromium.connect_over_cdp(f'http://localhost:{port}')
        except Exception as e:
            return {'error': f'Cannot connect to Chrome on port {port}: {e}'}

        ctx  = browser.contexts[0] if browser.contexts else browser.new_context()
        page = ctx.pages[0] if ctx.pages else ctx.new_page()

        # ── batch ─────────────────────────────────────────────────────────────
        if action == 'batch':
            steps = par.get('steps', [])
            if not steps:
                return {'error': 'batch requires a steps array'}

            results = []
            for i, step in enumerate(steps):
                step_action = step.get('action')
                if not step_action:
                    results.append({'step': i, 'error': 'step missing action'})
                    break
                step_par = {k: v for k, v in step.items() if k != 'action'}
                r = _dispatch(step_action, step_par, page, ctx, meta, sid, ssdir)
                results.append({'step': i, 'action': step_action, **r})
                if 'error' in r:
                    # Stop on first error — screenshot already taken by _dispatch
                    break

            _save_meta(sid, meta)
            return {
                'session_id': sid,
                'batch_results': results,
                'steps_completed': len(results),
                'total_steps': len(steps),
                **_page_context(page),
            }

        # ── single action ──────────────────────────────────────────────────────
        res = _dispatch(action, par, page, ctx, meta, sid, ssdir)
        _save_meta(sid, meta)
        return res


# ── entry point ───────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'No payload provided.'}))
        sys.exit(1)
    result = _run(json.loads(sys.argv[1]))
    print(json.dumps(result))
PYTHON;
    }
}

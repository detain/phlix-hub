/**
 * Logs page — client-side interactivity.
 *
 * Mirrors phlix-server's admin log viewer:
 *  - lists the hub `*.log` files (GET /api/v1/me/logs),
 *  - tails the selected file (GET /api/v1/me/logs/tail),
 *  - or watches every file merged into one stream (GET /api/v1/me/logs/tail-all),
 *  - with optional 5s auto-refresh, pinned to the newest lines.
 *
 * @package Phlix\Hub
 */

/** Sentinel value for the combined "watch every log file" view. */
const ALL_LOGS = '__all__';
const AUTO_REFRESH_MS = 5000;

const LogsPage = {
  els: {},
  timer: null,

  init() {
    this.els = {
      file: document.getElementById('log-file'),
      lines: document.getElementById('log-lines'),
      refresh: document.getElementById('log-refresh'),
      auto: document.getElementById('log-auto'),
      output: document.getElementById('log-output'),
      truncated: document.getElementById('log-truncated'),
    };

    this.els.file.addEventListener('change', () => void this.refresh());
    this.els.lines.addEventListener('change', () => void this.refresh());
    this.els.refresh.addEventListener('click', () => void this.refresh());
    this.els.auto.addEventListener('change', () => this.toggleAuto());

    void this.loadFiles();
  },

  /** Fetch JSON from an endpoint, redirecting to /login on 401/403. */
  async fetchJson(path, params) {
    const url = new URL(path, window.location.origin);
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
      }
    }
    const res = await fetch(url.toString(), { credentials: 'include' });
    if (!res.ok) {
      if (res.status === 401 || res.status === 403) {
        window.location.href = '/login';
        return null;
      }
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  },

  async loadFiles() {
    try {
      const data = await this.fetchJson('/api/v1/me/logs');
      if (data === null) return;
      const files = Array.isArray(data.files) ? data.files : [];
      const select = this.els.file;
      select.innerHTML = '';

      if (files.length === 0) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = '(no log files)';
        select.appendChild(opt);
        this.els.output.textContent = '(no log files)';
        return;
      }

      const allOpt = document.createElement('option');
      allOpt.value = ALL_LOGS;
      allOpt.textContent = 'All logs (combined)';
      select.appendChild(allOpt);

      for (const f of files) {
        const opt = document.createElement('option');
        opt.value = f.name;
        opt.textContent = f.name;
        select.appendChild(opt);
      }

      // Default to the first individual file.
      select.value = files[0].name;
      await this.refresh();
    } catch (err) {
      console.error('Failed to list logs:', err);
      this.els.output.textContent = 'Failed to list logs.';
    }
  },

  async refresh() {
    const file = this.els.file.value;
    if (file === '') return;
    const lines = Number(this.els.lines.value) || 200;

    this.els.refresh.disabled = true;
    try {
      const data =
        file === ALL_LOGS
          ? await this.fetchJson('/api/v1/me/logs/tail-all', { lines })
          : await this.fetchJson('/api/v1/me/logs/tail', { file, lines });
      if (data === null) return;

      const out = Array.isArray(data.lines) ? data.lines : [];
      this.els.output.textContent = out.length === 0 ? '(no output)' : out.join('\n');

      if (data.truncated === true) {
        this.els.truncated.style.display = '';
        this.els.truncated.textContent =
          'Showing the most recent ' +
          lines +
          ' lines (' +
          (file === ALL_LOGS ? 'more lines available across files' : 'file is larger') +
          ').';
      } else {
        this.els.truncated.style.display = 'none';
      }

      // Pin to the newest lines.
      this.els.output.scrollTop = this.els.output.scrollHeight;
    } catch (err) {
      console.error('Failed to read log:', err);
      this.els.output.textContent = 'Failed to read log.';
    } finally {
      this.els.refresh.disabled = false;
    }
  },

  toggleAuto() {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
    if (this.els.auto.checked) {
      this.timer = setInterval(() => void this.refresh(), AUTO_REFRESH_MS);
    }
  },
};

document.addEventListener('DOMContentLoaded', () => LogsPage.init());

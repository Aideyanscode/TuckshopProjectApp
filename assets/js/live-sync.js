/**
 * Polls sync-version.php so tables stay in sync across tabs and users.
 */
const LiveSync = {
  versions: {},
  handlers: {},
  timer: null,
  intervalMs: 3000,

  isEditingStock() {
    const el = document.activeElement;
    return el?.matches?.('input[data-stock-id]');
  },

  isStudentSearchFocused() {
    return document.activeElement?.id === 'student-search';
  },

  register(key, fn) {
    this.handlers[key] = fn;
  },

  async fetchVersions() {
    const data = await apiGet('sync-version.php');
    return {
      catalog: data.catalog,
      students: data.students,
      sellers: data.sellers,
      transactions: data.transactions,
      settings: data.settings,
    };
  },

  async syncVersions() {
    try {
      this.versions = await this.fetchVersions();
    } catch {
      /* ignore */
    }
  },

  async tick() {
    if (!Object.keys(this.handlers).length) return;
    try {
      const next = await this.fetchVersions();
      const tasks = [];
      for (const [key, fn] of Object.entries(this.handlers)) {
        if (this.versions[key] === undefined) continue;
        if (next[key] === this.versions[key]) continue;
        if (key === 'catalog' && this.isEditingStock()) continue;
        if (key === 'students' && this.isStudentSearchFocused()) continue;
        tasks.push(fn());
      }
      if (tasks.length) await Promise.all(tasks);
      this.versions = next;
    } catch {
      /* ignore */
    }
  },

  start(intervalMs = 3000) {
    this.stop();
    this.intervalMs = intervalMs;
    this.versions = {};
    this.tick();
    this.timer = setInterval(() => this.tick(), intervalMs);
  },

  stop() {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  },

  /** Refresh tables immediately after a local change, then align poll versions. */
  async refreshNow(keys, refreshFn) {
    await refreshFn();
    const next = await this.fetchVersions();
    const list = Array.isArray(keys) ? keys : [keys];
    for (const key of list) {
      if (next[key] !== undefined) this.versions[key] = next[key];
    }
  },
};

/** @deprecated Use LiveSync — kept for minimal churn */
const LiveCatalog = {
  start(fn) {
    LiveSync.register('catalog', fn);
    LiveSync.start();
  },
  stop: () => LiveSync.stop(),
  refreshNow: (fn) => LiveSync.refreshNow('catalog', fn),
};

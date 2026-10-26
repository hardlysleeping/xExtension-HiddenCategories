<?php
declare(strict_types=1);

/**
 * Hidden Categories
 *
 * Keep a set of categories out of the way.
 *
 *   Light  (default) — nothing is written to the feeds. The sidebar and the
 *                      article list are filtered in the browser.
 *   Strict           — feeds in a hidden category get FreshRSS's own
 *                      "only show in this category" setting, with the previous
 *                      value snapshotted so it can be restored exactly.
 *
 * This extension never deletes or renames a category, never moves a feed
 * between categories and never touches another extension's feed attributes.
 * Enabling or disabling it writes nothing to FreshRSS's own data.
 *
 * Step 1 of the build: skeleton + store + config page + mode switch. Hiding,
 * the sidebar and feed enforcement arrive in the next steps; nothing in this
 * file writes to a feed.
 */
class HiddenCategoriesExtension extends Minz_Extension
{
	private const MODE_LABELS = [
		'light' => 'Light — visual only',
		'strict' => 'Strict — enforce on feeds',
	];

	/** @var HiddenCategoriesStore|null */
	private $store = null;

	/**
	 * Two entry points, both registered from getConfigureView().
	 *
	 * The extension is deliberately INERT on normal pages: init() registers no
	 * hook, no script and no style, so enabling it cannot change how any page
	 * renders. Everything this extension does is either scoped to its own
	 * settings page or (from step 3) a feed attribute it has a snapshot for.
	 * That keeps "disabling it restores the previous state" true by
	 * construction rather than by cleanup.
	 */
	public function init(): void
	{
		require_once __DIR__ . '/store.php';

		// A broken state store must never take the instance down: report and
		// carry on with the safe defaults.
		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: state store unavailable: ' . $e->getMessage());
		}

		// The one global touch point: JsVars fires exactly once per page from
		// the page head. It carries category ids only (never feed or article
		// data), so the payload cannot grow with subscription count.
		$this->registerHook(Minz_HookType::JsVars, [$this, 'injectJsVars']);

		// Re-enabling in Strict must re-apply the hiding: after a disable the
		// feeds were restored to visible, so without this the extension comes
		// back claiming Strict while writing nothing. Runs at most once per
		// request and does no writes when nothing drifted (the common case), so
		// it is a cheap comparison, not a sweep of 1000 feeds.
		$this->reconcileIfNeeded();

		// Both files are optional by design: the CSS does the hiding from the
		// data attribute, the JS adds convenience (sidebar section, menus). If
		// either fails to load, hiding still works. The CSS is registered here in
		// init() because a stylesheet has to be in <head>, and it is the only
		// global thing this extension needs.
		Minz_View::appendStyle($this->getFileUrl('hidden.css'));
		Minz_View::appendScript($this->getFileUrl('hidden.js', 'js', true));
	}

	/**
	 * The page payload. Consumed by hidden.css (through the data attribute that
	 * hidden.js sets before paint) and by hidden.js.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function injectJsVars($vars = [])
	{
		if (!is_array($vars)) {
			$vars = [];
		}
		$cats = [];
		$counts = [];
		try {
			$cats = $this->store()->hiddenIds();
			foreach ($this->categories() as $c) {
				$counts[$c['id']] = ['name' => $c['name'], 'feeds' => $c['feeds']];
			}
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: could not build page payload: ' . $e->getMessage());
		}
		$vars['hiddenCategories'] = [
			'cats' => $cats,
			'mode' => $this->store()->mode(),
			// the LOCK overrides the eye: a locked instance always reports the
			// hidden view, so nothing on the page can reveal what is hidden
			'view' => $this->effectiveView(),
			'next' => $this->sessionUnlocked() ? $this->store()->nextView() : 'hidden',
			'locked' => $this->store()->isLocked() && !$this->sessionUnlocked(),
			'lockedArmed' => $this->store()->isLocked(),
			// the lock button is only offered when a passcode exists, so it can
			// never lead to a dead end
			'lockAvailable' => $this->store()->hasPasscode(),
			// how the sidebar row labels itself. Display only — neither value
			// changes what is hidden or enforced, so they are safe to send to
			// the page even while locked.
			'showCount' => $this->store()->showCount(),
			'rowMode' => $this->store()->rowMode(),
			'rowLabel' => $this->store()->rowLabel(),
			// names+counts for the hidden ones only — a hidden category with no
			// row in this map is named by its id, never by a guess
			'meta' => array_filter($counts, static fn ($id) => in_array($id, $cats, true), ARRAY_FILTER_USE_KEY),
			// _url() returns an HTML-escaped string ("&amp;"), which is correct
			// in markup and WRONG in a JS string: fetch() would send a parameter
			// literally named "amp;a" and every click would silently do nothing.
			// Decode once, here, so the JS receives a real URL.
			'api' => html_entity_decode((string)_url('extension', 'configure', 'e', urlencode($this->getName())), ENT_QUOTES),
		];
		return $vars;
	}

	/**
	 * Runs on every render of this extension's settings page (GET and POST),
	 * before the page is built. This is where the two page-load-only jobs live:
	 * finish an interrupted restore, and record "this extension was enabled"
	 * once so the round trip can be proven.
	 *
	 * It is deliberately NOT in init(): init() runs on every page of the
	 * instance, and this extension must stay inert outside its own settings.
	 * It cannot live in getConfigureView() either — that method is final in
	 * Minz_Extension and overriding it is a fatal error.
	 */
	public function handleConfigureAction(): void
	{
		Minz_View::appendScript($this->getFileUrl('config.js', 'js', true));

		try {
			$this->store()->assertSchema();
			$this->store()->checkpoint('enabled');
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: store check failed: ' . $e->getMessage());
		}

		$this->handleConfigurePost();
	}

	/**
	 * FreshRSS calls this from disableAction — before it writes
	 * extensions_enabled — and a non-true return BLOCKS the disable.
	 *
	 * This is the only code path that exists on toggle-off, so the whole
	 * "put it back" guarantee hangs off it:
	 *
	 *   1. every feed attribute this extension wrote is restored from its
	 *      snapshot (nothing to do in light mode, where nothing was written);
	 *   2. the state store keeps the user's own settings (mode, hidden set) so
	 *      re-enabling comes back exactly as it was;
	 *   3. if any restore could not be completed, return a string and REFUSE the
	 *      disable — FreshRSS then leaves the extension enabled and the user
	 *      still has the settings page to press Restore on. Leaving the
	 *      extension enabled is the safe failure: it can always be fixed from
	 *      the UI, whereas a half-restored instance with the extension gone is
	 *      only fixable from a shell.
	 *
	 * @return string|true
	 */
	public function uninstall()
	{
		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			return 'Hidden Categories: could not open its state store, so nothing was restored. '
				. 'The extension was left enabled. ' . $e->getMessage();
		}
		if (!$this->store()->available()) {
			return 'Hidden Categories: state store is unusable (' . $this->store()->error() . '), '
				. 'so nothing was restored. The extension was left enabled.';
		}

		// Restore every feed this extension changed. The sweep compares against
		// the hidden set and the mode, so running it with the mode forced to
		// light-equivalent semantics is exactly "put everything back":
		// temporarily switch the stored mode off, sweep, and switch it back so
		// re-enabling comes back in Strict as the user left it.
		$mode = $this->store()->mode();
		// Undo the "only hidden" view FIRST and unconditionally: if the user
		// disables while in that view, hundreds of feeds are sitting at -10 and
		// nothing else in this extension would ever bring them back.
		$inverse = $this->exitOnlyView();
		if ($mode === 'strict') {
			$this->store()->setMode('light');      // makes the sweep restore-only
		}
		$sweep = $this->sweepFeeds();
		if ($inverse['failed'] > 0) {
			$sweep['ok'] = false;
			$sweep['failed'] += $inverse['failed'];
			$sweep['message'] = $inverse['failed'] . ' feed(s) could not be restored from the "only hidden" view';
		}
		if ($mode === 'strict') {
			$this->store()->setMode($mode);        // remember it for next enable
		}

		if (!$sweep['ok']) {
			return 'Hidden Categories: ' . $sweep['message'] . '. Nothing was changed and the '
				. 'extension was left enabled so it can be fixed from its settings page.';
		}
		$this->store()->checkpoint('disabled');
		return true;
	}

	/**
	 * Is the current browser session unlocked?
	 *
	 * The unlock is remembered in the PHP session, not the database, so it is
	 * per browser and gone at logout/session end — the "remember for session"
	 * the lock was asked for. A session unlocked BEFORE the current lock was
	 * armed does not count: the stored lock stamp must be older than the
	 * unlock, which is what keeps one open tab from leaving another unlocked.
	 */
	public function sessionUnlocked(): bool
	{
		if (!$this->store()->isLocked()) {
			return true;
		}
		$unlocked = Minz_Session::paramString('hc_unlocked_at');
		if ($unlocked === '') {
			return false;
		}
		$lockedAt = $this->store()->getMeta('locked_at', '');
		return $lockedAt === '' || $unlocked >= $lockedAt;
	}

	/** The view that actually applies: lock overrides the eye entirely. */
	public function effectiveView(): string
	{
		return $this->sessionUnlocked() ? $this->store()->view() : 'hidden';
	}

	/**
	 * Peeking: restore every enforced feed to its original value WITHOUT
	 * dropping the snapshots, so leaving the peeking state can re-apply them.
	 *
	 * This is the only way "mixed" can mean anything in Strict: at -10 the
	 * server does not send those articles at all, so the stream has nothing to
	 * mix. The snapshots stay on disk precisely so the next sweep knows which
	 * feeds to hide again, and the original values are what they are restored to.
	 *
	 * @return array{ok:bool,enforced:int,restored:int,skipped:int,failed:int,message:string}
	 */
	private function suspendEnforcement(): array
	{
		$store = $this->store();
		$restored = 0;
		$failed = 0;
		$held = [];   // feed_id => orig_priority, kept so re-enforcing is exact
		foreach ($store->snapshots() as $snap) {
			$held[$snap['feed_id']] = $snap['orig_priority'];
		}
		// Which of those feeds still exist. A snapshot whose feed has been
		// deleted since describes nothing: writing its priority back to a row
		// that is gone fails, and counting that as a failure aborted this whole
		// pass — so the caller enforced nothing and every feed stayed visible.
		// Drop those rows instead of failing on them.
		$live = [];
		try {
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$live[(int)$feed['id']] = true;
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'enforced' => 0, 'restored' => 0,
				'skipped' => 0, 'failed' => 0,
				'message' => 'could not read feeds: ' . $e->getMessage()];
		}
		foreach ($held as $feedId => $orig) {
			if (!isset($live[$feedId])) {
				$store->forgetSnapshot($feedId);   // feed is gone, row is noise
				continue;
			}
			if (!$this->writePriority($feedId, $orig)) {
				$failed++;
				continue;
			}
			$restored++;
		}
		if ($failed > 0) {
			return ['ok' => false, 'enforced' => 0, 'restored' => $restored,
				'skipped' => 0, 'failed' => $failed,
				'message' => $failed . ' feed(s) could not be shown'];
		}
		// remember that the snapshots are "parked" so the reconcile in init()
		// does not immediately re-hide them on the next page load
		$store->setMeta('peek_parked', '1');
		return ['ok' => true, 'enforced' => 0, 'restored' => $restored,
			'skipped' => 0, 'failed' => 0, 'message' => ''];
	}

	/**
	 * The inverse pass: make "show only hidden" true for OTHER CLIENTS, not just
	 * this browser.
	 *
	 * The browser view is a filter on rendered articles, which no server-side
	 * setting can express. What a server CAN say is "this feed is not shown", so
	 * to show only the hidden categories everywhere, every NON-hidden feed is
	 * pushed to -10 while the view is on. Entering the view:
	 *
	 *   1. lift enforcement on the hidden feeds (they must be visible to render)
	 *   2. snapshot and demote every non-hidden feed
	 *
	 * Leaving it (sweepFeeds) restores both sets. The regression risk this
	 * guards against is a half-applied state, so if any write fails the pass
	 * reports failure and the caller says so rather than claiming success.
	 *
	 * @return array{ok:bool,enforced:int,restored:int,skipped:int,failed:int,message:string}
	 */
	private function enterOnlyView(): array
	{
		$store = $this->store();
		$hiddenIds = $store->hiddenIds();

		// 1. the hidden feeds become visible so their articles can render
		$lift = $this->suspendEnforcement();
		if (!$lift['ok']) {
			return $lift;
		}

		// 2. every other feed is demoted, with its original value recorded
		$current = [];
		$categoryOf = [];
		try {
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$id = (int)$feed['id'];
				$current[$id] = (int)$feed['priority'];
				$categoryOf[$id] = (int)$feed['category'];
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'enforced' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0,
				'message' => 'could not read feeds: ' . $e->getMessage()];
		}

		$demoted = 0;
		$skipped = 0;
		$failed = 0;
		foreach ($categoryOf as $feedId => $cat) {
			if (in_array($cat, $hiddenIds, true)) {
				continue;                     // a hidden feed stays in the view
			}
			$prio = $current[$feedId] ?? null;
			if ($prio === null) {
				continue;
			}
			// already recorded by a previous entry into this view: re-apply if it
			// drifted, but never overwrite the original value
			$orig = $store->inverseOrigFor($feedId);
			if ($orig === null) {
				if (!$store->addInverseSnapshot($feedId, $prio)) {
					$failed++;
					continue;
				}
				$orig = $prio;
			}
			if ($prio === HiddenCategoriesStore::PRIORITY_HIDDEN) {
				$skipped++;                   // already invisible, nothing to write
				continue;
			}
			if (!$this->writePriority($feedId, HiddenCategoriesStore::PRIORITY_HIDDEN)) {
				$failed++;
				$store->forgetInverseSnapshot($feedId);
				continue;
			}
			$demoted++;
		}

		if ($failed > 0) {
			return ['ok' => false, 'enforced' => $demoted, 'restored' => $lift['restored'],
				'skipped' => $skipped, 'failed' => $failed,
				'message' => $failed . ' feed(s) could not be filtered out for the view'];
		}
		return ['ok' => true, 'enforced' => $demoted, 'restored' => $lift['restored'],
			'skipped' => $skipped, 'failed' => 0, 'message' => ''];
	}

	/**
	 * Restore every feed the "only hidden" view demoted, then drop those rows.
	 * Safe to call when there are none (the common case).
	 *
	 * @return array{restored:int,failed:int}
	 */
	private function exitOnlyView(): array
	{
		$store = $this->store();
		$restored = 0;
		$failed = 0;
		$snaps = $store->inverseSnapshots();
		if ($snaps === []) {
			return ['restored' => 0, 'failed' => 0];
		}
		// Same rule as suspendEnforcement(): a demoted feed that has since been
		// deleted cannot be written back, and that must not read as a failure.
		$live = [];
		try {
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$live[(int)$feed['id']] = true;
			}
		} catch (Throwable $e) {
			return ['restored' => 0, 'failed' => count($snaps)];
		}
		foreach ($snaps as $snap) {
			if (!isset($live[$snap['feed_id']])) {
				$store->forgetInverseSnapshot($snap['feed_id']);
				continue;
			}
			if (!$this->writePriority($snap['feed_id'], $snap['orig_priority'])) {
				$failed++;
				continue;
			}
			$store->forgetInverseSnapshot($snap['feed_id']);
			$restored++;
		}
		return ['restored' => $restored, 'failed' => $failed];
	}

	/**
	 * Converge the feed settings to the hidden set, and prove it.
	 *
	 * This is the only function that writes `priority`. Everything that can
	 * change the desired state calls it: hiding, unhiding, switching to Strict,
	 * switching away from Strict, and enabling/disabling. One implementation
	 * means the buttons, the sweep and the uninstall path cannot disagree.
	 *
	 * In Light mode it only RESTORES (any stale snapshots from a previous
	 * Strict session get put back and dropped); it never writes a new value.
	 *
	 * $apply = false performs the same walk without writing anything, for the
	 * dry-run/diagnostic path.
	 *
	 * @return array{ok:bool,enforced:int,restored:int,skipped:int,failed:int,message:string}
	 */
	public function sweepFeeds(bool $apply = true): array
	{
		$store = $this->store();
		$strict = $store->mode() === 'strict';
		$hiddenIds = $store->hiddenIds();
		// A view that needs the hidden feeds VISIBLE cannot be enforced from
		// here. Peeking and "only hidden" both lift enforcement so the articles
		// render at all; at -10 the server sends none, so enforcing while either
		// is on leaves the categories in the sidebar with nothing inside them.
		// That is reachable from this extension's own settings list (hide a
		// category while the eye is on "only hidden") — measured: every hidden
		// feed pushed to -10 and the view went empty.
		// A sweep taken from any other path therefore converges to the state the
		// current view needs instead of fighting it.
		$view = $store->getMeta('view', 'hidden');
		$viewNeedsVisible = $strict && in_array($view, ['only', 'revealed'], true);
		if ($apply) {
			$store->setMeta('peek_parked', '0');   // a sweep ends any peek
			// a sweep is authoritative: any feed still demoted for the "only
			// hidden" view is put back first, so a mode switch or a disable can
			// never leave the inverse state behind
			if ($store->inverseSnapshotCount() > 0 && in_array($view, ['hidden', 'revealed'], true)) {
				$this->exitOnlyView();
			}
		}
		$enforced = 0;
		$restored = 0;
		$skipped = 0;
		$failed = 0;

		// feed id -> current priority, and feed id -> category, read once
		$current = [];
		$categoryOf = [];
		try {
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$id = (int)$feed['id'];
				$current[$id] = (int)$feed['priority'];
				$categoryOf[$id] = (int)$feed['category'];
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'enforced' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0,
				'message' => 'could not read feeds: ' . $e->getMessage()];
		}

		// ---- 1. restore anything that should no longer be enforced ---------
		// Covers: unhidden, moved out of a hidden category, deleted, or Light
		// mode. Runs FIRST so a feed moved out is restored before anything else
		// touches it.
		foreach ($store->snapshots() as $snap) {
			$feedId = $snap['feed_id'];
			$stillHidden = $strict && isset($categoryOf[$feedId]) && in_array($categoryOf[$feedId], $hiddenIds, true);
			if ($stillHidden) {
				continue;                      // stays enforced, handle in pass 2
			}
			if (!isset($categoryOf[$feedId])) {
				// feed no longer exists: drop the snapshot, nothing to restore
				if ($apply) { $store->forgetSnapshot($feedId); }
				$skipped++;
				continue;
			}
			if (!$apply) {
				$restored++;
				continue;
			}
			$ok = $this->writePriority($feedId, $snap['orig_priority']);
			if (!$ok) {
				$failed++;
				continue;
			}
			$store->forgetSnapshot($feedId);
			$restored++;
		}

		// ---- 2. enforce every feed in a hidden category --------------------
		// Skipped entirely when the current view needs those feeds visible; the
		// view's own pass at the end of this method puts them in the right state.
		if ($strict && !$viewNeedsVisible) {
			foreach ($hiddenIds as $catId) {
				foreach ($categoryOf as $feedId => $cid) {
					if ($cid !== $catId) {
						continue;
					}
					$prio = $current[$feedId] ?? null;
					if ($prio === null) {
						continue;
					}
					// already category-only and not ours: leave it untouched
					if ($prio === HiddenCategoriesStore::PRIORITY_HIDDEN) {
						$skipped++;
						continue;
					}
					// already snapshotted (pass 1 kept it): re-apply if drifted
					$known = $store->origFor($feedId);
					if ($known === null) {
						$r = $store->enforceFeed($feedId, $prio);
						if ($r['state'] === 'preset') { $skipped++; continue; }
						if ($r['state'] === 'error') { $failed++; continue; }
					}
					if (!$apply) { $enforced++; continue; }
					if (!$this->writePriority($feedId, HiddenCategoriesStore::PRIORITY_HIDDEN)) {
						$failed++;
						// the snapshot is wrong if the write failed before any
						// change; drop it so a later run re-snapshots cleanly
						$store->forgetSnapshot($feedId);
						continue;
					}
					$store->confirmApplied($feedId);
					$enforced++;
				}
			}
		}

		if ($apply && $viewNeedsVisible) {
			// Converge to what this view needs instead of enforcing the hidden
			// set: 'only hidden' lifts the hidden feeds and demotes everything
			// else, peeking lifts the hidden feeds and leaves the rest alone.
			$viewSweep = $view === 'only' ? $this->enterOnlyView() : $this->suspendEnforcement();
			$enforced += (int)($viewSweep['enforced'] ?? 0);
			$restored += (int)($viewSweep['restored'] ?? 0);
			$skipped += (int)($viewSweep['skipped'] ?? 0);
			if (empty($viewSweep['ok'])) {
				$failed += max(1, (int)($viewSweep['failed'] ?? 0));
			}
		}

		if ($failed > 0) {
			return ['ok' => false, 'enforced' => $enforced, 'restored' => $restored,
				'skipped' => $skipped, 'failed' => $failed,
				'message' => $failed . ' feed(s) could not be updated'];
		}
		return ['ok' => true, 'enforced' => $enforced, 'restored' => $restored,
			'skipped' => $skipped, 'failed' => 0, 'message' => ''];
	}

	/** One line describing what a sweep did, for the notification. */
	private function sweepMessage(array $sweep): string
	{
		$sweep += ['enforced' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0];
		$bits = [];
		if ($sweep['enforced'] > 0) {
			$bits[] = $sweep['enforced'] . ' feed(s) now hidden from the main stream';
		}
		if ($sweep['restored'] > 0) {
			$bits[] = $sweep['restored'] . ' feed(s) restored';
		}
		if ($sweep['skipped'] > 0) {
			$bits[] = $sweep['skipped'] . ' already in that state';
		}
		if ($sweep['failed'] > 0) {
			$bits[] = $sweep['failed'] . ' FAILED';
		}
		if ($bits === []) {
			return '';
		}
		return ' (' . implode(', ', $bits) . ')';
	}

	/**
	 * Write one feed's priority and confirm it landed.
	 *
	 * Uses FreshRSS's own DAO rather than raw SQL so the write goes through the
	 * same path the UI uses, including any bookkeeping the DAO does. The value is
	 * re-read afterwards: a write that returns without an error but did not
	 * change the row is treated as a failure, because a silent no-op here means
	 * the feed stays visible while the UI claims it is hidden.
	 */
	private function writePriority(int $feedId, int $priority): bool
	{
		try {
			FreshRSS_Factory::createFeedDao()->updateFeed($feedId, ['priority' => $priority]);
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: priority write failed for feed ' . $feedId . ': ' . $e->getMessage());
			return false;
		}
		try {
			$feed = FreshRSS_Factory::createFeedDao()->searchById($feedId);
			return $feed !== null && (int)$feed->priority() === $priority;
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Run the sweep only when something can actually have drifted, so a normal
	 * page load costs one cheap comparison rather than a 1000-feed walk.
	 *
	 * It is needed in exactly these cases:
	 *   - Strict with hidden categories but no snapshots (re-enabled, or a
	 *     hidden category gained feeds since the last sweep)
	 *   - snapshots exist but that feed is no longer in a hidden category
	 *
	 * Both are answered from the store + one feed count, never by writing.
	 */
	private function reconcileIfNeeded(): void
	{
		try {
			$store = $this->store();
			if (!$store->available()) {
				return;
			}
			if ($store->mode() !== 'strict') {
				// Light: only ever needs to restore leftovers, which only exist
				// if a previous Strict run was interrupted.
				if ($store->snapshotCount() > 0) {
					$this->sweepFeeds();
				}
				return;
			}

			// Peeking intentionally leaves the feeds visible while keeping the
			// snapshots. Re-hiding them here would undo the peek on the very
			// next page load.
			// Both 'revealed' and 'only' park the snapshots deliberately; the
			// feeds must stay visible for those views to render anything.
			$parkedView = in_array($store->view(), ['revealed', 'only'], true);
			if ($store->getMeta('peek_parked', '0') === '1' && $parkedView) {
				return;
			}
			if ($store->getMeta('peek_parked', '0') === '1') {
				// left the peek by any other route: clear the flag and converge
				$store->setMeta('peek_parked', '0');
			}

			$hidden = $store->hiddenIds();
			if ($hidden === []) {
				if ($store->snapshotCount() > 0) {
					$this->sweepFeeds();
				}
				return;
			}

			// Strict with things hidden: does every feed in a hidden category
			// already have a snapshot? Count them in one query rather than
			// walking the feed table.
			$missing = $this->feedsMissingSnapshot($hidden);
			if ($missing > 0 || $store->snapshotCount() === 0) {
				$this->sweepFeeds();
			}
		} catch (Throwable $e) {
			// A reconcile failure must never break a page render.
			Minz_Log::warning('HiddenCategories: reconcile skipped: ' . $e->getMessage());
		}
	}

	/**
	 * How many feeds sit in a hidden category without a snapshot.
	 *
	 * Counted per feed, NOT as "feeds in hidden categories minus snapshot rows":
	 * that subtraction was negative whenever the table held snapshots for feeds
	 * the user had since deleted, and a negative answer meant this never asked
	 * for a sweep again — so a feed later added to a hidden category stayed
	 * visible until some other action forced one.
	 *
	 * One selectAll() pass over the feed table (the same call the sidebar and
	 * the settings page already make), no per-feed query, no writes. It exists
	 * only to decide whether a sweep is worth running; the sweep itself does the
	 * real work. A feed that was already at -10 before this extension touched
	 * it is counted here every time and is then skipped by the sweep, which
	 * costs one comparison and writes nothing.
	 *
	 * @param list<int> $hiddenIds
	 */
	private function feedsMissingSnapshot(array $hiddenIds): int
	{
		if ($hiddenIds === []) {
			return 0;
		}
		try {
			$snapped = [];
			foreach ($this->store()->snapshots() as $snap) {
				$snapped[$snap['feed_id']] = true;
			}
			$missing = 0;
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$id = (int)$feed['id'];
				if (in_array((int)$feed['category'], $hiddenIds, true) && !isset($snapped[$id])) {
					$missing++;
				}
			}
			return $missing;
		} catch (Throwable $e) {
			return 0;
		}
	}

	/** Lazily built store, rooted in this user's own extension data directory. */
	public function store(): HiddenCategoriesStore
	{
		if ($this->store === null) {
			require_once __DIR__ . '/store.php';
			$this->store = new HiddenCategoriesStore($this->getExtensionUserPath());
		}
		return $this->store;
	}

	/** @return array<string,string> */
	public function modeLabels(): array
	{
		return self::MODE_LABELS;
	}

	/**
	 * Every category with its feed count, for the config table.
	 *
	 * @return list<array{id:int,name:string,feeds:int}>
	 */
	public function categories(): array
	{
		$out = [];
		try {
			$counts = [];
			$st = FreshRSS_Factory::createFeedDao()->selectAll();
			foreach ($st as $feed) {
				$cid = (int)$feed['category'];
				$counts[$cid] = ($counts[$cid] ?? 0) + 1;
			}
			foreach (FreshRSS_Factory::createCategoryDao()->selectAll() as $row) {
				$id = (int)$row['id'];
				$out[] = [
					'id' => $id,
					'name' => (string)$row['name'],
					'feeds' => (int)($counts[$id] ?? 0),
				];
			}
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: could not list categories: ' . $e->getMessage());
		}
		return $out;
	}

	/**
	 * One category's display name, read from FreshRSS itself. Returns null when
	 * the category no longer exists, which is how a stale page revives a deleted
	 * category without silently hiding a different one that reused the id.
	 */
	public function categoryName(int $catId): ?string
	{
		foreach ($this->categories() as $c) {
			if ($c['id'] === $catId) {
				return $c['name'];
			}
		}
		return null;
	}

	// ------------------------------------------- programmatic entry points
	//
	// The settings form and anything that fronts this extension (an API, a
	// client) both go through these two methods. One behaviour path, so the
	// web UI and a client cannot diverge. Both take an explicit target rather
	// than toggling, and both return a result array instead of redirecting.

	// ------------------------------------------------------------ the lock
	//
	// The curtain's three controls, extracted for the same reason as the hide
	// methods above: the settings form and any client must share one behaviour
	// path. The store owns the passcode and the locked flag; these just report
	// what changed.

	/**
	 * Arms the curtain. Refused when no passcode exists, because there would be
	 * no way back in.
	 *
	 * @return array{ok:bool,changed:bool,message:string}
	 */
	public function lock(): array
	{
		$before = $this->store()->isLocked();
		$result = $this->store()->lock();

		return [
			'ok' => (bool) $result['ok'],
			'changed' => $before !== $this->store()->isLocked(),
			'message' => (string) $result['message'],
		];
	}

	/**
	 * Unlocks with the passcode. Global, not per session: the flag is stored
	 * once, so unlocking here unlocks everywhere, including the web UI.
	 *
	 * @return array{ok:bool,changed:bool,message:string}
	 */
	public function unlock(?string $given): array
	{
		$before = $this->store()->isLocked();
		$result = $this->store()->unlock($given);

		return [
			'ok' => (bool) $result['ok'],
			'changed' => $before !== $this->store()->isLocked(),
			'message' => (string) $result['message'],
		];
	}

	/**
	 * Sets or clears the passcode. An empty string disables the lock, matching
	 * the settings form.
	 *
	 * @return array{ok:bool,changed:bool,message:string}
	 */
	public function setPasscode(string $code): array
	{
		$before = $this->store()->hasPasscode();
		$result = $this->store()->setPasscode($code);

		return [
			'ok' => (bool) $result['ok'],
			'changed' => $before !== $this->store()->hasPasscode(),
			'message' => (string) $result['message'],
		];
	}

	/**
	 * Hide or show one category.
	 *
	 * Idempotent: hiding an already-hidden category changes nothing and says so.
	 *
	 * @return array{ok:bool,changed:bool,code:string,message:string,sweep?:array<string,mixed>}
	 */
	public function setCategoryHidden(int $catId, bool $hidden): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'changed' => false, 'code' => 'invalid_category', 'message' => 'No category was named, so nothing changed.'];
		}
		// Name comes from the database, never from the caller: a supplied name
		// could label one category while hiding another.
		$name = $this->categoryName($catId);
		if ($name === null) {
			return ['ok' => false, 'changed' => false, 'code' => 'category_not_found', 'message' => 'Category ' . $catId . ' no longer exists — nothing changed.'];
		}
		$result = $hidden
			? $this->store()->hideCategory($catId, $name)
			: $this->store()->unhideCategory($catId, $name);
		if (!$result['ok']) {
			return ['ok' => false, 'changed' => false, 'code' => 'store_error', 'message' => $result['message']];
		}
		// Converge the feeds to the new definition (no-op in Light except for
		// restoring anything left over from a previous Strict run).
		$sweep = $this->sweepFeeds();
		$extra = $this->sweepMessage($sweep);
		if (!$sweep['ok']) {
			return ['ok' => false, 'changed' => $result['changed'], 'code' => 'convergence_failed', 'message' => $result['message'] . ' But ' . $extra, 'sweep' => $sweep];
		}
		return ['ok' => true, 'changed' => $result['changed'], 'code' => '', 'message' => $result['message'] . $extra, 'sweep' => $sweep];
	}

	/**
	 * Switch the view to an explicit target: hidden, revealed or only.
	 *
	 * Named changeView() rather than setEffectiveView() because effectiveView()
	 * already means "what the user is actually seeing", which differs from the
	 * stored view while locked.
	 *
	 * @return array{ok:bool,code:string,message:string,sweep?:array<string,mixed>}
	 */
	public function changeView(string $view): array
	{
		// A hand-crafted view change while locked must not work, or the lock
		// could be bypassed from another tab.
		if (!$this->sessionUnlocked()) {
			return ['ok' => false, 'code' => 'locked', 'message' => 'Locked: unlock first.'];
		}
		if (!in_array($view, HiddenCategoriesStore::VIEWS, true)) {
			return ['ok' => false, 'code' => 'invalid_view', 'message' => 'Unknown view "' . $view . '"' . ': nothing was changed.'];
		}
		if (!$this->store()->setView($view)) {
			return ['ok' => false, 'code' => 'store_error', 'message' => 'Could not save the view: nothing was changed.'];
		}
		// PEEKING and ONLY-HIDDEN both need the feeds to exist in the page.
		// In Strict a hidden feed sits at -10 and the server never sends its
		// articles, so both states would render empty. So enforcement is
		// suspended for BOTH and re-applied when the eye returns to 'hidden'.
		// Light has nothing to lift.
		$sweep = ['ok' => true, 'enforced' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0, 'message' => ''];
		if ($this->store()->mode() === 'strict') {
			if ($view === 'only') {
				// Make the view true for other clients too: lift hiding on the
				// hidden feeds, demote everything else.
				$sweep = $this->enterOnlyView();
			} else {
				// Leaving the view: undo the inverse demotion first, or those
				// feeds would stay invisible for the next view.
				$back = $this->exitOnlyView();
				$sweep = ($view === 'revealed') ? $this->suspendEnforcement() : $this->sweepFeeds();
				$sweep['restored'] += $back['restored'];
				if ($back['failed'] > 0) {
					$sweep['ok'] = false;
					$sweep['failed'] += $back['failed'];
					$sweep['message'] = $back['failed'] . ' feed(s) could not be restored after the view';
				}
			}
		}
		if (!$sweep['ok']) {
			return ['ok' => false, 'code' => 'convergence_failed', 'message' => $sweep['message'], 'sweep' => $sweep];
		}
		$detail = $this->sweepMessage($sweep);
		return [
			'ok' => true,
			'code' => '',
			'message' => ($view === 'only' ? 'Showing only hidden categories.'
				: ($view === 'revealed' ? 'Peeking: hidden categories are mixed back in.'
					: 'Hidden categories are hidden again.')) . $detail,
			'sweep' => $sweep,
		];
	}

	/** POST handling only. Everything that must happen on ANY settings-page
	 *  render lives in handleConfigureAction() above. */
	private function handleConfigurePost(): void
	{
		if (!Minz_Request::isPost()) {
			return;
		}

		// paramStringNull distinguishes "field absent" from "field present but
		// empty". An absent action field is a malformed request, not an
		// instruction to change something — refuse it and change nothing.
		$act = Minz_Request::paramStringNull('act', true);
		if ($act === null || trim($act) === '') {
			Minz_Request::bad('Save failed: the form did not say what to change, so nothing was changed.', $this->selfUrl());
			return;
		}
		$act = trim($act);

		switch ($act) {
			case 'hide':
			case 'unhide':
				$r = $this->setCategoryHidden(Minz_Request::paramInt('cat'), $act === 'hide');
				if ($r['ok']) {
					Minz_Request::good($r['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($r['message'], $this->selfUrl());
				}
				return;

			case 'only':
				// Superseded by act=view; kept so an old bookmark or a stale
				// page still does the right thing instead of erroring.
				$want = Minz_Request::paramStringNull('on', true);
				$this->store()->setView($want === '1' ? 'only' : 'hidden');
				Minz_Request::good($want === '1' ? 'Showing only hidden categories.' : 'Showing everything again.', $this->selfUrl());
				return;

			case 'lock':
				// Locking must NOT change meta.view: the lock sits on top of the
				// eye, so unlocking returns you to whatever the eye was set to.
				// The "always hidden while locked" behaviour comes from the
				// server forcing the payload's view, not from rewriting it.
				$r = $this->store()->lock();
				if ($r['ok']) {
					Minz_Session::_param('hc_unlocked_at', '');
					Minz_Request::good($r['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($r['message'], $this->selfUrl());
				}
				return;

			case 'unlock':
				$code = Minz_Request::paramStringNull('code', true);
				$r = $this->store()->unlock(is_string($code) ? $code : null);
				// Return to the page the form was filled in on, so a wrong code
				// does not dump the user into the settings page. Only an internal
				// /i/ path is honoured — never an absolute URL, and never "//host".
				$back = Minz_Request::paramStringNull('back', true);
				$isSafeBack = is_string($back) && preg_match('~^/i/[\w\-./?=&%]*$~', $back) === 1
					&& strpos($back, '//') === false;
				// Minz_Request::good()/bad() take a PARAMS ARRAY, not a URL string
				// (a string throws TypeError and surfaces as a bare HTTP 500).
				// Fold the query back into ['c'=>…, 'a'=>…, '<param>'=>…].
				$dest = $this->selfUrl();
				if ($isSafeBack) {
					$qs = (string)parse_url($back, PHP_URL_QUERY);
					parse_str($qs, $parsed);
					$dest = [
						'c' => isset($parsed['c']) && is_string($parsed['c']) ? $parsed['c'] : 'index',
						'a' => isset($parsed['a']) && is_string($parsed['a']) ? $parsed['a'] : 'index',
					];
					foreach ($parsed as $k => $v) {
						if ($k === 'c' || $k === 'a' || !is_string($v)) {
							continue;
						}
						$dest[$k] = $v;
					}
				}
				if ($r['ok']) {
					// remember for THIS session only; the DB stays locked=0
					Minz_Session::_param('hc_unlocked_at', date('c'));
					Minz_Request::good($r['message'], $dest);
				} else {
					Minz_Request::bad($r['message'], $dest);
				}
				return;

			case 'unlock-here':
				// The settings page is the documented way back in when the
				// passcode is forgotten: this page already sits behind the
				// FreshRSS login, so posting the passcode back into the HTML
				// to prove you know it proves nothing — and it left the code
				// readable in the page source and in any screenshot.
				// This action therefore unlocks on the strength of the session
				// alone. It is only reachable from handleConfigurePost(), so
				// an unauthenticated request never reaches it.
				if (!$this->store()->isLocked()) {
					Minz_Request::good('Already unlocked.', $this->selfUrl());
					return;
				}
				$this->store()->setMeta('locked', '0');
				Minz_Session::_param('hc_unlocked_at', date('c'));
				Minz_Request::good('Unlocked for this session.', $this->selfUrl());
				return;

			case 'passcode':
				// Always allowed, locked or not: the settings page is the escape
				// hatch and must never need the code you may have forgotten.
				$code = Minz_Request::paramStringNull('passcode', true);
				$r = $this->store()->setPasscode(is_string($code) ? $code : '');
				if ($r['ok']) {
					Minz_Request::good($r['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($r['message'], $this->selfUrl());
				}
				return;

			case 'view':
				// One button, three states. Accepts an explicit target so the
				// button can send the state it displays; refuses anything else
				// rather than guessing. Lock and range checks live in changeView().
				$to = Minz_Request::paramStringNull('to', true);
				if ($to === null || trim($to) === '') {
					$to = $this->store()->nextView();
				}
				$r = $this->changeView(trim($to));
				if ($r['ok']) {
					Minz_Request::good($r['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($r['message'], $this->selfUrl());
				}
				return;

			case 'mode':
				$mode = Minz_Request::paramStringNull('mode', true);
				if ($mode === null || trim($mode) === '') {
					Minz_Request::bad('Save failed: no mode was sent, so nothing was changed.', $this->selfUrl());
					return;
				}
				$result = $this->store()->setMode(trim($mode));
				if (!$result['ok']) {
					Minz_Request::bad($result['message'], $this->selfUrl());
					return;
				}
				// Strict -> Light restores every enforced feed; Light -> Strict
				// enforces every hidden category. One sweep either way.
				$sweep = $this->sweepFeeds();
				$extra = $this->sweepMessage($sweep);
				if (!$sweep['ok']) {
					Minz_Request::bad($result['message'] . ' But ' . $extra, $this->selfUrl());
					return;
				}
				Minz_Request::good($result['message'] . $extra, $this->selfUrl());
				return;

			/**
			 * Put back everything this extension has changed. Same routine that
			 * runs on toggle-off, so the button and the toggle can never
			 * disagree. Safe to press repeatedly.
			 */
			case 'display':
				// The sidebar row's display state, saved from one form with one
				// Save button: a rowmode (caret / number / both) plus the label
				// text. Both are posted every time, so the handler reads the
				// full desired state instead of guessing which control moved.
				$mode = Minz_Request::paramStringNull('rowmode', true);
				$label = Minz_Request::paramStringNull('label', true);
				if ($mode === null || $label === null) {
					Minz_Request::bad('Save failed: the form was incomplete, so nothing was changed.', $this->selfUrl());
					return;
				}
				// The label is stored even in caret mode, so switching back to
				// "Label and number" does not lose what was typed.
				$rl = $this->store()->setRowLabel($label);
				if (!$rl['ok']) {
					Minz_Request::bad($rl['message'], $this->selfUrl());
					return;
				}
				$rm = $this->store()->setRowMode(trim($mode));
				if (!$rm['ok']) {
					Minz_Request::bad($rm['message'], $this->selfUrl());
					return;
				}
				$now = [
					'caret' => 'the caret only',
					'number' => 'the number on its own',
					'both' => '"' . $this->store()->rowLabel() . '" and the number',
				][$this->store()->rowMode()] ?? 'nothing';
				Minz_Request::good('Saved: the sidebar row shows ' . $now . '.', $this->selfUrl());
				return;

			case 'restore':
				// Same routine as a mode switch to Light: restore every feed this
				// extension changed. Deliberately does NOT unhide anything — the
				// hidden set is the user's choice, this only undoes feed writes.
				$storedMode = $this->store()->mode();
				if ($storedMode === 'strict') {
					$this->store()->setMode('light');
				}
				$result = $this->sweepFeeds();
				if ($storedMode === 'strict') {
					$this->store()->setMode($storedMode);
				}
				if ($result['ok']) {
					$detail = $this->sweepMessage($result);
					Minz_Request::good(
						'Restore finished' . ($detail === '' ? ': nothing needed restoring.' : ':' . $detail . '.'),
						$this->selfUrl()
					);
				} else {
					Minz_Request::bad(
						'Restore could not be completed: ' . $result['message'] . ' Nothing was changed.',
						$this->selfUrl()
					);
				}
				return;

			default:
				Minz_Request::bad('Unknown action "' . $act . '": nothing was changed.', $this->selfUrl());
				return;
		}
	}

	/**
	 * Redirect target for feedback messages.
	 *
	 * The extension name MUST sit under 'params': Minz_Request::forward() only
	 * carries 'params', so a top-level 'e' is dropped and the redirect lands on
	 * a nameless configure URL (404).
	 *
	 * @return array<string,mixed>
	 */
	private function selfUrl(): array
	{
		return ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
	}
}

# Changelog

Newest first. These version numbers match the [releases page](https://github.com/hardlysleeping/xExtension-HiddenCategories/releases).

**1.5.19** — the extension can be driven from outside its settings form, and the published copy catches up.

Hiding a category, changing the view and the three lock actions are available as methods on the
extension (`setCategoryHidden()`, `changeView()`, `lock()`, `unlock()`, `setPasscode()`). Each takes an
explicit target and returns what changed instead of redirecting, so the settings form and anything that
fronts this extension share one behaviour path rather than two that can drift.

This release also brings the published source back in line with the version running on a real
instance: the previous upload here was missing those entry points, so anything driving the extension
from outside the browser form — including automated tests — was told the extension could not hide
anything.

No migration, and nothing changes on upgrade.

**1.5.18** — a sweep can no longer blank the peeking and only-hidden views.

The peeking and only-hidden views lift the hiding on purpose: at *Do not show* the server sends no
articles, so the view would otherwise render empty. Any action that converged the feeds back to your
hidden set could still re-apply the hiding while one of those views was open, and the result was a
sidebar with the categories in it and nothing inside them. The reachable case was hiding a category
from the settings list while the eye was on only-hidden, which no longer has to be avoided.

`sweepFeeds()` now takes the current view into account. While the eye is on `revealed` or `only` it
converges to that view's own state — revealing the hidden feeds, and for `only` demoting everything
else — instead of enforcing the hidden set. Hiding, unhiding, changing mode and disabling all run
through that one sweep, so every one of them leaves the view you are looking at intact.

No migration, and nothing changes on upgrade.

**1.5.17** — the pin lock is armed by default on a fresh install.

A new install now comes up **locked with the pin `0000`**, so the controls are protected from the
moment the extension is switched on instead of sitting open until someone remembers to set a pin.

The pin and lock state are seeded with `INSERT OR IGNORE`, from the same `checkpoint('enabled')` path
that records the first-enable timestamp. Only the first enable writes, so an existing pin is never
overwritten, a lock state you chose is never changed, and re-enabling the extension leaves both
alone. Upgrading changes nothing on an install that already has a pin.

`0000` is a published default, so it is a curtain with the drawstring visible: it stops the person
who walks up to your screen, not someone who has read the README. Change it in the settings (1 to 6
digits) and it is yours. Clearing the field still disables the lock entirely.

**1.5.16** — the eye's label wraps between words again.

`white-space: nowrap` (1.5.15) kept "Just Hidden" on one line at the cost of the stacked label. The
label now wraps at the word boundaries the markup already provides — "Just" above "Hidden" — exactly
like the favourites button beside it, while `word-break: normal` stops it breaking mid-word. Same
font size as the favourites button; only the wrapping rule differed.

**1.5.15** — the passcode is digits only, labels stop fracturing mid-word, and the passcode field is
sized for a passcode.

*Passcode is 1–6 digits, numbers only.* The field takes digits and refuses more than six. Enforced in
`setPasscode()` on the server as well as in the form: `maxlength` and `pattern` are browser hints
that a hand-made POST ignores, and the code is compared as an exact string, so a longer or
non-numeric value could never be typed back in to unlock. Both fields strip anything that is not a
digit as you type. An empty field still clears the passcode and disables the lock. The wording on the
settings page is now **pin**, matching the sidebar.

*Labels no longer break mid-word.* With three controls sharing the row, "Hidden" was fracturing into
"Hidde" / "n" — `word-break: break-word` splits a word wherever it runs out of room. Labels are now
one line, elided with an ellipsis if they truly cannot fit, which reads as "too narrow" rather than
as broken text.

*Widths.* The lock is icon-only and takes its natural width (~23px) while the eye and the pin share
the rest (~113px each). Three labelled buttons cannot fit one 265px row — an equal third is about
85px, not enough for a glyph plus a word — so the key handing its column back is what lets the two
labelled buttons show their words whole.

*The passcode field is sized for a passcode.* It was `min-width: 12em`, wide enough for a sentence,
for a field that holds six digits. It is now seven characters wide and does not grow.

*The locked row no longer starves its neighbour.* An earlier attempt at this made the unlock form
size to its content, which let it claim the row and squash the favourites button to nothing. The
form takes one equal share, as it did in 1.5.14.

*(Passcode length: the stored value is an existing 4-digit code, still valid under the 1–6 rule.)*

**1.5.14** — equal-width controls, a passcode that cannot be pre-filled, and the settings page stops
printing the passcode.

*Row widths.* 1.5.13 left the lock at its natural width while the pin and the eye each took an
equal share, so with three controls on screen the lock came out narrower than the other two. All
three are now equal `flex: 1 1 0` columns and the lock centres its key glyph.

*Row order.* This extension's controls are inserted before the first control it does not own, rather
than appended. Appending made the position depend on which extension's script happened to run last,
so the eye could land to the right of the favorites star. That applies in every state now: eye, wide
lock, narrow lock and the locked unlock form.

*The passcode is never pre-filled.* The unlock field in the sidebar is rebuilt with a fresh id on
every render and `autocomplete="new-password"`, and its value is cleared after build, so a browser
or password manager that saved the passcode cannot put it back for you. Locking reloads the page, so
every lock gets a brand new field. A lock whose code is already typed in is not a lock.

*The settings page no longer contains the passcode.* The "Unlock now" button used to carry the
passcode in a hidden field, which put the code in the page source and in any screenshot of the
settings page, and contradicted the note beside it. It now posts a separate `unlock-here` action and
unlocks on the strength of the logged-in session — the settings page is already behind the FreshRSS
login, so this is the same escape hatch without the secret in the HTML. The visible passcode field
is also no longer rendered with a saved value in it.

No change to what Strict writes to feeds, and no change to Favorite Categories.

**1.5.13** — a genuinely even split with the favorites button.

1.5.12 stopped this extension deleting the favorites star, but the widths were still uneven: with
all three controls on screen the star took noticeably more room than the eye.

The row splits its **direct children** equally, and 1.5.12's fix wrapped this extension's eye and
lock in a single container. That container claimed one equal share and then divided its own share
again between the eye and the lock — so the eye got less than the star, which was a direct child
with a whole share to itself.

The wrapper is gone. The eye and the lock are now direct children of the row like any other
button, so all three take the same share. At the widths this was checked at, the star and the eye
come out pixel-identical; the icon-only lock kept its natural width rather than claiming a column
(**reversed in 1.5.14**, where the lock takes an equal column too).

Nothing else changed. No server-side code was touched.

**1.5.12** — the lock no longer eats the favorites button.

Both this extension and **Favorite Categories** put a control in the same row above the sidebar
tree, and the row is deliberately shared: whichever extension arrives first creates it, everyone
else appends. This extension was clearing that whole row on every render and rebuilding it with
only its own control, which deleted the favorites star. Because the star is drawn once and then
skips itself on later renders, it never came back — the button was gone for the life of the page.

This extension now writes into a slot of its own inside the row and never touches its siblings'
controls. The row already sizes every direct child equally, so the two controls split the width
evenly, and either still fills the row when the other is absent.

Nothing else changed: same buttons, same states, same passcode form. No server-side code was
touched.

**1.5.11** — the unlock button is a key.

The lock row's submit button said **Unlock**. It is now a key glyph, which fits the narrow sidebar
better and reads the same in every language. The word is not lost: the button keeps
`aria-label="Unlock"` and `title="Unlock"`, so screen readers and a hover both still say it.

The glyph is an inline SVG, drawn with the same stroke and inherited colour as the other icons in
the row, rather than the 🔑 character — that depends on the reader's emoji font, and renders as a
box on systems without one.

Nothing else changed. Same form, same `act=unlock` post, same passcode field. No server-side code
was touched.

**1.5.10** — README only: what each iOS app does with a hidden category.

1.5.9's README said both tested apps drop a hidden category and show it again on unhide. Testing
says otherwise, so the text now separates them: **FeedFlow** follows Strict fully, **Lire** keeps
the category as an empty folder because it caches its own category tree. A full refresh, clearing
the app's cache or re-adding the account is what clears it; there is nothing the server can send
that makes an app forget a folder. No code changed in this release.

**1.5.9** — a feed you deleted can no longer switch off Strict.

The Strict pass walks a saved list of "what this feed's visibility was before I changed it". That
list kept rows for feeds that no longer exist, and writing the saved value back to a deleted feed
fails. That failure was counted against the whole pass, so a pass that had hundreds of real feeds
to restore reported itself as failed and stopped before enforcing anything.

Two symptoms came out of it:

- Switching to **Just Hidden** did nothing at all server-side: the view was stored, the feeds were
  never demoted, and your phone saw everything. This was the visible bug.
- A feed added later to a hidden category could stay visible, because the "is a sweep needed?"
  check compared two counts and the dead rows made the answer always "no".

Rows for deleted feeds are now dropped as the pass walks past them, and the "is a sweep needed?"
check counts feeds instead of comparing totals. Nothing else changed: the write is still `priority`,
still per feed, still snapshot-and-restore, and no subscription is touched.

**1.5.8** — the eye's third label reads **Just Hidden**.

**1.5.5** — the button label names the state you are in.

1.5.3 flipped the labels to name the *destination* — what you would see after clicking. That turned
out to be unreadable in practice: the eye told you where you were going while you were looking at
something else, so the label never matched the page.

The label now names what is on screen **right now**:

| You are looking at | Button reads |
|---|---|
| hidden categories removed | **Hidden** |
| everything back, hidden ones marked | **All shown** |
| just the hidden categories | **Only hidden** |

The tooltip says the same thing in a sentence. Clicking still cycles in the same order.

**1.5.4** — two-word labels wrap instead of being cut off.

The eye button's label was clipped with an ellipsis, so *Only hidden* and *Show all* read as
*Only hid…*. The text now wraps onto two lines at the space, the way the star button in the
companion extension does. Stylesheet and label markup only.

**1.5.3** — the button labels now name the destination.

The labels named the *action*, which read ambiguously next to each other: from the normal state the
button said *Show all* while everything was already showing, and from the peek state it said *Show
all* again while the click actually narrowed things down to only the hidden categories.

Each label now says what you will be looking at **after** the click:

| Showing now | Button says | After clicking |
|---|---|---|
| hidden categories removed | **Show all** | everything back, hidden ones marked |
| everything back, hidden ones marked | **Only hidden** | just the hidden categories |
| just the hidden categories | **Hide** | hidden categories removed again |

Same three states, same cycle, same writes. Labels and tooltips only.

**1.5.0** — *show only hidden* now works on every client in Strict.

- It was browser-only, and that was a flaw: entering the view revealed the hidden feeds so their
  articles could render, which meant every synced client saw everything. A server can only say
  "this feed is not shown", not "show only these".
- Strict now enforces the inverse too: entering the view reveals the hidden feeds **and** sets every
  non-hidden feed to *Do not show*. Your phone then shows exactly the hidden categories, and
  leaving the view restores all of them.
- The feeds demoted to make the view work are recorded in their own table, never mixed with your
  hidden set, and are restored on every exit path including disabling the extension.
- Cost: a sweep on entry and exit, sized to your non-hidden feed count.

**1.4.5** — the settings page now says the right-click item needs the RightClickActions extension,
instead of leaving that only in this README.

**1.4.4** — the control bar above the sidebar became shared ground, so a second extension can put a
button beside the eye. Its children size themselves: one control takes the full width, two split it.

**1.4.3** — the sidebar row's three display states, including label-only.

- The on/off "show the count" checkbox is replaced by a three-option choice: **nothing but the
  caret**, **the number only**, or **label and number**. The checkbox could not express "label but
  no number", which is the state that actually obscures the row.
- The label under **Label text** is kept when you switch to another state, so moving to caret-only
  and back does not lose it. An empty label in the third state falls back to the number alone.
- The display state is stored explicitly (`rowmode`) instead of being inferred from two flags.
  Existing settings are migrated automatically on read; nothing is lost.
- 1.4.2 and 1.4.1 are folded in: the label is free text (it was an on/off prefix switch), and both
  settings save with one **Save** button, a live **After saving** preview, and an
  "Unsaved changes" line.

**1.3.5** — the `HIDDEN` tag gained a note on when it appears; the tag illustration was redrawn.

Earlier versions are on the [releases page](https://github.com/hardlysleeping/xExtension-HiddenCategories/releases).

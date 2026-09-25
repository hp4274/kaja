<?php
/**
 * The booking modal, shared by the dashboard and the sessions calendar.
 *
 * It lived only in the sessions page before, tucked into the "Upcoming
 * Sessions" panel header — so the dashboard showed a calendar you could click
 * but never book from, and clicking a day on either calendar opened a
 * read-only list. Both surfaces now open the same modal, from the same code,
 * with the clicked day already filled in.
 *
 * Requires $db in the including scope. The including page must also emit
 * window.CAL_MONTH (a "YYYY-MM" string) so a clicked day number can be turned
 * into a real date.
 */

require_once __DIR__ . '/../../includes/booking-slots.php';

// Only bookable clients belong in the picker -- see clientIsBookable(): intake
// in, a human has read it, treatment live.
$bookableClients = $db->query("
    SELECT `id`, `first_name`, `last_name` FROM `clients`
    WHERE `status` = 'active' ORDER BY `first_name` ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Everyone held short of bookable, so the picker can say why it is short or
// empty instead of just looking broken.
$heldClients    = $db->query("
    SELECT `status`, COUNT(*) AS n FROM `clients`
    WHERE `status` IN ('pending','review') GROUP BY `status`
")->fetchAll(PDO::FETCH_KEY_PAIR);
$awaitingReview = (int) ($heldClients['review'] ?? 0);
$awaitingIntake = (int) ($heldClients['pending'] ?? 0);
?>
<div class="modal-overlay" id="newSessionModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Schedule New Session</div>
      <button class="modal-close" type="button" onclick="closeNewSessionModal()" aria-label="Close">&times;</button>
    </div>
    <form onsubmit="createSession(event)">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label" for="new-session-client">Client</label>
          <select class="form-select" id="new-session-client" name="client_id" required <?php echo $bookableClients ? '' : 'disabled'; ?>>
            <option value="">Select a client...</option>
            <?php foreach ($bookableClients as $cl): ?>
              <option value="<?php echo (int) $cl['id']; ?>"><?php echo htmlspecialchars($cl['first_name'] . ' ' . $cl['last_name']); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($awaitingReview || $awaitingIntake): ?>
            <?php
            // A client missing from this list reads as a broken page unless the
            // page says where they are instead. Both holds clear in one click,
            // so the note links to where that happens.
            $held = [];
            if ($awaitingReview) { $held[] = $awaitingReview . ' awaiting review'; }
            if ($awaitingIntake) { $held[] = $awaitingIntake . ' still to send intake back'; }
            ?>
            <small class="hint is-block">
              Only active clients can be booked. <?php echo htmlspecialchars(implode(', ', $held)); ?> &mdash;
              <a href="index.php?page=clients">open Clients</a> to move them on.
            </small>
          <?php elseif (!$bookableClients): ?>
            <small class="hint is-block">
              No active clients yet. A client becomes bookable once their intake is in and you have marked it reviewed.
            </small>
          <?php endif; ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="new-session-date">Date</label>
            <input type="date" class="form-input" id="new-session-date" name="session_date" required />
            <!-- Every date picked on the calendar, first one included. Empty
                 unless the therapist ctrl- or shift-clicked more than one. -->
            <input type="hidden" id="new-session-dates" name="session_dates" value="" />
            <small id="new-session-dates-note" class="hint is-block" hidden></small>
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-time">Time</label>
            <!-- The same slots the public forms offer. A free time input here
                 let the calendar hold appointments at times no visitor was ever
                 shown, which is how the two halves of the practice drifted. -->
            <select class="form-select" id="new-session-time" name="session_time" required>
              <option value="" disabled selected hidden>Select a time</option>
              <?php foreach (bookingSlots() as $slot): ?>
                <option value="<?php echo htmlspecialchars($slot); ?>"><?php echo htmlspecialchars(bookingSlotLabel($slot)); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="new-session-duration">Duration (min)</label>
            <input type="number" class="form-input" id="new-session-duration" name="duration" value="<?php echo (int) getSettingInt('default_session_duration', 60); ?>" min="15" step="15" />
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-type">Type</label>
            <select class="form-select" id="new-session-type" name="session_type">
              <option value="online">Online</option>
              <option value="inperson">In-Person</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="new-session-repeat">Repeat</label>
            <select class="form-select" id="new-session-repeat" name="repeat">
              <option value="none">Does not repeat</option>
              <option value="weekly">Weekly</option>
              <option value="fortnightly">Fortnightly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
        </div>

        <div class="form-group" id="new-session-repeat-end" hidden>
          <label class="form-label" for="new-session-repeat-end-type">Until</label>
          <div class="form-inline">
            <select class="form-select form-grow" id="new-session-repeat-end-type" name="repeat_end_type">
              <option value="count">After N sessions</option>
              <option value="date">On a date</option>
              <option value="open">Keep going (I will stop it)</option>
            </select>
            <input class="form-input form-grow" name="repeat_end_value" value="4" aria-label="Repeat until value" />
          </div>
          <small class="hint">Occurrences that clash with an existing session are skipped, not booked.</small>
        </div>

        <div class="form-group">
          <label class="form-label" for="new-session-notes">Notes</label>
          <textarea class="form-textarea" id="new-session-notes" name="notes"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeNewSessionModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" <?php echo $bookableClients ? '' : 'disabled'; ?>>Schedule</button>
      </div>
    </form>
  </div>
</div>

<script>
/**
 * Booking, shared by every surface that includes this file.
 *
 * openNewSessionModal() takes an optional "YYYY-MM-DD". Passing the day the
 * therapist actually clicked is the whole point: retyping a date you just
 * pointed at is how the wrong day gets booked.
 */
function openNewSessionModal(dateStr) {
  var modal = document.getElementById('newSessionModal');
  if (!modal) return;

  var dates = calendarSelection.dates();
  if (dates.length > 1) {
    // The calendar selection wins over the single day passed in: it is the
    // more specific thing the therapist just pointed at.
    dateStr = dates[0];
  }

  var dateField = document.getElementById('new-session-date');
  if (dateField && dateStr) dateField.value = dateStr;

  applyDateSelection(dates);
  modal.classList.add('open');

  var client = document.getElementById('new-session-client');
  if (client && !client.disabled) client.focus();
}

function closeNewSessionModal() {
  var modal = document.getElementById('newSessionModal');
  if (modal) modal.classList.remove('open');
}

/**
 * Show the picked dates in the modal and post them with the booking.
 *
 * One date behaves exactly as before. Several turn Repeat off: a recurrence
 * per date is two different ways of saying "more sessions" at once, and the
 * result is never what either input looked like it meant.
 */
function applyDateSelection(dates) {
  var hidden = document.getElementById('new-session-dates');
  var note   = document.getElementById('new-session-dates-note');
  var repeat = document.getElementById('new-session-repeat');
  var multi  = dates.length > 1;

  if (hidden) hidden.value = multi ? dates.join(',') : '';

  if (note) {
    note.hidden = !multi;
    if (multi) {
      note.textContent = dates.length + ' dates selected — ' + calendarDateSummary(dates)
                       + ' — one session at the chosen time on each.';
    }
  }

  if (repeat) {
    if (multi) { repeat.value = 'none'; }
    repeat.disabled = multi;
    var end = document.getElementById('new-session-repeat-end');
    if (end && multi) end.hidden = true;
  }
}

/**
 * A list of dates as "Aug (2, 9, 23)".
 *
 * Repeating the month once per day ("2 Aug, 9 Aug, 23 Aug") is three words of
 * noise for one fact; the days are what differ, so they are what the eye gets.
 * A selection spanning months keeps each month's own bracket: "Aug (28, 30),
 * Sep (4)".
 */
function calendarDateSummary(dates) {
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var order  = [];
  var byMonth = {};

  dates.forEach(function (iso) {
    var parts = String(iso).split('-');
    if (parts.length !== 3) return;
    var key = months[parseInt(parts[1], 10) - 1] || parts[1];
    if (!byMonth[key]) { byMonth[key] = []; order.push(key); }
    byMonth[key].push(parseInt(parts[2], 10));
  });

  return order.map(function (m) {
    return m + ' (' + byMonth[m].join(', ') + ')';
  }).join(', ');
}

/** Turn a day number from the rendered calendar into a real date. */
function calendarDate(day) {
  var month = window.CAL_MONTH || '';
  if (!month) return '';
  return month + '-' + (day < 10 ? '0' + day : String(day));
}

function createSession(e) {
  e.preventDefault();

  var form = e.target;
  var btn  = form.querySelector('button[type="submit"]');
  var label = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = 'Scheduling...'; }

  var fd = new FormData(form);
  fd.append('action', 'add_session');

  fetch('api/sessions.php', { method: 'POST', body: fd })
    .then(function (r) { return r.text(); })
    .then(function (text) {
      var data;
      try {
        data = JSON.parse(text);
      } catch (err) {
        // A PHP warning printed ahead of the JSON turns a booking that actually
        // worked into a silent failure. Show what came back instead of "Error".
        throw new Error('Unexpected server response: ' + text.slice(0, 160));
      }
      if (!data.success) {
        throw new Error(data.error || 'The session could not be booked.');
      }

      var msg = data.booked > 1 ? (data.booked + ' sessions scheduled') : 'Session scheduled';
      // A day that clashed was skipped, not booked. Saying so here is the only
      // place the therapist finds out before the calendar reloads without it.
      if (data.skipped && data.skipped.length) {
        msg += ' — skipped ' + calendarDateSummary(data.skipped) + ' (already booked)';
      }
      showToast(msg);
      calendarSelection.clear();
      // Both calendars are rendered server-side, so a reload is what puts the
      // new session on the dashboard and the sessions page at once.
      setTimeout(function () { location.reload(); }, 600);
    })
    .catch(function (err) {
      showToast(err.message || 'Network error', 'error');
      if (btn) { btn.disabled = false; btn.textContent = label; }
    });
}

(function () {
  var repeat = document.getElementById('new-session-repeat');
  var end    = document.getElementById('new-session-repeat-end');
  if (repeat && end) {
    repeat.addEventListener('change', function () {
      end.hidden = (repeat.value === 'none');
    });
  }

  var modal = document.getElementById('newSessionModal');
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeNewSessionModal();
    });
  }
})();
</script>

<style>
/* A day held in a multi-date selection. Distinct from the dashboard's single
   ".selected" outline, because the two mean different things: one is "the day
   you are looking at", this one is "a day you are about to book". */
.cal-cell.multi-selected {
  background: var(--clr-primary-light);
  box-shadow: inset 0 0 0 2px var(--clr-primary);
}
.cal-cell.multi-selected .cal-date { font-weight: 700; }

/* The day cells are focusable buttons, and nothing styled :focus -- so the
   browser's own ring stayed on the last day clicked and read as a seventh
   selected date sitting next to the six real ones. A ring that says "selected"
   must only ever appear on a selected day, so the focus ring is now dashed,
   inset, and shown only to keyboard users, who are the ones it is for. */
.cal-cell:focus:not(:focus-visible) { outline: none; box-shadow: none; }
.cal-cell:focus-visible {
  outline: 2px dashed var(--clr-text-muted);
  outline-offset: -4px;
}
/* Belt and braces with the blur() above: whatever a browser draws on a
   mouse-focused day, it is not allowed to look like the selection ring. */
.cal-cell:not(.multi-selected):focus:not(:focus-visible) {
  background: inherit;
}
.calendar-selection-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 0.75rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-md, 8px);
  background: var(--clr-primary-light);
  font-size: 0.82rem;
}
.calendar-selection-bar[hidden] { display: none; }
/* The flex lives here, not in a style attribute: an inline display outranks
   the [hidden] attribute, so the row stayed on screen with an unlabelled
   button in it whenever the selection was empty. */
.calendar-selection-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.calendar-selection-actions[hidden] { display: none; }
/* Nothing picked yet: the bar is only telling you the gesture exists, so it
   steps back out of the way instead of sitting there looking like a state. */
.calendar-selection-bar.is-hint {
  background: transparent;
  border-color: transparent;
  padding: 0.25rem 0;
  color: var(--clr-text-muted);
  font-size: 0.78rem;
}

/* A day the practice is closed. Struck through rather than merely tinted: the
   grid is read at a glance and a colour alone is a thing you have to learn. */
.cal-cell.holiday {
  /* The hatch is drawn in a border token, not a fixed grey, so it stays a
     faint texture in both themes instead of a white stripe on a black page. */
  background: repeating-linear-gradient(
    45deg,
    var(--clr-border-light),
    var(--clr-border-light) 6px,
    transparent 6px,
    transparent 12px
  );
  color: var(--clr-text-muted);
}
.cal-cell.holiday .cal-date { text-decoration: line-through; }
</style>

<script>
/**
 * Ctrl/Cmd- and Shift-click multi-selection on the month calendars.
 *
 * The cells already had a plain click behaviour (open the day, or book it), so
 * a modifier-click has to be caught before that runs. This listens on the grid
 * in the CAPTURE phase and stops the event there: the cell's own inline
 * onclick never fires, and a modified click means "select", never "open".
 *
 * Ctrl/Cmd toggles one day. Shift extends from the last day touched to the one
 * clicked. A plain click clears the selection, which is the only way out that
 * does not need a second thing to learn.
 */
var calendarSelection = (function () {
  var picked = [];    // "YYYY-MM-DD", in calendar order
  var anchor = null;  // where a Shift range measures from

  function cells() {
    return Array.prototype.slice.call(
      document.querySelectorAll('.calendar-grid .cal-cell[data-date]')
    );
  }

  function render() {
    cells().forEach(function (cell) {
      cell.classList.toggle('multi-selected', picked.indexOf(cell.dataset.date) !== -1);
      // Screen readers get the same fact the outline gives everyone else.
      if (picked.indexOf(cell.dataset.date) !== -1) {
        cell.setAttribute('aria-selected', 'true');
      } else {
        cell.removeAttribute('aria-selected');
      }
    });
    renderBars();
  }

  /**
   * One bar per calendar on the page, created the first time it is needed.
   *
   * It appears for a single picked day as well as for a run, because marking
   * one day a holiday is at least as common as marking a week of them.
   */
  function renderBars() {
    document.querySelectorAll('.calendar-grid').forEach(function (grid) {
      var bar = grid.parentNode.querySelector('.calendar-selection-bar');
      if (!bar) {
        bar = document.createElement('div');
        bar.className = 'calendar-selection-bar';
        bar.hidden = true;
        bar.innerHTML = '<span class="calendar-selection-count"></span>'
          + '<span class="calendar-selection-actions">'
          + '<button type="button" class="btn btn-ghost btn-sm" data-cal-clear>Clear</button>'
          + '<button type="button" class="btn btn-ghost btn-sm" data-cal-holiday></button>'
          + '<button type="button" class="btn btn-primary btn-sm" data-cal-book>Book these dates</button>'
          + '</span>';
        bar.querySelector('[data-cal-clear]').addEventListener('click', function () { clear(); });
        bar.querySelector('[data-cal-book]').addEventListener('click', function () {
          if (typeof openNewSessionModal === 'function') openNewSessionModal(picked[0]);
        });
        bar.querySelector('[data-cal-holiday]').addEventListener('click', function () {
          setHoliday(!allPickedAreHolidays());
        });
        grid.parentNode.insertBefore(bar, grid.nextSibling);
      }

      var closing = !allPickedAreHolidays();
      var actions = bar.querySelector('.calendar-selection-actions');

      bar.hidden   = false;
      bar.classList.toggle('is-hint', picked.length < 1);
      actions.hidden = picked.length < 1;

      bar.querySelector('.calendar-selection-count').textContent = picked.length
        ? picked.length + (picked.length === 1 ? ' date: ' : ' dates: ') + calendarDateSummary(picked)
        : 'Ctrl-click days to pick them, Shift-click for a range.';

      // Labelled every pass, never behind an early return: a button with no
      // text on it is a bug the user sees before anyone else does.
      bar.querySelector('[data-cal-holiday]').textContent = closing ? 'Mark as holiday' : 'Remove holiday';
      // Booking a day that is already closed is refused server-side; the button
      // says so up front instead of letting the modal be filled in for nothing.
      bar.querySelector('[data-cal-book]').disabled = !closing;
    });
  }

  /** True when every picked day is already closed, i.e. the button reopens. */
  function allPickedAreHolidays() {
    if (!picked.length) return false;
    return picked.every(function (date) {
      var cell = document.querySelector('.cal-cell[data-date="' + date + '"]');
      return !!(cell && cell.dataset.holiday === '1');
    });
  }

  /**
   * Close or reopen the picked days.
   *
   * Sessions already booked on a day being closed are left alone -- the server
   * reports how many there are and the toast passes that on, because a click
   * on a calendar must not quietly cancel somebody's appointment.
   */
  function setHoliday(on) {
    if (!picked.length) return;

    // No prompt and no confirm: the button says what it does, does it, and
    // says what happened. The same button undoes it on the next click, so
    // there is nothing here worth stopping the therapist to ask about.
    var fd = new FormData();
    fd.append('action', 'set_holiday');
    fd.append('session_dates', picked.join(','));
    fd.append('on', on ? '1' : '0');

    fetch('api/sessions.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw new Error(data.error || 'Those days could not be changed.');

        var msg = data.dates.length + (data.dates.length === 1 ? ' day ' : ' days ')
                + (on ? 'marked a holiday' : 'reopened');
        if (on && data.sessions) {
          msg += ' — ' + data.sessions + ' session(s) already booked on '
               + (data.sessions === 1 ? 'that day' : 'those days') + ' were left alone';
        }
        showToast(msg);
        setTimeout(function () { location.reload(); }, 800);
      })
      .catch(function (err) { showToast(err.message || 'Network error', 'error'); });
  }

  function order(dates) {
    var all = cells().map(function (c) { return c.dataset.date; });
    return all.filter(function (d) { return dates.indexOf(d) !== -1; });
  }

  function toggle(date) {
    var at = picked.indexOf(date);
    if (at === -1) { picked.push(date); } else { picked.splice(at, 1); }
    picked = order(picked);
    anchor = date;
    render();
  }

  /**
   * Shift-click: every day from the anchor to the day clicked, inclusive.
   *
   * The anchor is the last day clicked plainly or ctrl-clicked. Clicking a
   * date and then shift-clicking a later one is the whole gesture, so a plain
   * click has to leave an anchor behind or a range has nothing to measure
   * from. It also stays put across repeated shift-clicks, so moving the far
   * end redraws the run instead of piling ranges on top of each other.
   *
   * `add` keeps whatever was already picked (ctrl+shift); a plain shift-click
   * replaces the selection with the run.
   */
  function extendTo(date, add) {
    var all = cells().map(function (c) { return c.dataset.date; });
    if (anchor === null || all.indexOf(anchor) === -1) anchor = date;

    var from = all.indexOf(anchor);
    var to   = all.indexOf(date);
    if (from === -1 || to === -1) return;
    if (from > to) { var swap = from; from = to; to = swap; }

    var range = all.slice(from, to + 1);
    picked = add
      ? order(picked.concat(range.filter(function (d) { return picked.indexOf(d) === -1; })))
      : range;
    render();
  }

  function clear() {
    picked = [];
    render();
    if (typeof applyDateSelection === 'function') applyDateSelection([]);
  }

  /**
   * Start again on a different month.
   *
   * clear() deliberately keeps the anchor, because the click that clears is
   * the same click that sets it. Changing month is the one case where the
   * anchor has to go too: it points at a day that is no longer on screen, and
   * a shift-click would measure a range from somewhere nobody can see.
   */
  function reset() {
    picked = [];
    anchor = null;
    render();
    var opened = document.querySelector('.cal-cell.selected[data-date], .cal-cell.today[data-date]');
    if (opened) anchor = opened.dataset.date;
    if (typeof applyDateSelection === 'function') applyDateSelection([]);
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.calendar-grid').forEach(function (grid) {
      grid.addEventListener('click', function (e) {
        var cell = e.target.closest ? e.target.closest('.cal-cell[data-date]') : null;
        if (!cell || !grid.contains(cell)) return;

        // The cells are focusable, so a mouse click leaves the clicked day
        // holding focus and wearing a ring that reads as "still selected" --
        // which is exactly the complaint. Styling :focus was not enough,
        // because the ring is drawn by whichever rule wins the cascade on the
        // day; not holding the focus at all is the fix that does not depend on
        // that. e.detail is 0 for a keyboard-triggered click, so keyboard
        // focus -- the only kind the ring is actually for -- is left alone.
        if (e.detail > 0 && typeof cell.blur === 'function') cell.blur();

        if (e.shiftKey) {
          // Stop here, in capture, or the cell's own onclick opens a day
          // modal on top of the selection the therapist is building.
          e.preventDefault();
          e.stopPropagation();
          // A shift-drag across days selects text otherwise.
          if (window.getSelection) window.getSelection().removeAllRanges();
          extendTo(cell.dataset.date, e.ctrlKey || e.metaKey);
          return;
        }

        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          e.stopPropagation();
          toggle(cell.dataset.date);
          return;
        }

        // A plain click keeps its old behaviour and drops any selection, but
        // it still leaves an anchor behind: "click a date, shift-click a later
        // one" is how a range gets picked, and the first click is half of it.
        if (picked.length) clear();
        anchor = cell.dataset.date;
      }, true);

      // Keyboard parity. The cells are focusable buttons on the sessions page,
      // and a selection you can only make with a mouse is not one everybody
      // can make.
      grid.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var cell = e.target.closest ? e.target.closest('.cal-cell[data-date]') : null;
        if (!cell || !grid.contains(cell)) return;

        if (e.shiftKey) {
          e.preventDefault();
          e.stopPropagation();
          extendTo(cell.dataset.date, e.ctrlKey || e.metaKey);
        } else if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
          e.stopPropagation();
          toggle(cell.dataset.date);
        }
      }, true);
    });

    // The day the page opens on anchors the first range, so a shift-click
    // works before anything has been clicked at all.
    var opened = document.querySelector('.cal-cell.selected[data-date], .cal-cell.today[data-date]');
    if (opened) anchor = opened.dataset.date;

    renderBars();
  });

  return {
    dates: function () { return picked.slice(); },
    clear: clear,
    reset: reset
  };
})();

/**
 * Previous / next month, without reloading the page.
 *
 * The month grid is built in PHP -- which weekday the first falls on, which
 * days are holidays, which days hold sessions -- so the honest way to get the
 * next month is to ask the server for it, not to rebuild that logic in
 * JavaScript and have two versions of the same calendar to keep in step. The
 * page is fetched and the parts that changed are lifted out of it.
 *
 * The links stay real links. Middle-click and ctrl-click still open a month in
 * a new tab, the URL still names the month it is showing, and with JavaScript
 * off the whole thing degrades to the page load it used to be.
 */
var calendarNav = (function () {
  var busy = false;

  function readData(root) {
    var node = (root || document).getElementById('cal-data');
    if (!node) { return null; }
    try { return JSON.parse(node.textContent); } catch (e) { return null; }
  }

  function apply(doc) {
    var incoming = doc.querySelector('.calendar-grid');
    var grid     = document.querySelector('.calendar-grid');
    if (!incoming || !grid) { return false; }

    // innerHTML, not the element: the click and keydown listeners that drive
    // multi-selection are bound to the grid itself, and swapping the node out
    // would take them with it.
    grid.innerHTML = incoming.innerHTML;

    var title = document.querySelector('.calendar-title');
    var freshTitle = doc.querySelector('.calendar-title');
    if (title && freshTitle) { title.textContent = freshTitle.textContent; }

    var links = document.querySelectorAll('[data-cal-nav]');
    var freshLinks = doc.querySelectorAll('[data-cal-nav]');
    for (var i = 0; i < links.length && i < freshLinks.length; i++) {
      links[i].setAttribute('href', freshLinks[i].getAttribute('href'));
    }

    var here  = document.getElementById('cal-data');
    var fresh = doc.getElementById('cal-data');
    if (here && fresh) { here.textContent = fresh.textContent; }

    var data = readData(document) || {};
    if (data.month) { window.CAL_MONTH = data.month; }
    calendarSelection.reset();
    document.dispatchEvent(new CustomEvent('calendar:monthchanged', { detail: data }));
    return true;
  }

  function go(url, push) {
    if (busy) { return; }
    busy = true;
    var grid = document.querySelector('.calendar-grid');
    if (grid) { grid.classList.add('is-loading'); }

    fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) { throw new Error('HTTP ' + res.status); }
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (!apply(doc)) { throw new Error('no calendar in the response'); }
        if (push) { history.pushState({ calendar: true }, '', url); }
        busy = false;
        if (grid) { grid.classList.remove('is-loading'); }
      })
      .catch(function () {
        // A month that will not load is not a month worth trapping anyone on:
        // fall back to the plain navigation the link would have done anyway.
        window.location.href = url;
      });
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest ? e.target.closest('[data-cal-nav]') : null;
    // Anything that means "open this somewhere else" is left alone.
    if (!link || e.defaultPrevented) { return; }
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    e.preventDefault();
    go(link.getAttribute('href'), true);
  });

  // Back and forward have to move the calendar, or the URL and the month on
  // screen stop agreeing with each other.
  window.addEventListener('popstate', function () {
    if (document.querySelector('.calendar-grid')) { go(window.location.href, false); }
  });

  return { data: function () { return readData(document); } };
})();
</script>

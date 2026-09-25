<?php
/**
 * Questions added in the admin, rendered onto the form that is served.
 *
 * patient-intake-form.html is a static template and stays that way: intake.php
 * serves it with in-memory rewrites and never edits the file. A question added
 * in the form module is therefore validated and stored -- both read the schema
 * -- but would not be asked, because there is no input for it in the markup.
 * A builder whose "add" produces a question nobody is ever asked is a builder
 * that lies, so the missing rows are rendered here at serve time.
 *
 * Only yes/no questions are handled: that is the one shape addFormQuestion()
 * creates. Anything else added by hand is left to the template, where a
 * designed input already exists for it.
 */

require_once __DIR__ . '/intake-schema.php';

/**
 * The schema fields that have no input in the markup, grouped by section.
 * An empty result is the normal case -- nothing has been added.
 */
function intakeMissingQuestions($html, $version) {
    $missing = [];
    foreach (intakeSchema($version) as $section) {
        foreach ($section['fields'] as $field) {
            if ($field['type'] !== 'yesno') {
                continue;
            }
            if (strpos($html, 'name="' . $field['id'] . '"') !== false) {
                continue;
            }
            $missing[$section['title']][] = $field;
        }
    }
    return $missing;
}

/**
 * One question row, in the markup the template already uses for these.
 *
 * Copied deliberately rather than restyled: a question added last week must
 * not be recognisable as the odd one out on the page.
 */
function intakeQuestionRowHtml(array $field, $number) {
    $id    = htmlspecialchars($field['id'], ENT_QUOTES);
    $label = htmlspecialchars($field['label'], ENT_QUOTES);
    $req   = !empty($field['required']) ? ' required' : '';
    $star  = !empty($field['required']) ? ' *' : '';

    return '
          <div class="ra-question-row py-3 border-bottom" style="border-bottom-color: rgba(0, 55, 62, 0.08) !important;">
            <div class="row align-items-center">
              <div class="col-12 col-md-9 mb-2 mb-md-0">
                <span class="ra-question-text" style="font-family: var(--font-body); font-size: 0.95rem; color: var(--ra-brown); font-weight: 500;">
                  ' . $number . '. ' . $label . $star . '
                </span>
              </div>
              <div class="col-12 col-md-3 d-flex justify-content-md-end gap-4">
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input custom-form-radio" type="radio" name="' . $id . '" id="' . $id . '_yes" value="yes"' . $req . ' />
                  <label class="form-check-label" for="' . $id . '_yes" style="font-size: 0.9rem; color: var(--ra-brown); cursor: pointer;">Yes</label>
                </div>
                <div class="form-check form-check-inline mb-0">
                  <input class="form-check-input custom-form-radio" type="radio" name="' . $id . '" id="' . $id . '_no" value="no"' . $req . ' />
                  <label class="form-check-label" for="' . $id . '_no" style="font-size: 0.9rem; color: var(--ra-brown); cursor: pointer;">No</label>
                </div>
              </div>
            </div>
          </div>
';
}

/**
 * Put every added question onto the served markup, at the end of its section.
 *
 * The anchor is the last existing row of that section, found by its input
 * name. If a section has no rows to anchor to, its questions are skipped
 * rather than dropped somewhere arbitrary -- a question in the wrong step is
 * worse than one the therapist can see is still missing.
 */
function intakeRenderExtraQuestions($html, $version) {
    $missing = intakeMissingQuestions($html, $version);
    if (!$missing) {
        return $html;
    }

    foreach (intakeSchema($version) as $section) {
        $title = $section['title'];
        if (empty($missing[$title])) {
            continue;
        }

        // The last field of this section that the template does render.
        $anchorId = null;
        $rendered = 0;
        foreach ($section['fields'] as $field) {
            if (strpos($html, 'name="' . $field['id'] . '"') !== false) {
                $anchorId = $field['id'];
                $rendered++;
            }
        }
        if ($anchorId === null) {
            continue;
        }

        $rowEnd = intakeRowEndOffset($html, $anchorId);
        if ($rowEnd === null) {
            continue;
        }

        $addition = '';
        $number   = $rendered;
        foreach ($missing[$title] as $field) {
            $addition .= intakeQuestionRowHtml($field, ++$number);
        }
        $html = substr($html, 0, $rowEnd) . $addition . substr($html, $rowEnd);
    }

    return $html;
}

/**
 * Where the question row holding this input ends.
 *
 * Walks out from the input to its wrapping .ra-question-row and counts div
 * nesting to its close, so a new row lands between two rows rather than inside
 * one. Returns null when the markup is not the shape expected, which leaves
 * the template untouched.
 */
function intakeRowEndOffset($html, $fieldId) {
    $at = strpos($html, 'name="' . $fieldId . '"');
    if ($at === false) {
        return null;
    }

    $rowStart = strrpos(substr($html, 0, $at), '<div class="ra-question-row');
    if ($rowStart === false) {
        return null;
    }

    $depth = 0;
    $i     = $rowStart;
    $len   = strlen($html);
    while ($i < $len) {
        $open  = strpos($html, '<div', $i);
        $close = strpos($html, '</div>', $i);
        if ($close === false) {
            return null;
        }
        if ($open !== false && $open < $close) {
            $depth++;
            $i = $open + 4;
            continue;
        }
        $depth--;
        $i = $close + 6;
        if ($depth === 0) {
            return $i;
        }
    }
    return null;
}

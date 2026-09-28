<?php
/**
 * Page-based pagination for admin list pages.
 *
 * Slices an already-fetched array rather than pushing LIMIT/OFFSET through
 * four different repo query builders (lead-repo, client-repo, the inline SQL
 * in patient-intake.php and blogs.php) -- this is a one-practice admin, not a
 * dataset where fetching every filtered row first is expensive.
 *
 * The GET param is `p`, never `page`: admin/index.php already owns `page` for
 * routing between sections.
 */

/** The row-count choices the dropdown in paginationHtml() offers. */
function paginationSizeOptions() {
    return [10, 20, 50];
}

/**
 * $defaultPerPage is only the fallback: a page size picked from the dropdown
 * arrives as `pp` and overrides it, same as `p` already overrides the
 * current page. Anything not in paginationSizeOptions() is ignored rather
 * than trusted -- a stray `pp` value should not be able to force an
 * unbounded row count through a URL.
 */
function paginate(array $rows, $defaultPerPage = 20) {
    $perPage = isset($_GET['pp']) ? (int) $_GET['pp'] : $defaultPerPage;
    if (!in_array($perPage, paginationSizeOptions(), true)) {
        $perPage = $defaultPerPage;
    }

    $total   = count($rows);
    $pages   = max(1, (int) ceil($total / $perPage));
    $current = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
    $current = min($current, $pages);
    $offset  = ($current - 1) * $perPage;

    return [
        'rows'    => array_slice($rows, $offset, $perPage),
        'page'    => $current,
        'pages'   => $pages,
        'total'   => $total,
        'perPage' => $perPage,
    ];
}

/**
 * Prev/next plus a windowed set of page numbers (current \xb1 2, always the
 * first and last). $urlFn(array $overrides) builds one page's URL the same
 * way the calling page already builds its own filter/sort links, so `p` is
 * the only thing that changes and every other filter survives the click.
 */
function paginationHtml(array $pager, callable $urlFn) {
    if ($pager['pages'] <= 1) {
        return '';
    }

    $page  = $pager['page'];
    $pages = $pager['pages'];
    $from  = ($page - 1) * $pager['perPage'] + 1;
    $to    = min($page * $pager['perPage'], $pager['total']);

    // pp travels on every page-number/prev/next link, not just the dropdown --
    // otherwise a page the caller's own $filters does not already echo back
    // (every list page but the one it was picked on) would silently reset to
    // the default size on the very next click.
    $link = function ($target, $label, $disabled) use ($urlFn, $pager) {
        if ($disabled) {
            return '<span class="btn btn-ghost btn-sm pagination-edge is-disabled" aria-disabled="true">' . $label . '</span>';
        }
        return '<a class="btn btn-ghost btn-sm pagination-edge" href="' . htmlspecialchars($urlFn(['p' => $target, 'pp' => $pager['perPage']])) . '">' . $label . '</a>';
    };

    $out  = '<nav class="pagination" aria-label="Pagination">';
    $out .= '<span class="pagination-summary">' . $from . '-' . $to . ' of ' . $pager['total'];
    $out .= '<label class="pagination-size"><span>Show</span><select onchange="location.href=this.value">';
    foreach (paginationSizeOptions() as $opt) {
        $out .= '<option value="' . htmlspecialchars($urlFn(['pp' => $opt, 'p' => 1])) . '"'
              . ($opt === $pager['perPage'] ? ' selected' : '') . '>' . $opt . '</option>';
    }
    $out .= '</select></label></span>';
    $out .= '<div class="pagination-links">';
    $out .= $link(max(1, $page - 1), '<i class="bi bi-chevron-left"></i> Prev', $page <= 1);

    $window = 2;
    for ($i = 1; $i <= $pages; $i++) {
        if ($i === 1 || $i === $pages || abs($i - $page) <= $window) {
            $out .= '<a class="pagination-num' . ($i === $page ? ' active' : '') . '" href="'
                  . htmlspecialchars($urlFn(['p' => $i, 'pp' => $pager['perPage']])) . '"' . ($i === $page ? ' aria-current="page"' : '') . '>' . $i . '</a>';
        } elseif (abs($i - $page) === $window + 1) {
            $out .= '<span class="pagination-ellipsis">...</span>';
        }
    }

    $out .= $link(min($pages, $page + 1), 'Next <i class="bi bi-chevron-right"></i>', $page >= $pages);
    $out .= '</div></nav>';
    return $out;
}

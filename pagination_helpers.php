<?php
/**
 * pagination_helpers.php — shared pagination URL builder.
 *
 * pic_view_marks.php's page_url() and silibus.php's silibus_page_url() were
 * byte-identical, hand-duplicated per file. Both are kept as thin wrappers
 * around this one function (rather than renaming every call site) so this
 * split is zero-risk: same function names, same behavior, one definition.
 */
function pm_page_url(int $p, array $extra): string {
    $params = array_merge($extra, ['page' => $p]);
    return '?' . http_build_query($params);
}

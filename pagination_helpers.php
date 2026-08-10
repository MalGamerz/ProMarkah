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

/**
 * pm_render_pagination() — shared server-rendered pagination bar.
 *
 * Renders the same .vm-pagination markup that pic_view_marks.php and
 * silibus.php used to hand-duplicate (they were byte-identical apart from
 * the item label and URL-builder function). Only called for pages whose
 * page links are plain server-side URLs; client-side-paginated pages use
 * the pmRenderPagination() JS helper in layout.js instead.
 *
 * @param int      $page        Current page (1-based).
 * @param int      $total_pages Total number of pages.
 * @param int      $total_items Total item count, for the "Displaying X-Y of Z" label.
 * @param int      $per_page    Items per page.
 * @param string   $item_label  Localized noun for the count label (e.g. "pesilat", "rekod").
 * @param callable $url_fn      function(int $page): string — builds the href for a page.
 */
function pm_render_pagination(int $page, int $total_pages, int $total_items, int $per_page, string $item_label, callable $url_fn): void {
    if ($total_pages <= 1) return;
    $sp = max(1, $page - 2);
    $ep = min($total_pages, $sp + 4);
    if ($ep - $sp < 4) $sp = max(1, $ep - 4);
    $start_num = $total_items === 0 ? 0 : (($page - 1) * $per_page) + 1;
    $end_num   = min($page * $per_page, $total_items);
    ?>
    <div class="vm-pagination">
        <span class="vm-page-info">
            Memaparkan <b><?= $start_num ?>–<?= $end_num ?></b> daripada <b><?= $total_items ?></b> <?= htmlspecialchars($item_label) ?>
        </span>
        <div class="vm-page-btns">
            <?php if ($page > 1): ?>
            <a href="<?= $url_fn($page - 1) ?>" class="vm-page-btn">&laquo;</a>
            <?php else: ?>
            <span class="vm-page-btn vm-page-disabled">&laquo;</span>
            <?php endif; ?>

            <?php if ($sp > 1): ?>
            <a href="<?= $url_fn(1) ?>" class="vm-page-btn">1</a>
            <?php if ($sp > 2): ?><span class="vm-page-ellipsis">&hellip;</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $sp; $i <= $ep; $i++): ?>
            <a href="<?= $url_fn($i) ?>"
               class="vm-page-btn <?= $i === $page ? 'vm-page-active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <?php if ($ep < $total_pages): ?>
            <?php if ($ep < $total_pages - 1): ?><span class="vm-page-ellipsis">&hellip;</span><?php endif; ?>
            <a href="<?= $url_fn($total_pages) ?>" class="vm-page-btn"><?= $total_pages ?></a>
            <?php endif; ?>

            <?php if ($page < $total_pages): ?>
            <a href="<?= $url_fn($page + 1) ?>" class="vm-page-btn">&raquo;</a>
            <?php else: ?>
            <span class="vm-page-btn vm-page-disabled">&raquo;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

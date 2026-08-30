<?php
/**
 * Shared pagination helper.
 *
 * The long lists (booking management, activity log, customer history) were
 * rendering every row at once -- 184 booking cards on one page in the supplier
 * panel. These pages already fetch the full result set because they compute
 * totals from it, so this paginates the *display* with array_slice rather than
 * changing the queries and breaking those totals.
 *
 * Usage:
 *     require_once __DIR__ . '/../_pagination.php';
 *     $pg = tv_paginate($bookings, 12);
 *     foreach ($pg['items'] as $booking) { ... }
 *     tv_render_pagination($pg);
 */

if (!function_exists('tv_paginate')) {

    /**
     * Slice $items down to the current page. Clamps out-of-range page numbers
     * so a stale ?page=99 link still renders something sensible.
     */
    function tv_paginate(array $items, int $perPage = 12, string $param = 'page'): array
    {
        $perPage = max(1, $perPage);
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $perPage));

        $page = (int) ($_GET[$param] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;

        return [
            'items'      => array_slice($items, $offset, $perPage),
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'perPage'    => $perPage,
            'from'       => $total ? $offset + 1 : 0,
            'to'         => min($offset + $perPage, $total),
            'param'      => $param,
        ];
    }

    /**
     * Build a link to $page while keeping every other query parameter, so
     * paging does not silently drop an active filter, search term or ?lang.
     */
    function tv_pagination_url(int $page, string $param = 'page'): string
    {
        $query = $_GET;
        $query[$param] = $page;

        return '?' . http_build_query($query);
    }

    /**
     * Translate through the app's helper when it is loaded, otherwise fall
     * back to the Indonesian source string.
     */
    function tv_pg_t(string $text): string
    {
        return function_exists('te') ? te($text) : htmlspecialchars($text);
    }

    /**
     * Page numbers to show: always first and last, plus a window around the
     * current page, with null marking a gap that renders as an ellipsis.
     */
    function tv_pagination_window(int $page, int $totalPages, int $radius = 1): array
    {
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $pages = [1];
        $start = max(2, $page - $radius);
        $end   = min($totalPages - 1, $page + $radius);

        if ($start > 2) {
            $pages[] = null;
        }
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }
        if ($end < $totalPages - 1) {
            $pages[] = null;
        }
        $pages[] = $totalPages;

        return $pages;
    }

    function tv_render_pagination(array $pg): void
    {
        // Styles live here so the three panels (admin, supplier, customer)
        // pick up an identical control without editing three stylesheets.
        static $stylesPrinted = false;
        if (!$stylesPrinted) {
            $stylesPrinted = true;
            ?>
            <style>
                .tv-pagination {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                    margin: 24px 0 8px;
                    font-family: inherit;
                }

                .tv-pagination-info {
                    font-size: 0.85rem;
                    color: #64748b;
                }

                .tv-pagination-pages {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 6px;
                    margin-left: auto;
                }

                .tv-page-link {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 38px;
                    height: 38px;
                    padding: 0 10px;
                    border-radius: 10px;
                    border: 1px solid #e2e8f0;
                    background: #fff;
                    color: #334155;
                    font-size: 0.88rem;
                    font-weight: 600;
                    text-decoration: none;
                    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
                }

                .tv-page-link:hover {
                    background: #fff3e6;
                    border-color: #FEA116;
                    color: #b07d10;
                }

                .tv-page-link.is-current {
                    background: #FEA116;
                    border-color: #FEA116;
                    color: #fff;
                    cursor: default;
                }

                .tv-page-link.is-disabled {
                    opacity: 0.45;
                    pointer-events: none;
                }

                .tv-page-gap {
                    padding: 0 4px;
                    color: #94a3b8;
                }

                @media (max-width: 600px) {
                    .tv-pagination {
                        justify-content: center;
                    }

                    .tv-pagination-pages {
                        margin-left: 0;
                        justify-content: center;
                    }
                }
            </style>
            <?php
        }

        // A single page needs no control at all.
        if ($pg['totalPages'] <= 1) {
            return;
        }

        $param = $pg['param'];
        $page  = $pg['page'];
        $last  = $pg['totalPages'];
        ?>
        <nav class="tv-pagination" aria-label="<?= tv_pg_t('Navigasi halaman') ?>">
            <div class="tv-pagination-info">
                <?= tv_pg_t('Menampilkan') ?> <?= (int) $pg['from'] ?>&ndash;<?= (int) $pg['to'] ?>
                <?= tv_pg_t('dari') ?> <?= (int) $pg['total'] ?>
            </div>

            <div class="tv-pagination-pages">
                <a class="tv-page-link<?= $page <= 1 ? ' is-disabled' : '' ?>"
                   href="<?= htmlspecialchars(tv_pagination_url($page - 1, $param)) ?>"
                   aria-label="<?= tv_pg_t('Sebelumnya') ?>">&laquo;</a>

                <?php foreach (tv_pagination_window($page, $last) as $p): ?>
                    <?php if ($p === null): ?>
                        <span class="tv-page-gap">&hellip;</span>
                    <?php elseif ($p === $page): ?>
                        <span class="tv-page-link is-current" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="tv-page-link"
                           href="<?= htmlspecialchars(tv_pagination_url($p, $param)) ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <a class="tv-page-link<?= $page >= $last ? ' is-disabled' : '' ?>"
                   href="<?= htmlspecialchars(tv_pagination_url($page + 1, $param)) ?>"
                   aria-label="<?= tv_pg_t('Berikutnya') ?>">&raquo;</a>
            </div>
        </nav>
        <?php
    }
}

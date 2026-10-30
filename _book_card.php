<?php
/**
 * REUSABLE BOOK CARD COMPONENT
 */
if (!defined('SITE_URL')) exit;

$discount_percent = 0;
if (!empty($b['old_price']) && $b['old_price'] > $b['price']) {
    $discount_percent = round((($b['old_price'] - $b['price']) / $b['old_price']) * 100);
}
$img_src = cover_url($b['cover_image'] ?? '');
?>
<div class="book-card">
    <div class="book-cover-wrapper">
        <a href="<?= e(book_path($b)) ?>">
            <?= cover_picture($b['cover_image'] ?? '', cover_alt($b), 'book-cover-img', 400, 540, empty($eager_cover), 'onerror="this.src=\'/assets/images/book_placeholder.svg\'"') ?>
        </a>
        <?php if ($discount_percent > 0): ?>
            <span class="discount-badge">-%<?= $discount_percent ?> İndirim</span>
        <?php endif; ?>
    </div>

    <div class="book-card-body">
        <?php if (!empty($b['store_name'])): ?>
            <div class="mb-1">
                <a href="magaza.php?s=<?= urlencode($b['store_slug'] ?? '') ?>" class="badge bg-danger bg-opacity-10 text-danger text-decoration-none fs-8">
                    <i class="bi bi-shop me-1"></i><?= e($b['store_name']) ?>
                </a>
            </div>
        <?php endif; ?>
        <div class="book-author text-truncate"><?= e($b['author_name'] ?? 'Bilinmeyen Yazar') ?></div>
        <h6 class="book-title">
            <a href="<?= e(book_path($b)) ?>" class="text-dark text-decoration-none" title="<?= e($b['title']) ?>">
                <?= e($b['title']) ?>
            </a>
        </h6>

        <?php $badges = campaign_badges_html($b, 'fs-8'); if ($badges !== ''): ?>
            <div class="mb-1"><?= $badges ?></div>
        <?php endif; ?>
        <div class="book-price-box mb-3">
            <span class="current-price"><?= format_price($b['price']) ?></span>
            <?php if (!empty($b['old_price'])): ?>
                <span class="old-price"><?= format_price($b['old_price']) ?></span>
            <?php endif; ?>
        </div>

        <?php if ((int)$b['stock'] > 0): ?>
            <button class="btn btn-add-cart mt-auto"
                    data-id="<?= $b['id'] ?>"
                    data-title="<?= e($b['title']) ?>"
                    data-price="<?= $b['price'] ?>"
                    data-image="<?= e($img_src) ?>">
                <i class="bi bi-bag-plus-fill me-1"></i> Sepete Ekle
            </button>
        <?php else: ?>
            <button class="btn btn-secondary mt-auto" disabled>Tükendi</button>
        <?php endif; ?>
    </div>
</div>

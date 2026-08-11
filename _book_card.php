<?php
/**
 * REUSABLE BOOK CARD COMPONENT
 */
if (!defined('SITE_URL')) exit;

$discount_percent = 0;
if (!empty($b['old_price']) && $b['old_price'] > $b['price']) {
    $discount_percent = round((($b['old_price'] - $b['price']) / $b['old_price']) * 100);
}
$img_src = !empty($b['cover_image']) ? 'assets/images/' . $b['cover_image'] : 'assets/images/book_placeholder.svg';
?>
<div class="book-card">
    <div class="book-cover-wrapper">
        <a href="detail.php?id=<?= $b['id'] ?>">
            <img src="<?= e($img_src) ?>" alt="<?= e($b['title']) ?>" class="book-cover-img" onerror="this.src='assets/images/book_placeholder.svg'">
        </a>
        <?php if ($discount_percent > 0): ?>
            <span class="discount-badge">-%<?= $discount_percent ?> İndirim</span>
        <?php endif; ?>
    </div>

    <div class="book-card-body">
        <div class="book-author text-truncate"><?= e($b['author_name'] ?? 'Bilinmeyen Yazar') ?></div>
        <h6 class="book-title">
            <a href="detail.php?id=<?= $b['id'] ?>" class="text-dark text-decoration-none" title="<?= e($b['title']) ?>">
                <?= e($b['title']) ?>
            </a>
        </h6>

        <div class="book-price-box mb-3">
            <span class="current-price"><?= format_price($b['price']) ?></span>
            <?php if (!empty($b['old_price'])): ?>
                <span class="old-price"><?= format_price($b['old_price']) ?></span>
            <?php endif; ?>
        </div>

        <button class="btn btn-add-cart mt-auto" 
                data-id="<?= $b['id'] ?>" 
                data-title="<?= e($b['title']) ?>" 
                data-price="<?= $b['price'] ?>" 
                data-image="<?= e($img_src) ?>">
            <i class="bi bi-bag-plus-fill me-1"></i> Sepete Ekle
        </button>
    </div>
</div>

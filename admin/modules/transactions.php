<?php
/**
 * ADMIN MODULE: Transactions (p=Transactions)
 */
if (!defined('SITE_URL')) exit;

$transactions = db_fetch_all("SELECT t.*, o.order_number, u.name as customer_name 
                            FROM transactions t 
                            LEFT JOIN orders o ON t.order_id = o.id 
                            LEFT JOIN users u ON o.user_id = u.id 
                            ORDER BY t.created_at DESC");
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Ödeme ve Finans Kayıtları (Transactions)</h5>
        <p class="text-muted fs-7 mb-0">Sistem üzerinden gerçekleşen tüm Kredi Kartı ve Havale/EFT işlem detayları.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fs-7">
                    <tr>
                        <th class="ps-4">İşlem ID</th>
                        <th>Sipariş No</th>
                        <th>Müşteri</th>
                        <th>Ödeme Türü</th>
                        <th>Tutar</th>
                        <th>İşlem Yanıtı (POS / Banka)</th>
                        <th>Durum</th>
                        <th class="pe-4 text-end">Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">Ödeme kaydı bulunamadı.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td class="ps-4 text-muted fs-7">#TX-<?= $t['id'] ?></td>
                                <td>
                                    <div class="fw-bold text-dark fs-7"><?= e($t['order_number'] ?? 'Bilinmiyor') ?></div>
                                </td>
                                <td>
                                    <div class="fs-7"><?= e($t['customer_name'] ?? 'Misafir') ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fs-8">
                                        <i class="bi bi-credit-card-fill me-1 text-danger"></i> <?= e($t['transaction_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-extrabold text-danger fs-7"><?= format_price($t['amount']) ?></div>
                                </td>
                                <td>
                                    <small class="text-muted fs-8 font-mono"><?= e($t['gateway_response'] ?? 'Yanıt yok') ?></small>
                                </td>
                                <td>
                                    <?php if ($t['status'] === 'Onaylandı'): ?>
                                        <span class="badge bg-success fs-8">Onaylandı</span>
                                    <?php elseif ($t['status'] === 'Bekliyor'): ?>
                                        <span class="badge bg-warning text-dark fs-8">Havale Bekliyor</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger fs-8"><?= e($t['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-4 text-end text-muted fs-8">
                                    <?= date('d.m.Y H:i', strtotime($t['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

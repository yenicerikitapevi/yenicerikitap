<?php
/**
 * ADMIN MODULE: ActionLogs (p=ActionLogs)
 */
if (!defined('SITE_URL')) exit;

$logs = db_fetch_all("SELECT al.*, u.name as admin_name, u.email as admin_email 
                      FROM action_logs al 
                      LEFT JOIN users u ON al.user_id = u.id 
                      ORDER BY al.id DESC LIMIT 100");
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="fw-bold text-dark mb-1">Yönetici İşlem & Güvenlik Logları (Action Logs)</h5>
        <p class="text-muted fs-7 mb-0">Adminlerin yaptığı tüm kritik işlemler (silme, fiyat güncelleme vb.) IP ve zaman damgasıyla izlenir.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light fs-7">
                    <tr>
                        <th class="ps-4">Log ID</th>
                        <th>İşlemi Yapan Admin</th>
                        <th>Yapılan İşlem</th>
                        <th>Hedef Modül</th>
                        <th>IP Adresi</th>
                        <th>Açıklama & Detaylar</th>
                        <th class="pe-4 text-end">Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">Henüz işlem kaydı bulunmuyor.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="ps-4 text-muted fs-7">#<?= $l['id'] ?></td>
                                <td>
                                    <div class="fw-bold text-dark fs-7"><?= e($l['admin_name'] ?? 'Sistem') ?></div>
                                    <small class="text-muted fs-8"><?= e($l['admin_email'] ?? '') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-danger fs-8"><?= e($l['action']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fs-8"><?= e($l['target_module']) ?> #<?= e($l['target_id'] ?? '-') ?></span>
                                </td>
                                <td>
                                    <small class="font-mono text-secondary"><?= e($l['ip_address']) ?></small>
                                </td>
                                <td>
                                    <div class="fs-7 text-secondary"><?= e($l['details']) ?></div>
                                </td>
                                <td class="pe-4 text-end text-muted fs-8">
                                    <?= date('d.m.Y H:i:s', strtotime($l['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

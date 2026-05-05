<?php
// app/bms/customer/record_payout.php
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/header.php';

// Only leaders can access this
$viongozi_roles = ['Admin', 'Secretary', 'Katibu'];
if (!in_array($user_role, $viongozi_roles)) {
    header("Location: " . getUrl('dashboard') . "?error=Ufikiaji Umekataliwa");
    exit();
}

$message = '';
$error = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payout'])) {
    try {
        $member_id = $_POST['member_id'];
        $amount = $_POST['amount'];
        $description = $_POST['description'];
        $payout_date = $_POST['payout_date'];

        $stmt = $pdo->prepare("INSERT INTO member_payouts (member_id, amount, description, payout_date, status) VALUES (?, ?, ?, ?, 'paid')");
        $stmt->execute([$member_id, $amount, $description, $payout_date]);
        
        $message = "Rekodi ya matumizi/msaada imehifadhiwa!";
    } catch (Exception $e) {
        $error = "Hitilafu: " . $e->getMessage();
    }
}

// Fetch all members for the dropdown
$stmt = $pdo->query("SELECT customer_id, first_name, last_name FROM customers WHERE status = 'active' ORDER BY first_name ASC");
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent payouts
$stmt = $pdo->query("SELECT p.*, c.first_name, c.last_name FROM member_payouts p JOIN customers c ON p.member_id = c.customer_id ORDER BY p.payout_date DESC LIMIT 10");
$recent_payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>


    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-gift-fill me-2 text-danger"></i> Rekodi Matumizi / Msaada kwa Mwanachama</h2>
            <p class="text-muted">Tumia ukurasa huu kurekodi pesa ambazo kikundi kimetoa kumsaidia mwanachama (Matumizi ya Mwanachama).</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold">Rekodi Mpya</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jina la Mwanachama</label>
                            <select name="member_id" class="form-select select2" required>
                                <option value="">-- Chagua Mwanachama --</option>
                                <?php foreach ($members as $m): ?>
                                <option value="<?= $m['customer_id'] ?>"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Kiasi (Amount)</label>
                            <div class="input-group">
                                <span class="input-group-text">TZS</span>
                                <input type="number" name="amount" class="form-control" placeholder="0.00" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Tarehe</label>
                            <input type="date" name="payout_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Maelezo (Sababu ya Msaada)</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Mfano: Msaada wa matibabu, Msiba, nk." required></textarea>
                        </div>

                        <div class="d-grid shadow-sm">
                            <button type="submit" name="save_payout" class="btn btn-danger btn-lg">
                                <i class="bi bi-save me-2"></i> Hifadhi Rekodi
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold">Rekodi 10 za Hivi Karibuni</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Tarehe</th>
                                    <th>Mwanachama</th>
                                    <th>Maelezo</th>
                                    <th class="pe-4 text-end">Kiasi (TZS)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_payouts)): ?>
                                <tr><td colspan="4" class="text-center py-4">Hakuna rekodi bado.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($recent_payouts as $p): ?>
                                <tr>
                                    <td class="ps-4 text-muted"><?= date('d/m/Y', strtotime($p['payout_date'])) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></td>
                                    <td><small><?= htmlspecialchars($p['description']) ?></small></td>
                                    <td class="pe-4 text-end fw-bold text-danger"><?= number_format($p['amount'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>


<?php
require_once ROOT_DIR . '/footer.php';
ob_end_flush();
?>

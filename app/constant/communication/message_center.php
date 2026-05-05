<?php
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('message_center');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$user_id = $_SESSION['user_id'];

// Handle form submissions (must be before header.php for redirects to work)
if ($_POST) {
    if (isset($_POST['send_message'])) {
        try {
            $recipient_ids = $_POST['recipient_ids'] ?? [];
            $subject = trim($_POST['subject']);
            $message = trim($_POST['message']);
            $priority = $_POST['priority'] ?? 'normal';
            $parent_id = $_POST['parent_id'] ?? null;
            
            // Validate required fields
            if (empty($recipient_ids)) {
                throw new Exception("Please select at least one recipient");
            }
            
            if (empty($subject)) {
                throw new Exception("Subject is required");
            }
            
            if (empty($message)) {
                throw new Exception("Message content is required");
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            // Create main message
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, subject, message, priority, parent_id, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$user_id, $subject, $message, $priority, $parent_id]);
            $message_id = $pdo->lastInsertId();
            
            // Create recipient records
            foreach ($recipient_ids as $recipient_id) {
                $stmt = $pdo->prepare("
                    INSERT INTO message_recipients (message_id, recipient_id, created_at) 
                    VALUES (?, ?, NOW())
                ");
                $stmt->execute([$message_id, $recipient_id]);
            }
            
            // If this is a reply, update the parent message
            if ($parent_id) {
                $stmt = $pdo->prepare("UPDATE messages SET has_replies = 1 WHERE message_id = ?");
                $stmt->execute([$parent_id]);
            }
            
            $pdo->commit();
            // Post-Redirect-Get pattern to avoid resubmission on refresh
            header("Location: ?folder=sent&success=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_messages[] = "Error sending message: " . $e->getMessage();
        }
    }
    
    // Delete message
    if (isset($_POST['delete_message'])) {
        try {
            $message_id = $_POST['message_id'];
            
            // For sent messages, mark as deleted for sender
            $stmt = $pdo->prepare("
                UPDATE messages 
                SET deleted_by_sender = 1 
                WHERE message_id = ? AND sender_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            
            // For received messages, mark as deleted for recipient
            $stmt = $pdo->prepare("
                UPDATE message_recipients 
                SET deleted = 1 
                WHERE message_id = ? AND recipient_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            
            $success_messages[] = "Message deleted successfully";
            
        } catch (Exception $e) {
            $error_messages[] = "Error deleting message: " . $e->getMessage();
        }
    }
    
    // Mark as read
    if (isset($_POST['mark_as_read'])) {
        try {
            $message_id = $_POST['message_id'];
            $stmt = $pdo->prepare("
                UPDATE message_recipients 
                SET is_read = 1, read_at = NOW() 
                WHERE message_id = ? AND recipient_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            $success_messages[] = "Message marked as read";
        } catch (Exception $e) {
            $error_messages[] = "Error updating message: " . $e->getMessage();
        }
    }
    
    // Archive message
    if (isset($_POST['archive_message'])) {
        try {
            $message_id = $_POST['message_id'];
            
            // For received messages, mark as archived in recipients table
            $stmt = $pdo->prepare("
                UPDATE message_recipients 
                SET is_archived = 1 
                WHERE message_id = ? AND recipient_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            
            // For sent messages, mark as archived in messages table
            $stmt = $pdo->prepare("
                UPDATE messages 
                SET is_archived_by_sender = 1 
                WHERE message_id = ? AND sender_id = ?
            ");
            $stmt->execute([$message_id, $user_id]);
            
            $success_messages[] = $is_sw ? "Ujumbe umehifadhiwa!" : "Message archived";
        } catch (Exception $e) {
            $error_messages[] = "Error archiving message: " . $e->getMessage();
        }
    }
}

// Handle success/error message display
$success_messages = [];
$error_messages = [];
if (isset($_GET['success'])) {
    $success_messages[] = $is_sw ? "Ujumbe umetumwa kikamilifu!" : "Message sent successfully!";
}

require_once __DIR__ . '/../../../header.php';

// Get current folder/view
$folder = $_GET['folder'] ?? 'inbox';
$message_id = $_GET['message_id'] ?? null;

// Get message statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM message_recipients mr 
         JOIN messages m ON mr.message_id = m.message_id 
         WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0 AND mr.is_read = 0) as unread_count,
         
        (SELECT COUNT(*) FROM message_recipients mr 
         JOIN messages m ON mr.message_id = m.message_id 
         WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0) as inbox_count,
         
        (SELECT COUNT(*) FROM messages 
         WHERE sender_id = ? AND deleted_by_sender = 0 AND is_archived_by_sender = 0) as sent_count,
         
        (SELECT COUNT(*) FROM (
            SELECT m.message_id FROM messages m
            LEFT JOIN message_recipients mr ON m.message_id = mr.message_id AND mr.recipient_id = ?
            WHERE (mr.recipient_id = ? AND mr.is_archived = 1 AND mr.deleted = 0)
               OR (m.sender_id = ? AND m.is_archived_by_sender = 1 AND m.deleted_by_sender = 0)
            GROUP BY m.message_id
        ) as arch) as archived_count
");
$stats_stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
$message_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get all users for recipient selection
$role_col = $is_sw ? "COALESCE(r.role_name_sw, r.role_name)" : "r.role_name";
$users_stmt = $pdo->prepare("
    SELECT u.user_id, u.first_name, u.last_name, u.email, $role_col as role_name
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.is_active = 1 AND u.user_id != ?
    ORDER BY u.first_name, u.last_name
");
$users_stmt->execute([$user_id]);
$all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load messages based on current folder
$messages = [];
switch ($folder) {
    case 'inbox':
        $role_col = $is_sw ? "COALESCE(r.role_name_sw, r.role_name)" : "r.role_name";
        $messages_stmt = $pdo->prepare("
            SELECT m.*, mr.is_read, mr.read_at, mr.is_archived,
                   CONCAT(s.first_name, ' ', s.last_name) as sender_name,
                   s.email as sender_email,
                   $role_col as sender_role
            FROM message_recipients mr
            JOIN messages m ON mr.message_id = m.message_id
            LEFT JOIN users s ON m.sender_id = s.user_id
            LEFT JOIN roles r ON s.role_id = r.role_id
            WHERE mr.recipient_id = ? AND mr.deleted = 0 AND mr.is_archived = 0
            ORDER BY m.created_at DESC
        ");
        $messages_stmt->execute([$user_id]);
        $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'sent':
        $messages_stmt = $pdo->prepare("
            SELECT m.*, 
                   GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as recipient_names,
                   (SELECT COUNT(*) FROM message_recipients WHERE message_id = m.message_id AND is_read = 1) as read_count,
                   (SELECT COUNT(*) FROM message_recipients WHERE message_id = m.message_id) as total_recipients
            FROM messages m
            LEFT JOIN message_recipients mr ON m.message_id = mr.message_id
            LEFT JOIN users u ON mr.recipient_id = u.user_id
            WHERE m.sender_id = ? AND m.deleted_by_sender = 0 AND m.is_archived_by_sender = 0
            GROUP BY m.message_id
            ORDER BY m.created_at DESC
        ");
        $messages_stmt->execute([$user_id]);
        $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'archived':
        $messages_stmt = $pdo->prepare("
            SELECT m.*, MAX(mr.is_read) as is_read,
                   MAX(CONCAT(s.first_name, ' ', s.last_name)) as sender_name,
                   MAX(r.role_name) as sender_role,
                   GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', ') as recipient_names
            FROM messages m
            LEFT JOIN message_recipients mr ON m.message_id = mr.message_id AND mr.recipient_id = ?
            LEFT JOIN users s ON m.sender_id = s.user_id
            LEFT JOIN roles r ON s.role_id = r.role_id
            LEFT JOIN message_recipients gr ON m.message_id = gr.message_id
            LEFT JOIN users u ON gr.recipient_id = u.user_id
            WHERE (mr.recipient_id = ? AND mr.is_archived = 1 AND mr.deleted = 0)
               OR (m.sender_id = ? AND m.is_archived_by_sender = 1 AND m.deleted_by_sender = 0)
            GROUP BY m.message_id
            ORDER BY m.created_at DESC
        ");
        $messages_stmt->execute([$user_id, $user_id, $user_id]);
        $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

// Load specific message if viewing
$current_message = null;
$replies = [];
if ($message_id) {
    // Load main message
    $message_stmt = $pdo->prepare("
        SELECT m.*, 
               CONCAT(s.first_name, ' ', s.last_name) as sender_name,
               s.email as sender_email,
               d.department_name as sender_department,
               mr.is_read
        FROM messages m
        LEFT JOIN users s ON m.sender_id = s.user_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN message_recipients mr ON (m.message_id = mr.message_id AND mr.recipient_id = ?)
        WHERE m.message_id = ?
    ");
    $message_stmt->execute([$user_id, $message_id]);
    $current_message = $message_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Auto-mark as read if viewing a received message
    if ($current_message && $folder == 'inbox') {
        $stmt = $pdo->prepare("UPDATE message_recipients SET is_read = 1, read_at = NOW() WHERE message_id = ? AND recipient_id = ?");
        $stmt->execute([$message_id, $user_id]);
    }
    
    // Mark as read if viewing and it's unread
    if ($current_message && !$current_message['is_read'] && $current_message['sender_id'] != $user_id) {
        $stmt = $pdo->prepare("
            UPDATE message_recipients 
            SET is_read = 1, read_at = NOW() 
            WHERE message_id = ? AND recipient_id = ?
        ");
        $stmt->execute([$message_id, $user_id]);
        $current_message['is_read'] = 1;
    }
    
    // Load replies
    $replies_stmt = $pdo->prepare("
        SELECT m.*, 
               CONCAT(s.first_name, ' ', s.last_name) as sender_name,
               s.email as sender_email,
               d.department_name as sender_department
        FROM messages m
        JOIN users s ON m.sender_id = s.user_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        WHERE m.parent_id = ?
        ORDER BY m.created_at ASC
    ");
    $replies_stmt->execute([$message_id]);
    $replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-chat-dots"></i> <?= $is_sw ? 'Kituo cha Ujumbe' : 'Message Center' ?></h2>
            <p class="text-muted"><?= $is_sw ? 'Mfumo wa mawasiliano ya ndani' : 'Internal communication and messaging system' ?></p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="row">
        <!-- Sidebar - Folders & Compose -->
        <div class="col-lg-3">
            <!-- Compose Button -->
            <div class="card shadow mb-4">
                <div class="card-body text-center">
                    <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#composeModal">
                        <i class="bi bi-pencil-square"></i> <?= $is_sw ? 'Andika Ujumbe' : 'Compose Message' ?>
                    </button>
                </div>
            </div>

            <!-- Folders -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-folder"></i> Folders</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <a href="<?= getUrl("message_center") ?>?folder=inbox" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $folder == 'inbox' ? 'active' : '' ?>">
                            <span><i class="bi bi-inbox"></i> <?= $is_sw ? 'Sanduku la Ujumbe' : 'Inbox' ?></span>
                            <?php if ($message_stats['unread_count'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $message_stats['unread_count'] ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="<?= getUrl("message_center") ?>?folder=sent" class="list-group-item list-group-item-action <?= $folder == 'sent' ? 'active' : '' ?>">
                            <i class="bi bi-send"></i> <?= $is_sw ? 'Ujumbe Uliotumwa' : 'Sent Messages' ?>
                            <span class="badge bg-secondary float-end"><?= $message_stats['sent_count'] ?? 0 ?></span>
                        </a>
                        <a href="<?= getUrl("message_center") ?>?folder=archived" class="list-group-item list-group-item-action <?= $folder == 'archived' ? 'active' : '' ?>">
                            <i class="bi bi-archive"></i> <?= $is_sw ? 'Zilizohifadhiwa' : 'Archived' ?>
                            <span class="badge bg-secondary float-end"><?= $message_stats['archived_count'] ?? 0 ?></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
                            <i class="bi bi-plus-circle"></i> New Message
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" id="refreshMessages">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                        <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#searchModal">
                            <i class="bi bi-search"></i> Search Messages
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">
            <?php if ($message_id && $current_message): ?>
                <!-- Message View -->
                <div class="card shadow">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?= htmlspecialchars($current_message['subject']) ?></h5>
                        <div class="btn-group">
                            <a href="<?= getUrl("message_center") ?>?folder=<?= $folder ?>" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Original Message -->
                        <div class="message-header border-bottom pb-3 mb-3">
                            <div class="row">
                                <div class="col-md-8">
                                    <strong>From:</strong> <?= htmlspecialchars($current_message['sender_name'] ?? ($is_sw ? 'Mtumiaji' : 'User')) ?>
                                    <?php if (!empty($current_message['sender_department'])): ?>
                                        <small class="text-muted">(<?= htmlspecialchars($current_message['sender_department'] ?? '') ?>)</small>
                                    <?php endif; ?>
                                    <br>
                                    <strong>To:</strong> 
                                    <?php if ($current_message['sender_id'] == $user_id): ?>
                                        <!-- Show recipients for sent messages -->
                                        <?php
                                        $recipients_stmt = $pdo->prepare("
                                            SELECT CONCAT(u.first_name, ' ', u.last_name) as name 
                                            FROM message_recipients mr 
                                            JOIN users u ON mr.recipient_id = u.user_id 
                                            WHERE mr.message_id = ?
                                        ");
                                        $recipients_stmt->execute([$message_id]);
                                        $recipients = $recipients_stmt->fetchAll(PDO::FETCH_COLUMN);
                                        echo htmlspecialchars(implode(', ', $recipients ?? []));
                                        ?>
                                    <?php else: ?>
                                        Me
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4 text-end">
                                    <small class="text-muted">
                                        <?= date('M j, Y g:i A', strtotime($current_message['created_at'])) ?>
                                    </small>
                                    <br>
                                    <span class="badge bg-<?= getPriorityBadgeColor($current_message['priority']) ?>">
                                        <?= ucfirst($current_message['priority']) ?> Priority
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="message-content mb-4">
                            <?= nl2br(htmlspecialchars($current_message['message'] ?? '')) ?>
                        </div>

                        <!-- Message Actions -->
                        <div class="message-actions border-top pt-3">
                            <div class="d-flex gap-2">
                                <?php if ($folder == 'inbox'): ?>
                                    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#replyModal">
                                        <i class="bi bi-reply"></i> <?= $is_sw ? 'Jibu' : 'Reply' ?>
                                    </button>
                                <?php endif; ?>
                                <form method="POST" class="confirm-form">
                                    <input type="hidden" name="message_id" value="<?= $current_message['message_id'] ?>">
                                    <?php if ($folder !== 'archived'): ?>
                                    <button type="submit" name="archive_message" class="btn btn-outline-warning rounded-pill px-4" 
                                            data-title="<?= $is_sw ? 'Hifadhi Ujumbe?' : 'Archive Message?' ?>"
                                            data-text="<?= $is_sw ? 'Je, una uhakika unataka kuhifadhi ujumbe huu?' : 'Are you sure you want to archive this message?' ?>">
                                        <i class="bi bi-archive"></i> <?= $is_sw ? 'Hifadhi' : 'Archive' ?>
                                    </button>
                                    <?php endif; ?>
                                    <button type="submit" name="delete_message" class="btn btn-outline-danger rounded-pill px-4"
                                            data-title="<?= $is_sw ? 'Futa Ujumbe?' : 'Delete Message?' ?>"
                                            data-text="<?= $is_sw ? 'Je, una uhakika unataka kufuta ujumbe huu? Hutaweza kuurejesha.' : 'Are you sure you want to delete this message? This action cannot be undone.' ?>">
                                        <i class="bi bi-trash"></i> <?= $is_sw ? 'Futa' : 'Delete' ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Replies -->
                <?php if (!empty($replies)): ?>
                    <div class="mt-4">
                        <h6 class="mb-3">Replies (<?= count($replies) ?>)</h6>
                        <?php foreach ($replies as $reply): ?>
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <strong><?= htmlspecialchars($reply['sender_name']) ?></strong>
                                        <small class="text-muted">
                                            <?= date('M j, Y g:i A', strtotime($reply['created_at'])) ?>
                                        </small>
                                    </div>
                                    <div class="message-content">
                                        <?= nl2br(htmlspecialchars($reply['message'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Messages List -->
                <div class="card shadow">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-<?= $folder == 'inbox' ? 'inbox' : ($folder == 'sent' ? 'send' : 'archive') ?>"></i>
                            <?php 
                                if ($folder === 'archived') {
                                    echo $is_sw ? 'Ujumbe Uliohifadhiwa' : 'All Archived Messages'; 
                                } else {
                                    echo ucfirst($folder); 
                                }
                            ?> 
                            <?php if ($folder == 'inbox' && $message_stats['unread_count'] > 0): ?>
                                <span class="badge bg-danger ms-2"><?= $message_stats['unread_count'] ?> unread</span>
                            <?php endif; ?>
                        </h5>
                        <div class="btn-group">
                            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#composeModal">
                                <i class="bi bi-pencil-square"></i> Compose
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($messages)): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($messages as $message): ?>
                                    <div class="list-group-item list-group-item-action <?= ($message['is_read'] ?? 1) == 0 && $folder == 'inbox' ? 'bg-light' : '' ?> px-4 py-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="flex-grow-1" onclick="window.location.href='<?= getUrl("message_center") ?>?folder=<?= $folder ?>&message_id=<?= $message['message_id'] ?>'" style="cursor: pointer;">
                                                <div class="d-flex align-items-center mb-1">
                                                    <?php if (($message['is_read'] ?? 1) == 0 && $folder == 'inbox'): ?>
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-2 px-3"><?= $is_sw ? 'Ujumbe Mpya' : 'NEW' ?></span>
                                                    <?php endif; ?>
                                                    <h6 class="mb-0 fw-bold"><?= htmlspecialchars($message['subject']) ?></h6>
                                                </div>
                                                
                                                <div class="small text-muted">
                                                    <?php if ($folder == 'sent'): ?>
                                                        <strong class="text-dark"><?= $is_sw ? 'Kwa:' : 'To:' ?></strong> <?= htmlspecialchars($message['recipient_names'] ?? ($is_sw ? 'Mtumiaji' : 'User')) ?>
                                                    <?php else: ?>
                                                        <strong class="text-dark"><?= $is_sw ? 'Kutoka:' : 'From:' ?></strong> 
                                                        <?= htmlspecialchars($message['sender_name'] ?? ($is_sw ? 'Mtumiaji' : 'User')) ?> 
                                                        <span class="text-primary">(<?= htmlspecialchars($message['sender_role'] ?? ($is_sw ? 'Mwanachama' : 'Member')) ?>)</span>
                                                    <?php endif; ?>
                                                    <span class="mx-2 text-silver">|</span>
                                                    <i class="bi bi-calendar3 small"></i> <?= date('M j, Y g:i A', strtotime($message['created_at'])) ?>
                                                </div>
                                            </div>

                                            <div class="dropdown" onclick="event.stopPropagation();">
                                                <button class="btn btn-light btn-sm rounded-1 shadow-sm border" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                    <i class="bi bi-gear"></i> <i class="bi bi-caret-down-fill small"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                                    <li>
                                                        <a class="dropdown-item py-2" href="<?= getUrl("message_center") ?>?folder=<?= $folder ?>&message_id=<?= $message['message_id'] ?>">
                                                            <i class="bi bi-eye text-primary me-2"></i> <?= $is_sw ? 'Ona' : 'View' ?>
                                                        </a>
                                                    </li>
                                                    <?php if ($folder !== 'archived'): ?>
                                                    <li>
                                                        <form method="POST" class="confirm-form">
                                                            <input type="hidden" name="message_id" value="<?= $message['message_id'] ?>">
                                                            <button type="submit" name="archive_message" class="dropdown-item py-2"
                                                                    data-title="<?= $is_sw ? 'Hifadhi Ujumbe?' : 'Archive Message?' ?>"
                                                                    data-text="<?= $is_sw ? 'Je, una uhakika?' : 'Are you sure you want to archive?' ?>">
                                                                <i class="bi bi-archive me-2 text-warning"></i> <?= $is_sw ? 'Hifadhi' : 'Archive' ?>
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="POST" class="confirm-form">
                                                            <input type="hidden" name="message_id" value="<?= $message['message_id'] ?>">
                                                            <button type="submit" name="delete_message" class="dropdown-item py-2 text-danger"
                                                                    data-title="<?= $is_sw ? 'Futa Ujumbe?' : 'Delete Message?' ?>"
                                                                    data-text="<?= $is_sw ? 'Je, una uhakika unataka kufuta ujumbe huu?' : 'Are you sure you want to delete?' ?>">
                                                                <i class="bi bi-trash me-2"></i> <?= $is_sw ? 'Futa' : 'Delete' ?>
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="bi bi-envelope-open text-muted fa-4x mb-3"></i>
                                <h5 class="text-muted">No messages found</h5>
                                <p class="text-muted">
                                    <?php if ($folder == 'inbox'): ?>
                                        Your inbox is empty. Send a message to get started!
                                    <?php elseif ($folder == 'sent'): ?>
                                        You haven't sent any messages yet.
                                    <?php else: ?>
                                        No archived messages.
                                    <?php endif; ?>
                                </p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#composeModal">
                                    <i class="bi bi-pencil-square"></i> Compose First Message
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Compose Modal -->
<div class="modal fade" id="composeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg" style="margin-top: 95px;">
        <div class="modal-content" style="border-radius: 0 !important;">
            <div class="modal-header bg-primary text-white" style="border-radius: 0 !important;">
                <h5 class="modal-title">Compose New Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="recipient_ids" class="form-label"><?= $is_sw ? 'Wapokeaji *' : 'Recipients *' ?></label>
                        <select class="form-control select2" id="recipient_ids" name="recipient_ids[]" multiple required>
                            <?php foreach ($all_users as $user): ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?> 
                                    (<?= htmlspecialchars($user['role_name'] ?? ($is_sw ? 'Mwanachama' : 'Member')) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small opacity-75"><?= $is_sw ? 'Shikilia Ctrl/Cmd kuchagua wapokeaji wengi' : 'Hold Ctrl/Cmd to select multiple recipients' ?></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subject" class="form-label"><?= $is_sw ? 'Mada (Subject) *' : 'Subject *' ?></label>
                        <input type="text" class="form-control" id="subject" name="subject" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="priority" class="form-label"><?= $is_sw ? 'Umuhimu' : 'Priority' ?></label>
                        <select class="form-control" id="priority" name="priority">
                            <option value="low"><?= $is_sw ? 'Chini' : 'Low' ?></option>
                            <option value="normal" selected><?= $is_sw ? 'Wastani' : 'Normal' ?></option>
                            <option value="high"><?= $is_sw ? 'Ya Juu' : 'High' ?></option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="message" class="form-label"><?= $is_sw ? 'Ujumbe *' : 'Message *' ?></label>
                        <textarea class="form-control" id="message" name="message" rows="8" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="send_message" class="btn btn-primary">Send Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reply Modal -->
<?php if ($current_message): ?>
<div class="modal fade" id="replyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg" style="margin-top: 95px;">
        <div class="modal-content" style="border-radius: 0 !important;">
            <div class="modal-header bg-primary text-white" style="border-radius: 0 !important;">
                <h5 class="modal-title">Reply to Message</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="parent_id" value="<?= $current_message['message_id'] ?>">
                <input type="hidden" name="subject" value="RE: <?= htmlspecialchars($current_message['subject']) ?>">
                <input type="hidden" name="recipient_ids[]" value="<?= $current_message['sender_id'] ?>">
                <div class="modal-body">
                    <!-- WhatsApp style quote -->
                    <div class="p-3 mb-3 bg-light border-start border-4 border-primary rounded" style="opacity: 0.8;">
                        <div class="small fw-bold text-primary mb-1"><?= htmlspecialchars($current_message['sender_name'] ?? ($is_sw ? 'Mtumiaji' : 'User')) ?></div>
                        <div class="small text-muted text-truncate"><?= htmlspecialchars($current_message['message'] ?? '') ?></div>
                    </div>

                    <div class="mb-3">
                        <label for="reply_message" class="form-label fw-bold"><?= $is_sw ? 'Ujumbe wako *' : 'Your Reply *' ?></label>
                        <textarea class="form-control border-0 bg-light" id="reply_message" name="message" rows="5" 
                                  placeholder="<?= $is_sw ? 'Andika ujumbe hapa...' : 'Type your message here...' ?>" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="priority" class="form-label small text-muted"><?= $is_sw ? 'Umuhimu' : 'Priority' ?></label>
                        <select class="form-select form-select-sm" name="priority">
                            <option value="low"><?= $is_sw ? 'Chini' : 'Low' ?></option>
                            <option value="normal" selected><?= $is_sw ? 'Wastani' : 'Normal' ?></option>
                            <option value="high"><?= $is_sw ? 'Ya Juu' : 'High' ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-link link-secondary text-decoration-none px-4" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" name="send_message" class="btn btn-primary rounded-pill px-5"><?= $is_sw ? 'Tuma' : 'Send Reply' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search Modal -->
<div class="modal fade" id="searchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Search Messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="GET">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="search_query" class="form-label">Search Query</label>
                        <input type="text" class="form-control" id="search_query" name="q" placeholder="Enter keywords...">
                    </div>
                    <div class="mb-3">
                        <label for="search_folder" class="form-label">Search In</label>
                        <select class="form-control" id="search_folder" name="folder">
                            <option value="all">All Folders</option>
                            <option value="inbox">Inbox</option>
                            <option value="sent">Sent</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<style>
.card {
    border: none;
    border-radius: 0.5rem;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #e9ecef;
}

.list-group-item:last-child {
    border-bottom: none;
}

.list-group-item.bg-light {
    background-color: #f8f9fa !important;
    border-left: 4px solid #0d6efd;
}

.message-header {
    background-color: #f8f9fa;
    padding: 1rem;
    border-radius: 0.25rem;
}

.message-content {
    line-height: 1.6;
    white-space: pre-wrap;
}

.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
}
</style>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;

$(document).ready(function() {
    // Refresh messages
    $('#refreshMessages').click(function() {
        location.reload();
    });

    // Auto-focus message input in compose modal
    $('#composeModal').on('shown.bs.modal', function() {
        $('#subject').focus();
    });

    // Auto-focus message input in reply modal
    $('#replyModal').on('shown.bs.modal', function() {
        $('#reply_message').focus();
    });

    // Character counter for message
    $('#message, #reply_message').on('input', function() {
        const length = $(this).val().length;
        $('#charCount').text(length + ' characters');
    });

    // Select2 for recipient selection (if you have Select2 installed)
    if ($.fn.select2) {
        $('.select2').select2({
            placeholder: isSw ? 'Chagua wapokeaji...' : 'Select recipients...',
            width: '100%',
            dropdownParent: $('#composeModal')
        });
    }

    // Auto-save draft (basic implementation)
    let draftTimer;
    $('#subject, #message').on('input', function() {
        clearTimeout(draftTimer);
        draftTimer = setTimeout(saveDraft, 2000);
    });

    function saveDraft() {
        const draft = {
            subject: $('#subject').val(),
            message: $('#message').val(),
            recipients: $('#recipient_ids').val()
        };
        localStorage.setItem('messageDraft', JSON.stringify(draft));
        showToast('info', 'Draft saved automatically');
    }

    // Load draft if exists
    const savedDraft = localStorage.getItem('messageDraft');
    if (savedDraft) {
        const draft = JSON.parse(savedDraft);
        $('#subject').val(draft.subject);
        $('#message').val(draft.message);
        if (draft.recipients) {
            $('#recipient_ids').val(draft.recipients);
        }
    }

    // Clear draft when message is sent
    $('form').on('submit', function() {
        if ($(this).find('button[name="send_message"]').length) {
            localStorage.removeItem('messageDraft');
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl/Cmd + N for new message
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            $('#composeModal').modal('show');
        }
        // Escape to go back from message view
        if (e.key === 'Escape' && window.location.search.includes('message_id')) {
            window.history.back();
        }
    });

    // Toast notification function
    function showToast(type, message) {
        var toast = '<div class="toast align-items-center text-white bg-' + type + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        toast += '<div class="d-flex">';
        toast += '<div class="toast-body">' + message + '</div>';
        toast += '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
        toast += '</div></div>';
        
        var $toast = $(toast);
        $('.toast-container').append($toast);
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
        
        $toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }
});
</script>

<?php
// Helper function for priority badge colors
function getPriorityBadgeColor($priority) {
    switch ($priority) {
        case 'high': return 'danger';
        case 'normal': return 'primary';
        case 'low': return 'secondary';
        default: return 'secondary';
    }
}
<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$member_id = $_GET['member_id'] ?? null;

if (!$member_id) {
    echo json_encode(['success' => false, 'message' => 'Member ID is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT customer_id, first_name, middle_name, last_name, spouse_first_name, spouse_middle_name, spouse_last_name, spouse_deceased, children_data FROM customers WHERE customer_id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit();
    }

    $beneficiaries = [];
    
    // Add Member themselves
    $beneficiaries[] = [
        'id' => 'member',
        'type' => 'member',
        'name' => trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name']) . ' (Member/Mwanachama)'
    ];

    // Add Spouse if exists and not deceased
    if (!empty($member['spouse_first_name']) && !$member['spouse_deceased']) {
        $beneficiaries[] = [
            'id' => 'spouse',
            'type' => 'spouse',
            'name' => trim($member['spouse_first_name'] . ' ' . $member['spouse_middle_name'] . ' ' . $member['spouse_last_name']) . ' (Spouse/Mwenza)'
        ];
    }

    // Add Children
    if (!empty($member['children_data'])) {
        $children = json_encode($member['children_data']); // It might be stored as string or already decoded depending on PHP version/driver
        // Ensure it's decoded
        $children_arr = is_string($member['children_data']) ? json_decode($member['children_data'], true) : $member['children_data'];
        
        if (is_array($children_arr)) {
            foreach ($children_arr as $index => $child) {
                // Assuming child object has 'name' and we might want to track if they are alive (not yet in schema but in logic)
                if (!isset($child['is_deceased']) || !$child['is_deceased']) {
                    $beneficiaries[] = [
                        'id' => 'child_' . $index,
                        'type' => 'child',
                        'name' => ($child['name'] ?? 'Mtoto ' . ($index + 1)) . ' (Child/Mtoto)'
                    ];
                }
            }
        }
    }

    echo json_encode(['success' => true, 'beneficiaries' => $beneficiaries]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

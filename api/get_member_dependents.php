<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

$member_id = $_GET['member_id'] ?? 0;
$lang = $_SESSION['preferred_language'] ?? 'sw';
$is_sw = ($lang === 'sw');

try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        throw new Exception($is_sw ? "Mwanachama hakupatikana." : "Member not found.");
    }

    $stmt = $pdo->prepare("SELECT deceased_id, deceased_name FROM death_expenses WHERE member_id = ? AND status != 'rejected'");
    $stmt->execute([$member_id]);
    $recs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $deceased_ids = array_filter(array_column($recs, 'deceased_id'));
    $deceased_names = array_column($recs, 'deceased_name');

    if (in_array('member', $deceased_ids) || ($member['is_deceased'] == 1)) {
        echo json_encode([
            'success' => true, 
            'dependents' => [], 
            'message' => $is_sw ? 'Mwanachama tayari amefariki.' : 'Member is already deceased.'
        ]);
        exit();
    }

    $dependents = [];
    $is_dead = function($id, $name) use ($deceased_ids, $deceased_names) {
        if (!empty($id) && in_array($id, $deceased_ids)) return true;
        if (!empty($name) && in_array($name, $deceased_names)) return true;
        return false;
    };

    // 1. Member themselves
    if (!$is_dead('member', $member['first_name'] . ' ' . $member['last_name'])) {
        $dependents[] = [
            'id' => 'member',
            'type' => 'mwanachama',
            'relationship' => $is_sw ? 'Mwanachama' : 'Member',
            'name' => $member['first_name'] . ' ' . $member['last_name']
        ];
    }

    // 2. Spouse
    $spouse_name = trim(($member['spouse_first_name'] ?? '') . ' ' . ($member['spouse_last_name'] ?? ''));
    if (!empty($spouse_name) && !$is_dead('spouse', $spouse_name)) {
        $g = strtolower($member['spouse_gender'] ?? '');
        $isFemale = ($g === 'female' || $g === 'mwanamke' || $g === 'f');
        if ($is_sw) {
            $rel = $isFemale ? 'Mke' : 'Mme';
        } else {
            $rel = $isFemale ? 'Wife' : 'Husband';
        }
        $dependents[] = [
            'id' => 'spouse',
            'type' => 'spouse',
            'relationship' => $rel,
            'name' => $spouse_name
        ];
    }

    // 3. Children
    if (!empty($member['children_data'])) {
        $children = json_decode($member['children_data'], true);
        if (is_array($children)) {
            foreach ($children as $index => $child) {
                $child_id = 'child_' . $index;
                $child_name = $child['name'] ?? '';
                if (!empty($child_name) && !$is_dead($child_id, $child_name)) {
                    $dependents[] = [
                        'id' => $child_id,
                        'type' => 'child',
                        'relationship' => $is_sw ? 'Mtoto' : 'Child',
                        'name' => $child_name
                    ];
                }
            }
        }
    }
    
    // 4. Parents
    if (!empty($member['father_name']) && !$is_dead('father', $member['father_name'])) {
        $dependents[] = [
            'id' => 'father',
            'type' => 'parent',
            'relationship' => $is_sw ? 'Baba' : 'Father',
            'name' => $member['father_name']
        ];
    }
    if (!empty($member['mother_name']) && !$is_dead('mother', $member['mother_name'])) {
        $dependents[] = [
            'id' => 'mother',
            'type' => 'parent',
            'relationship' => $is_sw ? 'Mama' : 'Mother',
            'name' => $member['mother_name']
        ];
    }

    echo json_encode(['success' => true, 'dependents' => $dependents]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

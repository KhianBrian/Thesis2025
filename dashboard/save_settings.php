<?php
/**
 * dashboard/save_settings.php — Online (Render)
 * Handles AJAX POST from the Settings panel.
 * Updates patient_table and hr_threshold_table in PostgreSQL.
 * Caregiver and Admin can edit. Patient is view-only (enforced here too).
 */
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated.']);
    exit();
}

$userRole = strtolower($_SESSION['role'] ?? 'patient');

// Patients cannot edit settings
if ($userRole === 'patient') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Patients have view-only access.']);
    exit();
}

require_once dirname(__DIR__) . '/db_connect.php';

$patientID = $_SESSION['patient_id'] ?? null;

if (!$patientID) {
    echo json_encode(['success' => false, 'error' => 'No patient profile linked to this account.']);
    exit();
}

$input   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$section = $input['section'] ?? '';

try {
    // ── SECTION: personal ─────────────────────────────────────────
    if ($section === 'personal') {
        $stmt = $pdo->prepare("
            UPDATE patient_table SET
                firstname                = $1,
                middlename               = $2,
                lastname                 = $3,
                birthdate                = $4,
                gender                   = $5,
                phonenumber              = $6,
                addressline              = $7,
                city                     = $8,
                province                 = $9,
                postalcode               = $10,
                emergencycontactname     = $11,
                emergencycontactnumber   = $12,
                emergencyrelationship    = $13,
                age = EXTRACT(YEAR FROM AGE(CURRENT_DATE, $4::date))
            WHERE patientid = $14
        ");
        $stmt->execute([
            $input['first_name']         ?? null,
            $input['middle_name']        ?: null,
            $input['last_name']          ?? null,
            $input['birth_date']         ?? null,
            $input['gender']             ?? null,
            $input['phone']              ?: null,
            $input['address']            ?: null,
            $input['city']               ?: null,
            $input['province']           ?: null,
            $input['postal_code']        ?: null,
            $input['emergency_name']     ?: null,
            $input['emergency_number']   ?: null,
            $input['emergency_relation'] ?? null,
            $patientID,
        ]);
        $_SESSION['display_name'] = trim(($input['first_name'] ?? '') . ' ' . ($input['last_name'] ?? ''));
        echo json_encode(['success' => true, 'message' => 'Personal info saved.']);
    }

    // ── SECTION: thresholds ───────────────────────────────────────
    elseif ($section === 'thresholds') {
        // Check if row exists
        $check = $pdo->prepare("SELECT thresholdid FROM hr_threshold_table WHERE patientid = $1 LIMIT 1");
        $check->execute([$patientID]);
        $exists = $check->fetch();

        if ($exists) {
            $stmt = $pdo->prepare("
                UPDATE hr_threshold_table SET
                    restingmin    = $1,
                    restingmax    = $2,
                    activemin     = $3,
                    activemax     = $4,
                    criticallevel = $5
                WHERE patientid = $6
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO hr_threshold_table
                    (patientid, restingmin, restingmax, activemin, activemax, criticallevel)
                VALUES ($1, $2, $3, $4, $5, $6)
            ");
            // Reorder params for insert
            $stmt->execute([
                $patientID,
                (int)($input['resting_min'] ?? 50),
                (int)($input['resting_max'] ?? 100),
                (int)($input['active_min']  ?? 100),
                (int)($input['active_max']  ?? 170),
                (int)($input['critical']    ?? 150),
            ]);
            echo json_encode(['success' => true, 'message' => 'HR thresholds saved.']);
            exit();
        }

        $stmt->execute([
            (int)($input['resting_min'] ?? 50),
            (int)($input['resting_max'] ?? 100),
            (int)($input['active_min']  ?? 100),
            (int)($input['active_max']  ?? 170),
            (int)($input['critical']    ?? 150),
            $patientID,
        ]);
        echo json_encode(['success' => true, 'message' => 'HR thresholds saved.']);
    }

    else {
        echo json_encode(['success' => false, 'error' => 'Unknown section.']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>

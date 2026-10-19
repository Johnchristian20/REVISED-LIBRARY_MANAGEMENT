<?php

function profileSchemaColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'ss', $table, $column);
    mysqli_stmt_execute($stmt);
    $exists = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $exists;
}

function ensureProfileMembershipSchema(mysqli $conn): bool
{
    static $ready = false;
    if ($ready) return true;

    $columns = [
        'users' => [
            'phone_number' => 'VARCHAR(25) NULL',
            'bio' => 'VARCHAR(500) NULL',
            'profile_picture' => 'VARCHAR(255) NULL',
            'profile_cover' => 'VARCHAR(255) NULL'
        ],
        'members' => [
            'user_id' => 'INT UNSIGNED NULL'
        ]
    ];
    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $definition) {
            if (!profileSchemaColumnExists($conn, $table, $column) &&
                !mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` $definition")) {
                error_log('Profile schema update failed: ' . mysqli_error($conn));
                return false;
            }
        }
    }

    $index = mysqli_query(
        $conn,
        "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members' AND INDEX_NAME='uq_members_user_id' LIMIT 1"
    );
    if ($index && !mysqli_fetch_assoc($index) &&
        !mysqli_query($conn, 'CREATE UNIQUE INDEX uq_members_user_id ON members(user_id)')) {
        error_log('Member account link index creation failed: ' . mysqli_error($conn));
        return false;
    }

    $constraint = mysqli_query(
        $conn,
        "SELECT 1 FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='members' AND CONSTRAINT_NAME='fk_members_user' LIMIT 1"
    );
    if ($constraint && !mysqli_fetch_assoc($constraint) &&
        !mysqli_query(
            $conn,
            'ALTER TABLE members ADD CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL ON UPDATE CASCADE'
        )) {
        error_log('Member account link foreign key creation failed: ' . mysqli_error($conn));
        return false;
    }

    // Connect existing member records to accounts with the same email address.
    mysqli_query(
        $conn,
        'UPDATE members m INNER JOIN users u ON LOWER(m.email)=LOWER(u.email)
         SET m.user_id=u.user_id WHERE m.user_id IS NULL'
    );
    $ready = true;
    return true;
}

function loadUserProfile(mysqli $conn, int $userId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT u.user_id,u.first_name,u.last_name,u.email,u.username,u.password,u.phone_number,u.bio,u.profile_picture,u.profile_cover,
                m.member_id,m.membership_number,m.first_name AS member_first_name,m.last_name AS member_last_name,m.home_branch_id,m.membership_type,m.account_status,m.registration_date,m.expiration_date,br.branch_name AS home_branch_name
         FROM users u LEFT JOIN members m ON m.user_id=u.user_id LEFT JOIN branches br ON br.branch_id=m.home_branch_id WHERE u.user_id=? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);
    return $profile;
}

function validMembershipType(string $type): bool
{
    return in_array($type, ['Student', 'Faculty', 'Regular', 'Senior', 'Corporate'], true);
}

function saveUserProfile(mysqli $conn, int $userId, array $input, array $files, string &$error, bool $createMembership = true): bool
{
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $username = trim($input['username'] ?? '');
    $phone = trim($input['phone_number'] ?? '');
    if (array_key_exists('phone_country_code', $input) && $phone !== '') {
        $countryCodeDigits = preg_replace('/\D+/', '', (string) $input['phone_country_code']);
        $localNumber = preg_replace('/[^0-9 ()-]+/', '', $phone);
        $localDigits = preg_replace('/\D+/', '', $localNumber);
        if ($countryCodeDigits === '' || strlen($countryCodeDigits) > 4 || $localDigits === '') {
            $error = 'Choose a country code and enter a valid phone number.';
            return false;
        }
        $phone = '+' . $countryCodeDigits . ' ' . trim($localNumber);
    }
    $memberFirstName = trim($input['membership_first_name'] ?? $firstName);
    $memberLastName = trim($input['membership_last_name'] ?? $lastName);
    $bio = trim($input['bio'] ?? '');
    $branchId = filter_var($input['home_branch_id'] ?? '', FILTER_VALIDATE_INT);
    $membershipType = trim($input['membership_type'] ?? '');
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (in_array('', [$firstName, $lastName, $email, $username], true) ||
        ($createMembership && (in_array('', [$phone, $memberFirstName, $memberLastName], true) || !$branchId || !validMembershipType($membershipType)))) {
        $error = $createMembership
            ? 'Complete your name, email, username, phone, home branch, and membership type.'
            : 'Complete your name, email, and username.';
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3–50 characters and use letters, numbers, dots, underscores, or hyphens.';
        return false;
    }
    if (strlen($firstName) > 50 || strlen($lastName) > 50 || strlen($memberFirstName) > 50 || strlen($memberLastName) > 50 || strlen($email) > 100 || strlen($phone) > 25 || strlen($bio) > 500) {
        $error = 'One or more fields are longer than allowed.';
        return false;
    }

    if ($createMembership) {
        $branchCheck = mysqli_prepare($conn, 'SELECT branch_id FROM branches WHERE branch_id=?');
        mysqli_stmt_bind_param($branchCheck, 'i', $branchId);
        mysqli_stmt_execute($branchCheck);
        $branchExists = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($branchCheck));
        mysqli_stmt_close($branchCheck);
        if (!$branchExists) {
            $error = 'Choose a valid home branch.';
            return false;
        }
    }

    $stmt = mysqli_prepare($conn, 'SELECT password,profile_picture,profile_cover,phone_number FROM users WHERE user_id=?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$current) {
        $error = 'Account not found.';
        return false;
    }
    if (!$createMembership && !array_key_exists('phone_number', $input)) {
        $phone = (string) ($current['phone_number'] ?? '');
    }

    $newHash = null;
    if ($newPassword !== '' || $confirmPassword !== '' || $currentPassword !== '') {
        if (!password_verify($currentPassword, $current['password'])) {
            $error = 'Enter your current password to change it.';
            return false;
        }
        if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) {
            $error = 'New passwords must match and contain at least 8 characters.';
            return false;
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    $picture = $current['profile_picture'] ?? '';
    if (!empty($files['profile_picture']['name'])) {
        $file = $files['profile_picture'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $error = 'Profile photos must be smaller than 2 MB.';
            return false;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || !@getimagesize($file['tmp_name'])) {
            $error = 'Upload a JPG, PNG, or WebP profile photo.';
            return false;
        }
        $directory = dirname(__DIR__) . '/assets/uploads/profiles';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $error = 'The profile photo folder could not be created.';
            return false;
        }
        $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            $error = 'The profile photo could not be saved.';
            return false;
        }
        $picture = 'assets/uploads/profiles/' . $filename;
    }

    $cover = $current['profile_cover'] ?? '';
    if (!empty($files['profile_cover']['name'])) {
        $file = $files['profile_cover'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
            $error = 'Cover photos must be smaller than 5 MB.';
            return false;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || !@getimagesize($file['tmp_name'])) {
            $error = 'Upload a JPG, PNG, or WebP cover photo.';
            return false;
        }
        $directory = dirname(__DIR__) . '/assets/uploads/profiles';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $error = 'The cover photo folder could not be created.';
            return false;
        }
        $filename = 'cover-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
            $error = 'The cover photo could not be saved.';
            return false;
        }
        $cover = 'assets/uploads/profiles/' . $filename;
    }

    mysqli_begin_transaction($conn);
    try {
        if ($newHash !== null) {
            $userUpdate = mysqli_prepare($conn, 'UPDATE users SET first_name=?,last_name=?,email=?,username=?,phone_number=?,bio=?,profile_picture=?,profile_cover=?,password=? WHERE user_id=?');
            mysqli_stmt_bind_param($userUpdate, 'sssssssssi', $firstName, $lastName, $email, $username, $phone, $bio, $picture, $cover, $newHash, $userId);
        } else {
            $userUpdate = mysqli_prepare($conn, 'UPDATE users SET first_name=?,last_name=?,email=?,username=?,phone_number=?,bio=?,profile_picture=?,profile_cover=? WHERE user_id=?');
            mysqli_stmt_bind_param($userUpdate, 'ssssssssi', $firstName, $lastName, $email, $username, $phone, $bio, $picture, $cover, $userId);
        }
        if (!mysqli_stmt_execute($userUpdate)) {
            if (mysqli_stmt_errno($userUpdate) === 1062) throw new RuntimeException('That email or username is already used by another account.');
            throw new RuntimeException('Could not update your account.');
        }
        mysqli_stmt_close($userUpdate);

        if (!$createMembership) {
            mysqli_commit($conn);
            return true;
        }

        $memberFind = mysqli_prepare($conn, 'SELECT member_id,user_id,membership_number,account_status FROM members WHERE user_id=? OR email=? LIMIT 1 FOR UPDATE');
        mysqli_stmt_bind_param($memberFind, 'is', $userId, $email);
        mysqli_stmt_execute($memberFind);
        $member = mysqli_fetch_assoc(mysqli_stmt_get_result($memberFind));
        mysqli_stmt_close($memberFind);

        if ($member && !empty($member['user_id']) && (int) $member['user_id'] !== $userId) {
            throw new RuntimeException('That email is already linked to a different library membership. Contact the librarian to resolve it.');
        }

        $today = date('Y-m-d');
        $expiry = date('Y-m-d', strtotime('+1 year'));
        $status = $member['account_status'] ?? 'Active';
        if ($status === 'Pending') $status = 'Active';
        if ($member) {
            $memberId = (int) $member['member_id'];
            $membershipNumber = $member['membership_number'];
            $memberUpdate = mysqli_prepare($conn, 'UPDATE members SET user_id=?,membership_number=?,first_name=?,last_name=?,email=?,phone_number=?,home_branch_id=?,membership_type=?,account_status=? WHERE member_id=?');
            mysqli_stmt_bind_param($memberUpdate, 'isssssissi', $userId, $membershipNumber, $memberFirstName, $memberLastName, $email, $phone, $branchId, $membershipType, $status, $memberId);
            if (!mysqli_stmt_execute($memberUpdate)) {
                if (mysqli_stmt_errno($memberUpdate) === 1062) throw new RuntimeException('This email is already registered to another member.');
                throw new RuntimeException('Could not update your library membership.');
            }
            mysqli_stmt_close($memberUpdate);
        } else {
            $membershipNumber = 'USR-' . str_pad((string) $userId, 8, '0', STR_PAD_LEFT);
            $borrowLimit = $membershipType === 'Corporate' ? 15 : ($membershipType === 'Faculty' ? 10 : ($membershipType === 'Senior' ? 6 : 5));
            $memberInsert = mysqli_prepare($conn, 'INSERT INTO members (user_id,membership_number,first_name,last_name,email,phone_number,home_branch_id,membership_type,account_status,max_borrow_limit,registration_date,expiration_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            mysqli_stmt_bind_param($memberInsert, 'isssssississ', $userId, $membershipNumber, $memberFirstName, $memberLastName, $email, $phone, $branchId, $membershipType, $status, $borrowLimit, $today, $expiry);
            if (!mysqli_stmt_execute($memberInsert)) {
                if (mysqli_stmt_errno($memberInsert) === 1062) throw new RuntimeException('This email or membership number is already registered. Contact the librarian for help.');
                throw new RuntimeException('Could not create your library membership.');
            }
            mysqli_stmt_close($memberInsert);
        }

        mysqli_commit($conn);
        return true;
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'Could not save your profile.';
        return false;
    }
}

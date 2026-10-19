<?php
require __DIR__ . '/../includes/auth.php';
require_admin();
// Loans are created by the request pickup workflow, not by manual entry.
header('Location: ../requests/index.php');
exit;

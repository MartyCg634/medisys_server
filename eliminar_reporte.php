<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT))) {
    $stmt = db()->prepare('DELETE FROM reportes WHERE id=?');
    $stmt->execute([$id]);
}
redirect('reportes.php');

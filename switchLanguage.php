<?php
/*
 * Language Switcher for Gibbon
 * Switches between English and Thai language
 */

require_once 'gibbon.php';

$allowedLangs = ['0001', '0035']; // en_GB, th_TH
$langID = $_GET['lang'] ?? '0001';

if (!in_array($langID, $allowedLangs)) {
    $langID = '0001';
}

try {
    $pdo = $container->get('db')->getConnection();
    $stmt = $pdo->prepare("SELECT * FROM gibboni18n WHERE gibboni18nID=:id AND active='Y'");
    $stmt->execute([':id' => $langID]);
    $row = $stmt->fetch();

    if ($row) {
        setLanguageSession($guid, $row, false);
        $session->set('gibboni18nIDPersonal', $langID);

        // Save to DB if logged in
        if ($session->get('username')) {
            $upd = $pdo->prepare("UPDATE gibbonPerson SET gibboni18nIDPersonal=:lang WHERE username=:user");
            $upd->execute([':lang' => $langID, ':user' => $session->get('username')]);
        }
    }
} catch (Exception $e) {
    // Fail silently
}

$redirect = $_SERVER['HTTP_REFERER'] ?? './index.php';
header('Location: ' . $redirect);
exit;

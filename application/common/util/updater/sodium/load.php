<?php
// JavCMS updater: loads the trimmed sodium_compat copy (Ed25519 signature verification only) in dependency order.
// Used only when ext-sodium is missing. See README.txt for the source, the pin and the renaming.
foreach (array('SodiumException.php', 'Compat.php', 'Util.php', 'Fe.php', 'GeP1p1.php', 'GeP2.php', 'GeP3.php', 'GePrecomp.php', 'GeCached.php', 'H.php', 'Curve25519.php', 'Ed25519.php') as $javcmsUpdSodiumFile) {
    require_once __DIR__ . '/' . $javcmsUpdSodiumFile;
}
unset($javcmsUpdSodiumFile);

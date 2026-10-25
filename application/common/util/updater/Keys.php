<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util\updater;

/**
 * Public release keys the updater trusts (Ed25519, base64 of the 32-byte public key).
 *
 * The private key never leaves the release owner's machine (release-tools/keygen.php writes it to a file of the owner's
 * choice; release-tools/embed_pubkey.php puts the public half here). A build without a key refuses every update ("This
 * build has no release key"): the updater fails closed.
 *
 * Key rotation: a release signed by a NEW key carries an endorsement of that key made with a key listed here (release-
 * tools/endorse_key.php). The updater accepts the new key once the endorsement verifies, remembers it in
 * runtime/update/keys.php, and the next release lists the new key here and may move the old one to REVOKED.
 * A key in REVOKED is never accepted, directly or as an endorser.
 */
final class Keys
{
    /** key id => base64 public key (key id = 'k' + first 12 hex digits of sha256(public key)). */
    const TRUSTED = array(
        'k5dd910cd7766' => '88MFLcOOvBJq41wt4111Af0gI3n0/xqDw7bibAgVJGw=',
    );

    /** key ids that must never be accepted again. */
    const REVOKED = array(
    );

    /** Key id of a raw 32-byte public key. */
    public static function id($raw)
    {
        return 'k' . substr(hash('sha256', (string)$raw), 0, 12);
    }
}

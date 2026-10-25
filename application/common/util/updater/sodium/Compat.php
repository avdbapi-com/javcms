<?php
// JavCMS updater: stand-in for ParagonIE_Sodium_Compat. The trimmed Ed25519 verification path reads only the $fastMult
// switch of that class; the other members are never reached by verify_detached() and throw if anything calls them.
if (!class_exists('JavcmsUpdSodium_Compat', false)) {
    class JavcmsUpdSodium_Compat
    {
        /** @var bool */
        public static $fastMult = false;

        public static function __callStatic($name, $args)
        {
            throw new JavcmsUpdSodiumException('Not part of the trimmed verification copy: ' . $name);
        }
    }
}

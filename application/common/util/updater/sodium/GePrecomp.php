<?php

if (class_exists('JavcmsUpdSodium_Core_Curve25519_Ge_Precomp', false)) {
    return;
}

/**
 * Class JavcmsUpdSodium_Core_Curve25519_Ge_Precomp
 */
class JavcmsUpdSodium_Core_Curve25519_Ge_Precomp
{
    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $yplusx;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $yminusx;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $xy2d;

    /**
     * JavcmsUpdSodium_Core_Curve25519_Ge_Precomp constructor.
     *
     * @internal You should not use this directly from another application
     *
     * @param JavcmsUpdSodium_Core_Curve25519_Fe $yplusx
     * @param JavcmsUpdSodium_Core_Curve25519_Fe $yminusx
     * @param JavcmsUpdSodium_Core_Curve25519_Fe $xy2d
     */
    public function __construct(
        $yplusx = null,
        $yminusx = null,
        $xy2d = null
    ) {
        if ($yplusx === null) {
            $yplusx = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($yplusx instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 1 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->yplusx = $yplusx;
        if ($yminusx === null) {
            $yminusx = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($yminusx instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 2 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->yminusx = $yminusx;
        if ($xy2d === null) {
            $xy2d = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($xy2d instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 3 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->xy2d = $xy2d;
    }
}

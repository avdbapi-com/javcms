<?php

if (class_exists('JavcmsUpdSodium_Core_Curve25519_Ge_P2', false)) {
    return;
}

/**
 * Class JavcmsUpdSodium_Core_Curve25519_Ge_P2
 */
class JavcmsUpdSodium_Core_Curve25519_Ge_P2
{
    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $X;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $Y;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $Z;

    /**
     * JavcmsUpdSodium_Core_Curve25519_Ge_P2 constructor.
     *
     * @internal You should not use this directly from another application
     *
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $x
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $y
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $z
     */
    public function __construct(
        $x = null,
        $y = null,
        $z = null
    ) {
        if ($x === null) {
            $x = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($x instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 1 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->X = $x;
        if ($y === null) {
            $y = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($y instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 2 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->Y = $y;
        if ($z === null) {
            $z = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($z instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 3 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->Z = $z;
    }
}

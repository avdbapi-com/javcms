<?php

if (class_exists('JavcmsUpdSodium_Core_Curve25519_Ge_P1p1', false)) {
    return;
}
/**
 * Class JavcmsUpdSodium_Core_Curve25519_Ge_P1p1
 */
class JavcmsUpdSodium_Core_Curve25519_Ge_P1p1
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
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $T;

    /**
     * JavcmsUpdSodium_Core_Curve25519_Ge_P1p1 constructor.
     *
     * @internal You should not use this directly from another application
     *
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $x
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $y
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $z
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $t
     */
    public function __construct(
        $x = null,
        $y = null,
        $z = null,
        $t = null
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
        if ($t === null) {
            $t = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($t instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 4 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->T = $t;
    }
}

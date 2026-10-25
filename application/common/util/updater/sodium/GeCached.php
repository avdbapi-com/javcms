<?php


if (class_exists('JavcmsUpdSodium_Core_Curve25519_Ge_Cached', false)) {
    return;
}
/**
 * Class JavcmsUpdSodium_Core_Curve25519_Ge_Cached
 */
class JavcmsUpdSodium_Core_Curve25519_Ge_Cached
{
    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $YplusX;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $YminusX;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $Z;

    /**
     * @var JavcmsUpdSodium_Core_Curve25519_Fe
     */
    public $T2d;

    /**
     * JavcmsUpdSodium_Core_Curve25519_Ge_Cached constructor.
     *
     * @internal You should not use this directly from another application
     *
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $YplusX
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $YminusX
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $Z
     * @param JavcmsUpdSodium_Core_Curve25519_Fe|null $T2d
     */
    public function __construct(
        $YplusX = null,
        $YminusX = null,
        $Z = null,
        $T2d = null
    ) {
        if ($YplusX === null) {
            $YplusX = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($YplusX instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 1 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->YplusX = $YplusX;
        if ($YminusX === null) {
            $YminusX = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($YminusX instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 2 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->YminusX = $YminusX;
        if ($Z === null) {
            $Z = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($Z instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 3 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->Z = $Z;
        if ($T2d === null) {
            $T2d = new JavcmsUpdSodium_Core_Curve25519_Fe();
        }
        if (!($T2d instanceof JavcmsUpdSodium_Core_Curve25519_Fe)) {
            throw new TypeError('Argument 4 must be an instance of JavcmsUpdSodium_Core_Curve25519_Fe');
        }
        $this->T2d = $T2d;
    }
}

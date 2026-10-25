Trimmed copy of paragonie/sodium_compat (ISC license, see LICENSE in this folder).

Purpose: Ed25519 signature verification for the JavCMS one-click updater on hosts without ext-sodium.
Pin: the copy bundled with WordPress 7.1.3 (wp-includes/sodium_compat, VERSION_STRING polyfill-1.0.8).
Changes: only the 11 files of the verification path; class names ParagonIE_Sodium_* renamed to JavcmsUpdSodium_*
and SodiumException to JavcmsUpdSodiumException (no other edit); Compat.php is a JavCMS stand-in for
ParagonIE_Sodium_Compat (only its $fastMult switch is read); load.php loads the files in order.

source file | source sha256 | file here | sha256 here
src/SodiumException.php | 4dc4b39dfd4ec7932aa9680ee915ec5c407e3bf6699f3f694102b292543e8b04 | SodiumException.php | 3f753d22db7775af648a523fb90c366c8f32f93c07eeae57cef0c0063f1be6b4
src/Core/Util.php | 1d508481ef6bbb5ca9e453cf044777128c78a9a37d0a7ac211e713340b2525af | Util.php | 86459390d33453656c30f9468418b6fa0d2fe29c349906efa9b63cff307e311b
src/Core/Curve25519/Fe.php | 16d1e163378754a173dcaaa97ee42799bc7e9965650d2c40e3d83b52d5a3cdc6 | Fe.php | 590c2dd80b376a0105cac85d8bbe545df8f31a33a504fd1134a8e506b3616776
src/Core/Curve25519/Ge/P1p1.php | a8860db08a54f9b598c2041cadf00589da180e2229eb175d782ae8da04845704 | GeP1p1.php | 2c3a004eebb8fec845295b07c720ceef132edeaf00881d45640519db102ff4f3
src/Core/Curve25519/Ge/P2.php | 7c57be8e8ea812c754ec0116fec5a85b62719ff18a6950b5923a04538e989f94 | GeP2.php | 6e64174e052667553d1ecb4fc9b6cb967c32376a62cbc3f6cf97d79c2e0a2ccc
src/Core/Curve25519/Ge/P3.php | 9694bd0948d20cb45ca3f787e85013884d7df7b543a344838f1111713e9e576a | GeP3.php | a225fc0064377e306911160764d16514b7c3fe4ea57363c2d351541ac057ba0e
src/Core/Curve25519/Ge/Precomp.php | cef6a5f25e9bb7863ee00dc0ed6a7a3b3f153c9daad3936d666da55f7e07327e | GePrecomp.php | 5bf748d00bded1ca82e62763eed3c718c6c341277f909f076d90284d9e8ecc53
src/Core/Curve25519/Ge/Cached.php | bbfeb1c884d3e64e223a6da86503154cd7cb478c812921dd31b50e71e252eae4 | GeCached.php | 7fddee9b0081a27574593096be77605c1e56ba71de5f6845b0c6f04b529b88df
src/Core/Curve25519/H.php | 5bdca3663d50486dd4d1101cf90b10f5ef9fbb7ccbf6fca8c29487941ba348ae | H.php | c2289db6c9df0fbfebc77bd42c9f583df14332485f143d2ea4f30f6ea7f25db6
src/Core/Curve25519.php | 02f855c266aa4376c19fc9f551f4b923260791a6fceed38d9c39732279eaff23 | Curve25519.php | 69c53b89b03bafcb0b927e040b8905e555e72b286e7409efd305b5cc3f09e002
src/Core/Ed25519.php | 08a28c75a29a4705096c0c62ef8c40b35244ac04d45d82a9ea79f68941196e15 | Ed25519.php | 054839f8ea3222060bb63c27270556e811b4d35ec8996a1e2a6d019061d00cf7
LICENSE | 1f74dfe6e7621f71daf7928dcd522a86dedd1118307fe48256e726d38effc8f7 | LICENSE | 1f74dfe6e7621f71daf7928dcd522a86dedd1118307fe48256e726d38effc8f7

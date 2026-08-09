# PHQR API

Source: `library/PHQR.php`  
SHA-256: `d7ce97420cb7e0d1ce604eff7081b50e40c04b9eb191eccb7e3ef0796ab9ee80`

Classes: `PHQR`, `QRCode`, `QRUtil`, `QRRSBlock`, `QRNumber`, `QRKanji`, `QRAlphaNum`, `QR8BitByte`, `QRData`, `QRMath`, `QRPolynomial`, `QRBitBuffer`

## Public methods

### `static make($data, int $size = 8, int $margin = 4): string`

Generate a memory-safe PNG data URI.

### `__construct()`

See executable source and call sites for the behavioral contract.

### `getTypeNumber()`

See executable source and call sites for the behavioral contract.

### `setTypeNumber($typeNumber)`

See executable source and call sites for the behavioral contract.

### `getErrorCorrectLevel()`

See executable source and call sites for the behavioral contract.

### `setErrorCorrectLevel($errorCorrectLevel)`

See executable source and call sites for the behavioral contract.

### `addData($data, $mode = 0)`

See executable source and call sites for the behavioral contract.

### `clearData()`

See executable source and call sites for the behavioral contract.

### `addDataImpl($qrData)`

See executable source and call sites for the behavioral contract.

### `getDataCount()`

See executable source and call sites for the behavioral contract.

### `getData($index)`

See executable source and call sites for the behavioral contract.

### `isDark($row, $col)`

See executable source and call sites for the behavioral contract.

### `getModuleCount()`

See executable source and call sites for the behavioral contract.

### `hex2rgb($hex = 0x0)`

See executable source and call sites for the behavioral contract.

### `make()`

See executable source and call sites for the behavioral contract.

### `getBestMaskPattern()`

See executable source and call sites for the behavioral contract.

### `createNullArray($length)`

See executable source and call sites for the behavioral contract.

### `makeImpl($test, $maskPattern)`

See executable source and call sites for the behavioral contract.

### `mapData(&$data, $maskPattern)`

See executable source and call sites for the behavioral contract.

### `setupPositionAdjustPattern()`

See executable source and call sites for the behavioral contract.

### `setupPositionProbePattern($row, $col)`

See executable source and call sites for the behavioral contract.

### `setupTimingPattern()`

See executable source and call sites for the behavioral contract.

### `setupTypeNumber($test)`

See executable source and call sites for the behavioral contract.

### `setupTypeInfo($test, $maskPattern)`

See executable source and call sites for the behavioral contract.

### `createData($typeNumber, $errorCorrectLevel, $dataArray)`

See executable source and call sites for the behavioral contract.

### `createBytes(&$buffer, &$rsBlocks)`

See executable source and call sites for the behavioral contract.

### `static getMinimumQRCode($data, $errorCorrectLevel)`

See executable source and call sites for the behavioral contract.

### `createImage($size = 2, $margin = 2, $fg = 0x000000, $bg = 0xFFFFFF, $bgtrans = false)`

See executable source and call sites for the behavioral contract.

### `printHTML($size = "2px")`

See executable source and call sites for the behavioral contract.

### `printSVG($size = 2)`

See executable source and call sites for the behavioral contract.

### `static getPatternPosition($typeNumber)`

See executable source and call sites for the behavioral contract.

### `static getMaxLength($typeNumber, $mode, $errorCorrectLevel)`

See executable source and call sites for the behavioral contract.

### `static getErrorCorrectPolynomial($errorCorrectLength)`

See executable source and call sites for the behavioral contract.

### `static getMask($maskPattern, $i, $j)`

See executable source and call sites for the behavioral contract.

### `static getLostPoint($qrCode)`

See executable source and call sites for the behavioral contract.

### `static getMode($s)`

See executable source and call sites for the behavioral contract.

### `static isNumber($s)`

See executable source and call sites for the behavioral contract.

### `static isAlphaNum($s)`

See executable source and call sites for the behavioral contract.

### `static isKanji($s)`

See executable source and call sites for the behavioral contract.

### `static toCharCode($s)`

See executable source and call sites for the behavioral contract.

### `static getBCHTypeInfo($data)`

See executable source and call sites for the behavioral contract.

### `static getBCHTypeNumber($data)`

See executable source and call sites for the behavioral contract.

### `static getBCHDigit($data)`

See executable source and call sites for the behavioral contract.

### `__construct($totalCount, $dataCount)`

See executable source and call sites for the behavioral contract.

### `getDataCount()`

See executable source and call sites for the behavioral contract.

### `getTotalCount()`

See executable source and call sites for the behavioral contract.

### `static getRSBlocks($typeNumber, $errorCorrectLevel)`

See executable source and call sites for the behavioral contract.

### `static getRsBlockTable($typeNumber, $errorCorrectLevel)`

See executable source and call sites for the behavioral contract.

### `__construct($data)`

See executable source and call sites for the behavioral contract.

### `write(&$buffer)`

See executable source and call sites for the behavioral contract.

### `static parseInt($s)`

See executable source and call sites for the behavioral contract.

### `static parseIntAt($c)`

See executable source and call sites for the behavioral contract.

### `__construct($data)`

See executable source and call sites for the behavioral contract.

### `write(&$buffer)`

See executable source and call sites for the behavioral contract.

### `getLength()`

See executable source and call sites for the behavioral contract.

### `__construct($data)`

See executable source and call sites for the behavioral contract.

### `write(&$buffer)`

See executable source and call sites for the behavioral contract.

### `static getCode($c)`

See executable source and call sites for the behavioral contract.

### `__construct($data)`

See executable source and call sites for the behavioral contract.

### `write(&$buffer)`

See executable source and call sites for the behavioral contract.

### `__construct($mode, $data)`

See executable source and call sites for the behavioral contract.

### `getMode()`

See executable source and call sites for the behavioral contract.

### `getData()`

See executable source and call sites for the behavioral contract.

### `getLength()`

See executable source and call sites for the behavioral contract.

### `write(&$buffer)`

See executable source and call sites for the behavioral contract.

### `getLengthInBits($type)`

See executable source and call sites for the behavioral contract.

### `static init()`

See executable source and call sites for the behavioral contract.

### `static createNumArray($length)`

See executable source and call sites for the behavioral contract.

### `static glog($n)`

See executable source and call sites for the behavioral contract.

### `static gexp($n)`

See executable source and call sites for the behavioral contract.

### `__construct($num, $shift = 0)`

See executable source and call sites for the behavioral contract.

### `get($index)`

See executable source and call sites for the behavioral contract.

### `getLength()`

See executable source and call sites for the behavioral contract.

### `__toString()`

See executable source and call sites for the behavioral contract.

### `toString()`

See executable source and call sites for the behavioral contract.

### `toLogString()`

See executable source and call sites for the behavioral contract.

### `multiply($e)`

See executable source and call sites for the behavioral contract.

### `mod($e)`

See executable source and call sites for the behavioral contract.

### `__construct()`

See executable source and call sites for the behavioral contract.

### `getBuffer()`

See executable source and call sites for the behavioral contract.

### `getLengthInBits()`

See executable source and call sites for the behavioral contract.

### `__toString()`

See executable source and call sites for the behavioral contract.

### `get($index)`

See executable source and call sites for the behavioral contract.

### `put($num, $length)`

See executable source and call sites for the behavioral contract.

### `putBit($bit)`

See executable source and call sites for the behavioral contract.


Generated by `mystack docs:build`; do not hand-edit.

# PHJC API

Source: `library/PHJC.php`  
SHA-256: `757abc4b8b4a2536e3b0cdc2839e4a837348429c0965e7b070af4125664c580f`

Classes: `PHJC`

## Public methods

### `static fastUI(): void`

See executable source and call sites for the behavioral contract.

### `static ui(string $type, array $attr = [], string $content = ''): string`

See executable source and call sites for the behavioral contract.

### `static icon(string $name, string $style = ''): string`

See executable source and call sites for the behavioral contract.

### `static slot(string $name, ?string $content = null)`

See executable source and call sites for the behavioral contract.

### `static layout(string $title, string $bodyContent): string`

See executable source and call sites for the behavioral contract.

### `static clearCache(): bool`

ক্যাশ পরিষ্কার করার মেথড

### `static view(string $view, array $data = [], ?string $fragment = null): string`

মূল রেন্ডার মেথড

### `static includeView(string $view, array $data = []): string`

See executable source and call sites for the behavioral contract.

### `static startLoop($array): void`

See executable source and call sites for the behavioral contract.

### `static currentLoop(): object`

See executable source and call sites for the behavioral contract.

### `static endLoop(): void`

See executable source and call sites for the behavioral contract.

### `static share($key, $value = null): void`

See executable source and call sites for the behavioral contract.

### `static directive(string $name, callable $handler): void`

See executable source and call sites for the behavioral contract.

### `static minify(bool $state = true): void`

See executable source and call sites for the behavioral contract.

### `static metaPreset(string $type, array $data = []): void`

See executable source and call sites for the behavioral contract.

### `static breadcrumb(array $crumbs): void`

See executable source and call sites for the behavioral contract.

### `static reset()`

See executable source and call sites for the behavioral contract.

### `static head(array $data)`

See executable source and call sites for the behavioral contract.

### `static buildHead()`

See executable source and call sites for the behavioral contract.

### `static newHTML($tag = null, $attributes = [], $content = '')`

See executable source and call sites for the behavioral contract.

### `static singleHTML($html = [])`

See executable source and call sites for the behavioral contract.

### `static mergeHTML(array $htmlParts)`

See executable source and call sites for the behavioral contract.

### `static p2j($php, $json = true)`

See executable source and call sites for the behavioral contract.

### `static h2p($html, $json = true, $echo = false, $pre = false)`

See executable source and call sites for the behavioral contract.

### `static css($rules)`

See executable source and call sites for the behavioral contract.

### `static countElements($input)`

See executable source and call sites for the behavioral contract.

### `static generateId($fullTag, $attributeString, $content)`

See executable source and call sites for the behavioral contract.

### `static import($type, $source, $location = 'head', $version = null)`

See executable source and call sites for the behavioral contract.

### `static header($content)`

See executable source and call sites for the behavioral contract.

### `static body($content, $bodyPram = [])`

See executable source and call sites for the behavioral contract.

### `static streamJS($thisPath = '', $stream = '', $rootPath = '')`

See executable source and call sites for the behavioral contract.

### `static newJS($js)`

See executable source and call sites for the behavioral contract.

### `static phjs($js): void`

See executable source and call sites for the behavioral contract.

### `static render_h(): string`

See executable source and call sites for the behavioral contract.

### `static render_c(): string`

See executable source and call sites for the behavioral contract.

### `static render_b(): string`

See executable source and call sites for the behavioral contract.

### `static render_j($state = true)`

See executable source and call sites for the behavioral contract.

### `static app(string $stream, callable $producer)`

See executable source and call sites for the behavioral contract.

### `static render(): string`

See executable source and call sites for the behavioral contract.

### `__call(string $name, array $arguments)`

See executable source and call sites for the behavioral contract.

### `static __callStatic(string $name, array $arguments)`

See executable source and call sites for the behavioral contract.

### `static set(string $varName, string $value, string $type = 'var'): string`

See executable source and call sites for the behavioral contract.

### `static op(string $f1, string $op, string $f2): string`

See executable source and call sites for the behavioral contract.

### `static get(string ...$varNames): string`

See executable source and call sites for the behavioral contract.

### `static endFun(): string`

See executable source and call sites for the behavioral contract.

### `static endCod(): string`

See executable source and call sites for the behavioral contract.


Generated by `mystack docs:build`; do not hand-edit.

# PHJS API

Source: `library/PHJS.php`  
SHA-256: `48d84a19b9fbe5bf998750e6ac9aa274c6f2b6f0a635c25c7938215ea221c2d7`

Classes: `PHJSExpression`, `PHJS`, `PHJS_Chain`

## Public methods

### `__construct(private readonly string $code)`

See executable source and call sites for the behavioral contract.

### `code(): string`

See executable source and call sites for the behavioral contract.

### `__toString(): string`

See executable source and call sites for the behavioral contract.

### `static assets(array $options = []): string`

Smart Asset Manager

### `static js(): PHJS_Chain`

Fluent Entry Point

### `static __callStatic($name, $args)`

Magic Static Caller for full JS support

### `static render(string $html): string`

Smart HTML Renderer

### `static parse(string $dsl): string`

See executable source and call sites for the behavioral contract.

### `static alpineData(string $name, array $obj): string`

See executable source and call sites for the behavioral contract.

### `static alpineStore(string $name, array $obj): string`

See executable source and call sites for the behavioral contract.

### `static alpineBind(string $name, array $obj): string`

See executable source and call sites for the behavioral contract.

### `static el(): string`

See executable source and call sites for the behavioral contract.

### `static refs(string $name = ''): string`

See executable source and call sites for the behavioral contract.

### `static store(string $name): string`

See executable source and call sites for the behavioral contract.

### `static watch(string $prop, string $callback): string`

See executable source and call sites for the behavioral contract.

### `static dispatch(string $event, array $detail = []): string`

See executable source and call sites for the behavioral contract.

### `static nextTick(string $callback): string`

See executable source and call sites for the behavioral contract.

### `static root(): string`

See executable source and call sites for the behavioral contract.

### `static data(): string`

See executable source and call sites for the behavioral contract.

### `static id(string $name): string`

See executable source and call sites for the behavioral contract.

### `static state_magic(): string`

See executable source and call sites for the behavioral contract.

### `static params_magic(): string`

See executable source and call sites for the behavioral contract.

### `static route_magic(): string`

See executable source and call sites for the behavioral contract.

### `static ui_magic(): string`

See executable source and call sites for the behavioral contract.

### `static os_magic(): string`

See executable source and call sites for the behavioral contract.

### `static t_magic(): string`

See executable source and call sites for the behavioral contract.

### `static router_magic(): string`

See executable source and call sites for the behavioral contract.

### `static clipboard_magic(): string`

See executable source and call sites for the behavioral contract.

### `static hxProcess(string $sel): string`

See executable source and call sites for the behavioral contract.

### `static hxTrigger(string $sel, string $event): string`

See executable source and call sites for the behavioral contract.

### `static hxAjax(string $method, string $url, string $target): string`

See executable source and call sites for the behavioral contract.

### `static hxRemove(string $sel): string`

See executable source and call sites for the behavioral contract.

### `static hxAddClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static hxRemoveClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static hxToggleClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static hxConfig(array $config): string`

See executable source and call sites for the behavioral contract.

### `static let($name, $value = null): string`

See executable source and call sites for the behavioral contract.

### `static log($msg): string`

See executable source and call sites for the behavioral contract.

### `static error($msg): string`

See executable source and call sites for the behavioral contract.

### `static warn($msg): string`

See executable source and call sites for the behavioral contract.

### `static table($msg): string`

See executable source and call sites for the behavioral contract.

### `static localSet(string $key, $val): string`

See executable source and call sites for the behavioral contract.

### `static localGet(string $key): string`

See executable source and call sites for the behavioral contract.

### `static localRemove(string $key): string`

See executable source and call sites for the behavioral contract.

### `static sessionSet(string $key, $val): string`

See executable source and call sites for the behavioral contract.

### `static sessionGet(string $key): string`

See executable source and call sites for the behavioral contract.

### `static cookieSet(string $name, string $value, int $days = 7): string`

See executable source and call sites for the behavioral contract.

### `static html(string $sel, string $html): string`

See executable source and call sites for the behavioral contract.

### `static text(string $sel, string $text): string`

See executable source and call sites for the behavioral contract.

### `static val(string $sel, $val): string`

See executable source and call sites for the behavioral contract.

### `static addClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static removeClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static toggleClass(string $sel, string $cls): string`

See executable source and call sites for the behavioral contract.

### `static css(string $sel, string $prop, string $val): string`

See executable source and call sites for the behavioral contract.

### `static attr(string $sel, string $attr, string $val): string`

See executable source and call sites for the behavioral contract.

### `static remove(string $sel): string`

See executable source and call sites for the behavioral contract.

### `static event(string $sel, string $evt, string $code): string`

See executable source and call sites for the behavioral contract.

### `static onReady(string $code): string`

See executable source and call sites for the behavioral contract.

### `static redirect(string $url): string`

See executable source and call sites for the behavioral contract.

### `static reload(): string`

See executable source and call sites for the behavioral contract.

### `static alert($msg): string`

See executable source and call sites for the behavioral contract.

### `static fetch(string $url, array $opts = []): string`

See executable source and call sites for the behavioral contract.

### `static raw(string $code): string`

See executable source and call sites for the behavioral contract.

### `static appReady(string $code): string`

See executable source and call sites for the behavioral contract.

### `static appNavigate(string $url): string`

See executable source and call sites for the behavioral contract.

### `static appLink(string $url): string`

See executable source and call sites for the behavioral contract.

### `static appApi(string $url): string`

See executable source and call sites for the behavioral contract.

### `static appRoutePath(string $url = ''): string`

See executable source and call sites for the behavioral contract.

### `static appToast(string $msg, string $type = 'info'): string`

See executable source and call sites for the behavioral contract.

### `static appModal(string $id, string $action = 'open'): string`

See executable source and call sites for the behavioral contract.

### `static appProgress(bool $start = true): string`

See executable source and call sites for the behavioral contract.

### `static appTheme(string $name): string`

See executable source and call sites for the behavioral contract.

### `static appThemeToggle(): string`

See executable source and call sites for the behavioral contract.

### `static appValidate(string $selector): string`

See executable source and call sites for the behavioral contract.

### `static appCheck(string $selector, ?string $successMsg = null): string`

See executable source and call sites for the behavioral contract.

### `static appSeo(array $config): string`

See executable source and call sites for the behavioral contract.

### `static appI18n(string $lang): string`

See executable source and call sites for the behavioral contract.

### `static appStoreGet(string $name): string`

See executable source and call sites for the behavioral contract.

### `static appStoreSet(string $name, $value): string`

See executable source and call sites for the behavioral contract.

### `static appStoreDispatch(string $action, $payload = null): string`

See executable source and call sites for the behavioral contract.

### `static appDbStorageSet(string $key, $val): string`

See executable source and call sites for the behavioral contract.

### `static appDbStorageGet(string $key): string`

See executable source and call sites for the behavioral contract.

### `static appDbStorageDel(string $key): string`

See executable source and call sites for the behavioral contract.

### `static appDbSync(string $namespace, string $url): string`

See executable source and call sites for the behavioral contract.

### `static appRequest(string $url, array $opts = []): string`

See executable source and call sites for the behavioral contract.

### `static appUpload(string $fileVar, string $endpoint, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appSearch(string $indexName, string $query): string`

See executable source and call sites for the behavioral contract.

### `static appSearchIndex(string $indexName, array $data): string`

See executable source and call sites for the behavioral contract.

### `static appHardware(string $type, string $action = 'connect', array $args = []): string`

See executable source and call sites for the behavioral contract.

### `static appDrmProtect(string $selector, array $config = []): string`

See executable source and call sites for the behavioral contract.

### `static appFsRead(string $accept = '.txt,.json,.md'): string`

See executable source and call sites for the behavioral contract.

### `static appFsSave(string $content, string $defaultName = 'export.txt'): string`

See executable source and call sites for the behavioral contract.

### `static appMediaInit(string $selector, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appChartInit(string $selector, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appWorker(string $task, array $data = []): string`

See executable source and call sites for the behavioral contract.

### `static appInspector(): string`

See executable source and call sites for the behavioral contract.

### `static appPalette(): string`

See executable source and call sites for the behavioral contract.

### `static appA11yTrap(string $selector): string`

See executable source and call sites for the behavioral contract.

### `static appDesignSet(string $name, string $value): string`

See executable source and call sites for the behavioral contract.

### `static appDesignGet(string $name): string`

See executable source and call sites for the behavioral contract.

### `static appTimeFormat(string $dateVar = 'new Date()', string $pattern = 'YYYY-MM-DD HH:mm:ss'): string`

See executable source and call sites for the behavioral contract.

### `static appTimeAgo(string $dateVar): string`

See executable source and call sites for the behavioral contract.

### `static appAuthTotp(string $secret, array $options = []): string`

Local enrollment-preview helper only; server-side PHTP must verify codes.

### `static appOAuthStart(string $url, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appOAuthCallback(array $result = [], array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appTwoFactorSubmit(string $endpoint, string $codeExpression, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appPaymentStart(string $endpoint, array $data = [], array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appPaymentStatus(string $endpoint, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appHeroUpdate(string $selector): string`

See executable source and call sites for the behavioral contract.

### `static appAnimateTo(string $selector, array $props, array $options = []): string`

See executable source and call sites for the behavioral contract.

### `static appAnimateSpring(string $selector, array $props): string`

See executable source and call sites for the behavioral contract.

### `static appFontLoad(string $name, string $url): string`

See executable source and call sites for the behavioral contract.

### `static appAi(string $prompt, array $opts = []): string`

See executable source and call sites for the behavioral contract.

### `static appXrInit(array $opts = []): string`

See executable source and call sites for the behavioral contract.

### `static appPwaEnable(array $opts = []): string`

See executable source and call sites for the behavioral contract.

### `static appHydrate(): string`

See executable source and call sites for the behavioral contract.

### `static expr(string $code): PHJSExpression`

Mark trusted input as executable JavaScript instead of a JS string.

### `static value(mixed $value): string`

Translate a PHP value into a JavaScript value.

### `static translate(mixed $value): string`

See executable source and call sites for the behavioral contract.

### `static arrayValue(array $values): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static object(array|object $values): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static template(string $template, array $values = []): PHJSExpression`

Build a JavaScript template literal.

### `static statement(string|PHJSExpression $code): string`

See executable source and call sites for the behavioral contract.

### `static program(mixed ...$parts): string`

See executable source and call sites for the behavioral contract.

### `static compile(mixed $definition): string`

See executable source and call sites for the behavioral contract.

### `static module(array $definitions): string`

See executable source and call sites for the behavioral contract.

### `static build(mixed $definition): string`

Declarative JavaScript compiler.

### `static arrow(array|string $params, mixed $body, bool $async = false, bool $expression = false): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static functionDef(string $name, array $params, mixed $body, bool $async = false, bool $generator = false): string`

See executable source and call sites for the behavioral contract.

### `static assign(string $target, mixed $value, string $operator = '='): string`

See executable source and call sites for the behavioral contract.

### `static returnValue(mixed $value = null): string`

See executable source and call sites for the behavioral contract.

### `static throwValue(mixed $value): string`

See executable source and call sites for the behavioral contract.

### `static awaitValue(mixed $value): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static invoke(string|PHJSExpression $callable, mixed ...$args): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static construct(string $className, mixed ...$args): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static dynamicImport(string $from): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static ternary(mixed $test, mixed $truthy, mixed $falsy): PHJSExpression`

See executable source and call sites for the behavioral contract.

### `static ifBlock(mixed $test, mixed $then, mixed $else = null): string`

See executable source and call sites for the behavioral contract.

### `static forOf(string $value, mixed $iterable, mixed $body, string $key = ''): string`

See executable source and call sites for the behavioral contract.

### `static whileBlock(mixed $test, mixed $body): string`

See executable source and call sites for the behavioral contract.

### `static doWhileBlock(mixed $body, mixed $test): string`

See executable source and call sites for the behavioral contract.

### `static forBlock(mixed $init, mixed $test, mixed $update, mixed $body): string`

See executable source and call sites for the behavioral contract.

### `static switchBlock(mixed $test, array $cases, mixed $default = null): string`

See executable source and call sites for the behavioral contract.

### `static tryCatch(mixed $try, string $catch, mixed $catchBody, mixed $finally = null): string`

See executable source and call sites for the behavioral contract.

### `static classDef(string $name, array $methods, ?string $extends = null): string`

See executable source and call sites for the behavioral contract.

### `static importModule(string $from, ?string $default = null, array $named = []): string`

See executable source and call sites for the behavioral contract.

### `static exportDefault(mixed $value): string`

See executable source and call sites for the behavioral contract.

### `static exportNamed(array $names): string`

See executable source and call sites for the behavioral contract.

### `static call(string $name, ...$args): string`

Explicit JS Function Caller

### `static script(string $js, bool $module = false): string`

Wrap JS in Script Tag

### `static moduleScript(array|string $definition): string`

See executable source and call sites for the behavioral contract.

### `static gen(string $humanLanguage): string`

See executable source and call sites for the behavioral contract.

### `__call($name, $args)`

See executable source and call sites for the behavioral contract.

### `render(): string`

See executable source and call sites for the behavioral contract.

### `__toString(): string`

See executable source and call sites for the behavioral contract.

## Public functions

- `tjs($dsl)`
- `phjs($human)`

Generated by `mystack docs:build`; do not hand-edit.

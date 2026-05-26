<?php
/**
 * Minimal Joomla CMS stubs for yt-builder-mcp unit tests.
 *
 * Provides the smallest subset of Joomla runtime symbols that the
 * platform-joomla module touches:
 *   - \Joomla\CMS\Factory (Factory::getContainer())
 *   - \Joomla\Database\DatabaseInterface (read-only marker)
 *   - \Joomla\Database\ParameterType (binding-type constants)
 *   - \Joomla\Database\DatabaseDriver-shaped mock with query() chain
 *   - \Joomla\CMS\Plugin\CMSPlugin (extension base class for InstallerScript tests)
 *   - \Joomla\CMS\Plugin\PluginHelper (isEnabled/getPlugin lookups)
 *   - \Joomla\CMS\Installer\InstallerAdapter / InstallerScript (installer tests)
 *   - \Joomla\CMS\Language\Text (translation passthrough)
 *
 * Lifted and trimmed from /Users/getimo/Projekte/wootsup/tests/Support/Joomla/Stubs/JoomlaCmsStubs.php
 * (api-mapper, ~1255 LoC) — only the surface used by yt-builder-mcp is preserved.
 *
 * Load via require_once from tests/php/joomla-bootstrap.php — DO NOT
 * require this file directly from a test class.
 *
 * @package WootsUp\BuilderMcp\Tests\Support
 */

declare(strict_types=1);

// =========================================================================
// Global Mock Classes (no namespace) — referenced by namespaced thin wrappers
// =========================================================================

namespace {
    if (defined('YTB_JOOMLA_STUBS_LOADED')) {
        return;
    }
    define('YTB_JOOMLA_STUBS_LOADED', true);

    if (!defined('JVERSION')) {
        define('JVERSION', '5.2.0');
    }

    /**
     * Mock query-builder. Records calls fluently; assert via inspection.
     */
    if (!class_exists('MockJoomlaQuery')) {
        class MockJoomlaQuery
        {
            public string $type = '';
            /** @var array<int, string> */
            public array $selects = [];
            public string $from = '';
            /** @var array<int, string> */
            public array $wheres = [];
            /** @var array<int, string> */
            public array $sets = [];
            public string $update = '';
            public string $delete = '';
            public string $insert = '';
            /** @var array<int, string> */
            public array $columns = [];
            /** @var array<int, string> */
            public array $values = [];
            /** @var array<string, mixed> */
            public array $binds = [];

            /**
             * Raw SQL set via setQuery() on the QUERY object. Wave-7
             * deploy-fix: production storage code now does
             * `$db->createQuery()->setQuery($rawSql)->bind(...)` (binding on
             * the DatabaseQuery, NOT the driver — the driver has no bind()).
             * The mock records the raw SQL here so simulateWrite() /
             * deriveLoadResult() can still identify the op + table.
             */
            public string $rawSql = '';

            /** Mirror DatabaseQuery::setQuery() — accept a raw SQL string. */
            public function setQuery(mixed $query): self
            {
                if (is_string($query)) {
                    $this->rawSql = $query;
                }
                return $this;
            }

            public function select(string|array $col): self
            {
                if (is_array($col)) {
                    foreach ($col as $c) {
                        $this->selects[] = (string) $c;
                    }
                } else {
                    $this->selects[] = $col;
                }
                return $this;
            }

            public function from(string $table): self
            {
                $this->from = $table;
                return $this;
            }

            public function where(string $condition): self
            {
                $this->wheres[] = $condition;
                return $this;
            }

            public function update(string $table): self
            {
                $this->type = 'update';
                $this->update = $table;
                return $this;
            }

            public function delete(string $table): self
            {
                $this->type = 'delete';
                $this->delete = $table;
                return $this;
            }

            public function insert(string $table): self
            {
                $this->type = 'insert';
                $this->insert = $table;
                return $this;
            }

            public function set(string $value): self
            {
                $this->sets[] = $value;
                return $this;
            }

            public function columns(array|string $cols): self
            {
                if (is_array($cols)) {
                    foreach ($cols as $c) {
                        $this->columns[] = (string) $c;
                    }
                } else {
                    $this->columns[] = $cols;
                }
                return $this;
            }

            public function values(string $vals): self
            {
                $this->values[] = $vals;
                return $this;
            }

            /**
             * Mirror Joomla `DatabaseQuery::bind()` — $value is taken BY
             * REFERENCE (J6 prepared-statement contract). This fidelity is
             * load-bearing: passing the RESULT OF AN ASSIGNMENT EXPRESSION
             * (`$db->...->bind(':element', $x = self::CONST, ...)`) raises a
             * fatal "Argument #2 ($value) could not be passed by reference",
             * exactly as real Joomla does on J6. The Wave-7 deploy-fix
             * pre-declares each bound value as an addressable variable; the
             * pre-fix inline-assignment pattern fatals here so the
             * JoomlaLayoutStorage L1-read regression test goes red against it.
             *
             * @param mixed $value Bound by reference — DO NOT change to by-value.
             */
            public function bind(string $key, mixed &$value, mixed $type = null): self
            {
                $this->binds[$key] = $value;
                return $this;
            }

            /**
             * Round-4 audit A3 P1: Wave 3.5 L2 storage layer uses the
             * QueryBuilder fluent methods `order()` + `setLimit()` for
             * paginated SELECT on `#__content`. Stub them as recorders so
             * the storage call-chain doesn't throw when test harnesses
             * lack a real DatabaseQuery implementation.
             */
            public string $orderBy = '';
            public int $limit = 0;
            public int $limitStart = 0;

            public function order(string $columns): self
            {
                $this->orderBy = $columns;
                return $this;
            }

            public function setLimit(int $limit, int $offset = 0): self
            {
                $this->limit = $limit;
                $this->limitStart = $offset;
                return $this;
            }
        }
    }

    /**
     * Generic Joomla DatabaseInterface-compatible mock. Per-test tweakable
     * via public statics; reset() restores defaults.
     */
    if (!class_exists('MockJoomlaDatabase')) {
        class MockJoomlaDatabase
        {
            /** @var mixed Result returned by loadResult() (override). */
            public static mixed $loadResultOverride = null;
            public static bool $useLoadResultOverride = false;
            /** @var array<int, array<string, mixed>>|null Result for loadAssocList() (override). */
            public static ?array $loadAssocListOverride = null;
            public static bool $useLoadAssocListOverride = false;
            /** @var array<string, mixed>|null Result for loadAssoc() (override). */
            public static ?array $loadAssocOverride = null;
            public static bool $useLoadAssocOverride = false;
            public static bool $throwException = false;
            public static bool $executeResult = true;
            /** @var int|null Override affected-rows count; null falls back to derived value. */
            public static ?int $affectedRowsOverride = null;
            /** @var string Driver — 'mysql' or 'postgresql'. */
            public static string $serverType = 'mysql';
            /** @var array<int, MockJoomlaQuery|string> Recorded queries. */
            public static array $executedQueries = [];
            /**
             * In-memory option-store backing. Keyed by table name then key.
             * @var array<string, array<string, string>>
             */
            public static array $tables = [];
            /** Last derived affected-rows from a simulated INSERT/UPDATE/DELETE. */
            private static int $lastAffectedRows = 1;
            private ?MockJoomlaQuery $query = null;
            /** @var mixed Last raw query set via setQuery(). */
            private mixed $rawQuery = null;
            private string $prefix = 'j_';

            public function getPrefix(): string
            {
                return $this->prefix;
            }

            public function getServerType(): string
            {
                return self::$serverType;
            }

            public function createQuery(): MockJoomlaQuery
            {
                $this->query = new MockJoomlaQuery();
                return $this->query;
            }

            public function getQuery(bool $new = false): MockJoomlaQuery
            {
                // J6-removed API — exposed only for forward-compat pin-test
                // verification. platform-joomla source MUST NOT call this.
                $this->query = new MockJoomlaQuery();
                return $this->query;
            }

            public function quoteName(string|array $name): string|array
            {
                if (is_array($name)) {
                    return array_map(fn ($n) => '`' . $n . '`', $name);
                }
                return '`' . $name . '`';
            }

            public function quote(mixed $value): string
            {
                if ($value === null) {
                    // Joomla 6 quote(null) returns '' (cookbook §5.14.4 QuoteNull
                    // gotcha). Tests pin this.
                    return "''";
                }
                return "'" . addslashes((string) $value) . "'";
            }

            public function setQuery(mixed $query): static
            {
                $this->rawQuery = $query;
                if ($query instanceof MockJoomlaQuery) {
                    $this->query = $query;
                }
                // Raw-SQL path (e.g. `setQuery('DROP TABLE IF EXISTS …')`):
                // the driver accepts a raw string and execute() runs it, but
                // it carries NO bound params and NO query object — Wave-7
                // fidelity fix: we deliberately do NOT attach a fresh
                // MockJoomlaQuery here, because real Joomla's DatabaseDriver
                // has NO bind() (see the removed-method note below). The
                // simulateWrite() / deriveLoadResult() raw-SQL parsers read
                // $this->rawQuery directly for this path.
                self::$executedQueries[] = $query;
                return $this;
            }

            /**
             * DELIBERATELY ABSENT: real Joomla's `DatabaseDriver`
             * (MysqliDriver / PgsqlDriver / …) has NO `bind()` method —
             * binding lives ONLY on `DatabaseQuery`. A `bind()` stub on the
             * driver used to mask the headline Wave-7 production bug
             * (`$db->setQuery($rawSql)->bind(...)` fataled with "Call to
             * undefined method MysqliDriver::bind()", swallowed by the
             * catch → every option/lock/transient write silently failed →
             * all Bearer auth broke). With no driver bind(), the pre-fix
             * pattern raises an `Error` in the suite exactly as in
             * production, so the OptionStore/TransientStore/StateLock
             * regression tests go red against it. Bind on the QUERY object
             * (`$db->createQuery()->setQuery($sql)->bind(...)`) — the fixed
             * pattern — which routes through MockJoomlaQuery::bind().
             */

            public function loadResult(): mixed
            {
                if (self::$throwException) {
                    throw new \RuntimeException('MockJoomlaDatabase: throwException flag set');
                }
                if (self::$useLoadResultOverride) {
                    // Closure override: invoke with the current query so the
                    // test can dispatch per query-shape (extension_id vs
                    // custom_data vs option-store `:key`). Backward-compat:
                    // non-callable override returns verbatim.
                    if (self::$loadResultOverride instanceof \Closure) {
                        return (self::$loadResultOverride)($this->query);
                    }
                    return self::$loadResultOverride;
                }
                // Derive from in-memory tables: best-effort lookup matching
                // JoomlaOptionStore::get() SELECT option_value WHERE option_key = :key.
                return $this->deriveLoadResultFromQuery();
            }

            public function loadAssocList(): ?array
            {
                if (self::$throwException) {
                    throw new \RuntimeException('MockJoomlaDatabase: throwException flag set');
                }
                if (self::$useLoadAssocListOverride) {
                    return self::$loadAssocListOverride;
                }
                return [];
            }

            public function loadAssoc(): ?array
            {
                if (self::$throwException) {
                    throw new \RuntimeException('MockJoomlaDatabase: throwException flag set');
                }
                if (self::$useLoadAssocOverride) {
                    return self::$loadAssocOverride;
                }
                return null;
            }

            public function execute(): bool
            {
                if (self::$throwException) {
                    throw new \RuntimeException('MockJoomlaDatabase: throwException flag set');
                }
                // Simulate INSERT / UPDATE / DELETE on the in-memory table.
                self::$lastAffectedRows = $this->simulateWrite();
                return self::$executeResult;
            }

            public function getAffectedRows(): int
            {
                return self::$affectedRowsOverride ?? self::$lastAffectedRows;
            }

            /**
             * Best-effort: parse the queued raw-SQL or query-builder to
             * decide what to return from loadResult(). Recognises the
             * JoomlaOptionStore SELECT pattern with `:key` bound param.
             */
            private function deriveLoadResultFromQuery(): mixed
            {
                $key = null;
                if ($this->query instanceof MockJoomlaQuery && isset($this->query->binds[':key'])) {
                    $key = (string) $this->query->binds[':key'];
                }
                if ($key === null) {
                    return null;
                }
                // Pick the first table whose row map contains the key.
                foreach (self::$tables as $rows) {
                    if (array_key_exists($key, $rows)) {
                        return $rows[$key];
                    }
                }
                return null;
            }

            /**
             * Best-effort: identify the operation + table from the raw
             * SQL string and mutate the in-memory table.
             *
             * Returns the simulated affected-rows count (matches MySQL
             * `INSERT IGNORE` semantics: 0 if key already exists, 1 if
             * inserted).
             */
            private function simulateWrite(): int
            {
                // Wave-7 deploy-fix: production storage now binds raw SQL on
                // the QUERY object (`$db->createQuery()->setQuery($sql)->bind()`
                // then `$db->setQuery($query)`). When the attached query carries
                // a rawSql string, parse THAT (INSERT IGNORE / ON DUPLICATE /
                // DELETE detection) so atomicity semantics (lock acquire,
                // add-if-absent) are simulated correctly — falling through to
                // the generic raw-SQL parser below.
                if (
                    !is_string($this->rawQuery)
                    && $this->query instanceof MockJoomlaQuery
                    && $this->query->rawSql !== ''
                ) {
                    $sql = $this->query->rawSql;
                    // fall through to the shared raw-SQL parser by reassigning.
                    $this->rawQuery = $sql;
                }

                // Builder-style writes (createQuery()->delete(...)->where(...)->bind(...))
                // — derive op + table from the MockJoomlaQuery rather than parsing SQL.
                if (!is_string($this->rawQuery) && $this->query instanceof MockJoomlaQuery) {
                    $key = isset($this->query->binds[':key'])
                        ? (string) $this->query->binds[':key']
                        : null;
                    $value = self::deriveBoundValue($this->query);
                    $table = $this->query->delete !== ''
                        ? $this->extractTableName($this->query->delete)
                        : ($this->query->update !== ''
                            ? $this->extractTableName($this->query->update)
                            : ($this->query->insert !== ''
                                ? $this->extractTableName($this->query->insert)
                                : ($this->query->from !== '' ? $this->extractTableName($this->query->from) : null)));
                    if ($table === null || $key === null) {
                        return 1;
                    }
                    if (!isset(self::$tables[$table])) {
                        self::$tables[$table] = [];
                    }
                    if ($this->query->delete !== '') {
                        if (array_key_exists($key, self::$tables[$table])) {
                            unset(self::$tables[$table][$key]);
                            return 1;
                        }
                        return 0;
                    }
                    // For builder-style insert/update without a raw-SQL hint
                    // we treat as upsert.
                    self::$tables[$table][$key] = (string) ($value ?? '');
                    return 1;
                }

                if (!is_string($this->rawQuery)) {
                    return 1;
                }
                $sql = (string) $this->rawQuery;
                $key = null;
                $value = null;
                if ($this->query instanceof MockJoomlaQuery) {
                    if (isset($this->query->binds[':key'])) {
                        $key = (string) $this->query->binds[':key'];
                    }
                    $value = self::deriveBoundValue($this->query);
                }
                // Extract table name (between back-ticks).
                $table = null;
                if (preg_match('/`([^`]+)`/', $sql, $m)) {
                    $table = $m[1];
                }
                if ($table === null) {
                    return 1;
                }
                if (!isset(self::$tables[$table])) {
                    self::$tables[$table] = [];
                }

                $isInsertIgnore = (bool) preg_match('/INSERT IGNORE/i', $sql);
                $isInsertOnConflict = (bool) preg_match('/ON CONFLICT.*DO NOTHING/i', $sql);
                $isUpsert = (bool) preg_match('/ON DUPLICATE KEY UPDATE|ON CONFLICT.*DO UPDATE/i', $sql);
                $isDelete = (bool) preg_match('/^\s*DELETE\b/i', $sql);

                if ($isDelete && $key !== null) {
                    if (array_key_exists($key, self::$tables[$table])) {
                        unset(self::$tables[$table][$key]);
                        return 1;
                    }
                    return 0;
                }

                if ($key === null) {
                    return 1;
                }

                if ($isInsertIgnore || $isInsertOnConflict) {
                    // Atomic create-if-absent: 0 if already exists, 1 if new.
                    if (array_key_exists($key, self::$tables[$table])) {
                        return 0;
                    }
                    self::$tables[$table][$key] = (string) ($value ?? '');
                    return 1;
                }

                if ($isUpsert) {
                    self::$tables[$table][$key] = (string) ($value ?? '');
                    return 1;
                }

                // Plain INSERT / UPDATE / other.
                self::$tables[$table][$key] = (string) ($value ?? '');
                return 1;
            }

            public function transactionStart(): void {}
            public function transactionCommit(): void {}
            public function transactionRollback(): void {}

            /** Strip back-ticks from quoteName(...) output. */
            private function extractTableName(string $quoted): string
            {
                return trim($quoted, '`"\'');
            }

            /**
             * Resolve the bound "value" column across stores: JoomlaOptionStore
             * binds `:value`, JoomlaTransientStore binds `:payload`. Returning
             * whichever is present lets the in-memory backing table round-trip
             * faithfully for both stores (so a get() after set() reads back the
             * exact bytes the bind carried).
             */
            private static function deriveBoundValue(MockJoomlaQuery $query): ?string
            {
                if (isset($query->binds[':value'])) {
                    return (string) $query->binds[':value'];
                }
                if (isset($query->binds[':payload'])) {
                    return (string) $query->binds[':payload'];
                }
                return null;
            }

            public static function reset(): void
            {
                self::$loadResultOverride        = null;
                self::$useLoadResultOverride     = false;
                self::$loadAssocListOverride     = null;
                self::$useLoadAssocListOverride  = false;
                self::$loadAssocOverride         = null;
                self::$useLoadAssocOverride      = false;
                self::$throwException            = false;
                self::$executeResult             = true;
                self::$affectedRowsOverride      = null;
                self::$serverType                = 'mysql';
                self::$executedQueries           = [];
                self::$tables                    = [];
                self::$lastAffectedRows          = 1;
            }
        }
    }

    /**
     * Joomla DI container substitute. Returns whatever is registered via
     * the static set() helper.
     */
    if (!class_exists('MockJoomlaContainer')) {
        class MockJoomlaContainer
        {
            /** @var array<string, object> */
            private static array $services = [];

            public static function register(string $id, object $service): void
            {
                self::$services[$id] = $service;
            }

            public static function reset(): void
            {
                self::$services = [];
            }

            public function get(string $id): object
            {
                if (isset(self::$services[$id])) {
                    return self::$services[$id];
                }
                // Default: hand out a typed-bridge mock for the database id
                // (subclass implements \Joomla\Database\DatabaseInterface).
                if ($id === 'Joomla\\Database\\DatabaseInterface') {
                    if (class_exists('MockJoomlaDatabaseTypedBridge', false)) {
                        $db = new \MockJoomlaDatabaseTypedBridge();
                    } else {
                        $db = new MockJoomlaDatabase();
                    }
                    self::$services[$id] = $db;
                    return $db;
                }
                throw new \RuntimeException('MockJoomlaContainer: unknown service id: ' . $id);
            }

            public function has(string $id): bool
            {
                return isset(self::$services[$id]);
            }
        }
    }

    /**
     * Joomla Factory substitute. Reset via reset() between tests.
     */
    if (!class_exists('MockJoomlaFactory')) {
        class MockJoomlaFactory
        {
            private static ?MockJoomlaContainer $container = null;
            private static ?object $application = null;

            public static function getContainer(): MockJoomlaContainer
            {
                if (self::$container === null) {
                    self::$container = new MockJoomlaContainer();
                }
                return self::$container;
            }

            public static function getApplication(): object
            {
                if (self::$application === null) {
                    self::$application = new class implements \Joomla\CMS\Application\CMSApplicationInterface {
                        /** @var array<int, array{message: string, type: string}> */
                        public array $messages = [];
                        /**
                         * Headers captured by {@see JoomlaJsonResponse::send()}.
                         * Keyed by header-name (last-write-wins, matching the
                         * `$replace = true` call-shape the helper uses). Lets
                         * behavioural REST controller tests assert on the
                         * emitted status code + Content-Type without a real
                         * ApiApplication.
                         *
                         * @var array<string, string>
                         */
                        public array $headers = [];

                        public function enqueueMessage(string $msg, string $type = 'message'): void
                        {
                            $this->messages[] = ['message' => $msg, 'type' => $type];
                        }
                        public function getIdentity(): ?object
                        {
                            return null;
                        }

                        /**
                         * Mirror {@see \Joomla\CMS\Application\CMSApplication::setHeader()}.
                         * Records the header so JoomlaJsonResponse-driven
                         * behavioural tests can read back the 'status' code +
                         * Content-Type the controller emitted.
                         */
                        public function setHeader(string $name, string $value, bool $replace = false): void
                        {
                            $this->headers[$name] = $value;
                        }
                    };
                }
                return self::$application;
            }

            public static function reset(): void
            {
                self::$container = null;
                self::$application = null;
                MockJoomlaContainer::reset();
                MockJoomlaDatabase::reset();
            }
        }
    }
}

// =========================================================================
// Namespaced thin wrappers — extend the global mocks
// =========================================================================

namespace Joomla\CMS {
    if (!class_exists(Factory::class)) {
        class Factory extends \MockJoomlaFactory {}
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface
        {
            public function createQuery(): mixed;
            public function setQuery(mixed $query): static;
            public function execute(): bool;
            public function getAffectedRows(): int;
        }
    }

    if (!class_exists(ParameterType::class)) {
        final class ParameterType
        {
            public const STRING       = \PDO::PARAM_STR;
            public const INTEGER      = \PDO::PARAM_INT;
            public const BOOLEAN      = \PDO::PARAM_BOOL;
            public const LARGE_OBJECT = \PDO::PARAM_LOB;
            public const NULL         = \PDO::PARAM_NULL;
        }
    }
}

// =========================================================================
// Tag the global MockJoomlaDatabase as a DatabaseInterface so production
// `: DatabaseInterface` return-type hints accept it. Done in a second
// namespace pass so the interface from Joomla\Database is in scope.
// =========================================================================

namespace {
    if (!class_exists('MockJoomlaDatabaseTypedBridge', false)) {
        /**
         * Marker subclass: same behaviour as MockJoomlaDatabase, but
         * additionally implements \Joomla\Database\DatabaseInterface so
         * the JoomlaOptionStore / JoomlaStateLock return-types accept it.
         */
        class MockJoomlaDatabaseTypedBridge extends MockJoomlaDatabase implements \Joomla\Database\DatabaseInterface
        {
        }
    }
}

namespace Joomla\CMS\Plugin {
    if (!class_exists(CMSPlugin::class)) {
        abstract class CMSPlugin
        {
            /** @var object Joomla dispatcher (mocked as plain object). */
            protected object $dispatcher;
            /** @var array<string, mixed> Plugin params (mocked map). */
            protected array $params = [];

            public function __construct($subject, array $config = [])
            {
                $this->dispatcher = (object) [];
                if (isset($config['params']) && is_array($config['params'])) {
                    $this->params = $config['params'];
                }
            }
        }
    }

    if (!class_exists(PluginHelper::class)) {
        class PluginHelper
        {
            public static bool $isEnabled = true;
            private static ?object $mockPlugin = null;

            public static function setMockPlugin(?object $plugin): void
            {
                self::$mockPlugin = $plugin;
            }

            public static function getPlugin(string $type, string $element): ?object
            {
                return self::$mockPlugin;
            }

            public static function isEnabled(string $type, string $element): bool
            {
                return self::$isEnabled;
            }
        }
    }
}

namespace Joomla\CMS\Installer {
    if (!class_exists(InstallerAdapter::class)) {
        class InstallerAdapter
        {
            public ?object $parent = null;

            public function getParent(): object
            {
                if ($this->parent === null) {
                    $this->parent = new class {
                        public string $sourcePath = '/tmp/ytb-mock-install';
                        public function getPath(string $name): string
                        {
                            return $this->sourcePath;
                        }
                    };
                }
                return $this->parent;
            }
        }
    }

    if (!class_exists(InstallerScript::class)) {
        abstract class InstallerScript
        {
            /** @var string */
            protected $minimumJoomla = '5.0';
            /** @var string */
            protected $minimumPhp = '8.2';

            public function preflight($type, $parent): bool
            {
                return true;
            }
        }
    }
}

namespace Joomla\CMS\Language {
    if (!class_exists(Text::class)) {
        class Text
        {
            public static function _(string $string): string
            {
                return $string;
            }

            public static function sprintf(string $string, ...$args): string
            {
                return vsprintf($string, $args);
            }
        }
    }
}

namespace Joomla\CMS\Application {
    if (!interface_exists(CMSApplicationInterface::class)) {
        /**
         * Minimal stub of {@see \Joomla\CMS\Application\CMSApplicationInterface}
         * covering only the methods this codebase actually invokes through the
         * interface contract:
         *   - `enqueueMessage()` — used by {@see JoomlaEncryptionKeyResolver}.
         *   - `setHeader()`      — used by {@see JoomlaJsonResponse::send()}.
         * The MockJoomlaFactory app (instantiated below) implements both, so
         * `implements CMSApplicationInterface` lets it satisfy production
         * `CMSApplicationInterface` typehints (e.g. JsonResponse::send) at the
         * type-system level under PHPUnit, faithful to the real driver
         * contract.
         */
        interface CMSApplicationInterface
        {
            public function enqueueMessage(string $msg, string $type = 'message'): void;

            public function setHeader(string $name, string $value, bool $replace = false): void;
        }
    }
}

namespace Joomla\CMS {
    /**
     * Mirror of {@see \Joomla\CMS\Version}. The api-application
     * HealthController reads MAJOR/MINOR/PATCH off these class constants to
     * surface `cms_version` in the Bearer-augmented payload. Stubbed with
     * deterministic 5.2.0 values so the BEHAVIOURAL health-payload test can
     * assert the field renders without a real Joomla bootstrap.
     */
    if (!class_exists(Version::class)) {
        class Version
        {
            public const MAJOR_VERSION = 5;
            public const MINOR_VERSION = 2;
            public const PATCH_VERSION = 0;
        }
    }
}

namespace Joomla\CMS\Uri {
    /**
     * Minimal mirror of {@see \Joomla\CMS\Uri\Uri}. HealthController casts
     * `Uri::root()` to a string for the `site_url` field; the stub returns a
     * deterministic test root so the behavioural assertion is stable.
     */
    if (!class_exists(Uri::class)) {
        class Uri
        {
            public static string $root = 'https://joomla.test/';

            public static function root(bool $pathonly = false, ?string $path = null): string
            {
                return self::$root;
            }

            public static function getInstance(string $uri = 'SERVER'): self
            {
                return new self();
            }

            public function __toString(): string
            {
                return self::$root;
            }
        }
    }
}

namespace Joomla\CMS\Access\Exception {
    /**
     * Mirror of {@see \Joomla\CMS\Access\Exception\NotAllowed}. The real
     * class extends RuntimeException with a 403 default code; the admin
     * capability gate ({@see WootsUp\BuilderMcp\Platform\Joomla\Auth\JoomlaAdminAccess})
     * throws it when an unauthorised identity hits the dashboard / key tasks.
     * Stubbed so the deny-path test can assert on the thrown type + code.
     */
    if (!class_exists(NotAllowed::class)) {
        class NotAllowed extends \RuntimeException
        {
            public function __construct(string $message = '', int $code = 403, ?\Throwable $previous = null)
            {
                parent::__construct($message, $code, $previous);
            }
        }
    }
}

// =========================================================================
// Router / Event stubs — power the BEHAVIOURAL route-registration tests
// (R8c-A3). These mirror the real Joomla framework signatures closely
// enough to instantiate the runtime-autoloaded `plg_webservices_ytbmcp`
// Extension and capture the Route OBJECTS it registers, so assertions run
// against real objects rather than the plugin's source text.
// =========================================================================

namespace Joomla\Router {
    /**
     * Mirror of {@see \Joomla\Router\Route}. The real class stores
     * methods/pattern/controller/rules/defaults via the constructor and
     * exposes them through getters. This stub records the same five inputs
     * faithfully so the route-registration test can assert on each Route's
     * HTTP methods, URL pattern, controller-task token, and defaults
     * (notably `component` + `public`).
     *
     * Real ctor (Joomla 4/5/6): __construct(array $methods, string $pattern,
     * mixed $controller, array $rules = [], array $defaults = []).
     */
    if (!class_exists(Route::class)) {
        class Route
        {
            /** @var array<int, string> */
            private array $methods;
            private string $pattern;
            /** @var mixed Controller-task token (e.g. 'sources.get'). */
            private mixed $controller;
            /** @var array<string, mixed> Regex rules for route vars. */
            private array $rules;
            /** @var array<string, mixed> Route defaults (component/public/format). */
            private array $defaults;

            /**
             * @param array<int, string>    $methods
             * @param array<string, mixed>  $rules
             * @param array<string, mixed>  $defaults
             */
            public function __construct(
                array $methods,
                string $pattern,
                mixed $controller,
                array $rules = [],
                array $defaults = []
            ) {
                $this->methods    = $methods;
                $this->pattern    = $pattern;
                $this->controller = $controller;
                $this->rules      = $rules;
                $this->defaults   = $defaults;
            }

            /** @return array<int, string> */
            public function getMethods(): array
            {
                return $this->methods;
            }

            public function getPattern(): string
            {
                return $this->pattern;
            }

            public function getController(): mixed
            {
                return $this->controller;
            }

            /** @return array<string, mixed> */
            public function getRules(): array
            {
                return $this->rules;
            }

            /** @return array<string, mixed> */
            public function getDefaults(): array
            {
                return $this->defaults;
            }
        }
    }
}

namespace Joomla\CMS\Router {
    /**
     * Mirror of {@see \Joomla\CMS\Router\ApiRouter}. Captures every Route
     * passed to addRoutes() so the test can inspect the registered table.
     * The real ApiRouter merges the routes into its internal map; for the
     * test we only need to capture them.
     */
    if (!class_exists(ApiRouter::class)) {
        class ApiRouter
        {
            /** @var array<int, \Joomla\Router\Route> Captured route objects. */
            public array $captured = [];

            /** @var int Number of times addRoutes() was invoked. */
            public int $addRoutesCalls = 0;

            /**
             * @param array<int, \Joomla\Router\Route> $routes
             */
            public function addRoutes(array $routes): void
            {
                $this->addRoutesCalls++;
                foreach ($routes as $route) {
                    $this->captured[] = $route;
                }
            }
        }
    }
}

namespace Joomla\Event {
    /**
     * Minimal {@see \Joomla\Event\Event} substitute. Carries a named
     * argument map; the webservices plugin reads the ApiRouter from the
     * 'subject' (and 'router') key via getArguments().
     */
    if (!class_exists(Event::class)) {
        class Event
        {
            private string $name;
            /** @var array<string, mixed> */
            private array $arguments;

            /** @param array<string, mixed> $arguments */
            public function __construct(string $name, array $arguments = [])
            {
                $this->name      = $name;
                $this->arguments = $arguments;
            }

            public function getName(): string
            {
                return $this->name;
            }

            /** @return array<string, mixed> */
            public function getArguments(): array
            {
                return $this->arguments;
            }

            public function getArgument(string $name, mixed $default = null): mixed
            {
                return $this->arguments[$name] ?? $default;
            }
        }
    }

    if (!interface_exists(SubscriberInterface::class)) {
        interface SubscriberInterface
        {
            /** @return array<string, string> */
            public static function getSubscribedEvents(): array;
        }
    }
}

namespace Joomla\CMS\MVC\Controller {
    /**
     * Minimal {@see \Joomla\CMS\MVC\Controller\BaseController} substitute.
     * Exists ONLY so the runtime-autoloaded com_ytbmcp controllers (which
     * `extend AbstractApiController extends BaseController`) and the
     * public-tier controllers (HealthController / PickupController /
     * EtagController) can be CLASS-LOADED for reflection-based
     * controller-task resolution. We never instantiate them in tests, so
     * the constructor/runtime surface is intentionally a no-op.
     */
    if (!class_exists(BaseController::class)) {
        abstract class BaseController
        {
            /** @var object|null Joomla input bag (unused in reflection tests). */
            protected ?object $input = null;

            public function __construct($config = [])
            {
            }
        }
    }
}

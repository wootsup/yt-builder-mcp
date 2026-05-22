<?php
/**
 * PageQuery — listing + meta-extraction for templates.
 *
 * Wave 2 Task 2.2. PageQuery is the pure-PHP layer between LayoutReader
 * and the PagesController REST handlers. Unit-tested in isolation;
 * REST round-trip coverage lives in tests/php/integration/Pages.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\Pages;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\Pages\PageQuery;
use WootsUp\BuilderMcp\State\LayoutReader;

#[CoversClass(PageQuery::class)]
final class PageQueryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['ytb_test_options'] = [];
    }

    private function seedTwoTemplates(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'library' => [],
            'templates' => [
                'bFIb-syj' => [
                    'name' => 'Home',
                    'title' => 'Welcome Home',
                    'layout' => ['type' => 'layout', 'children' => []],
                ],
                'Fp2ntvJd' => [
                    'name' => 'About',
                    'layout' => ['type' => 'layout', 'children' => []],
                ],
            ],
        ];
    }

    public function test_list_returns_meta_for_each_template(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());

        $list = $query->list();
        self::assertCount(2, $list);

        $ids = array_column($list, 'id');
        self::assertContains('bFIb-syj', $ids);
        self::assertContains('Fp2ntvJd', $ids);
    }

    public function test_list_includes_name_when_present(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        $list = $query->list();

        $byId = array_column($list, null, 'id');
        self::assertSame('Home', $byId['bFIb-syj']['name']);
        self::assertSame('Welcome Home', $byId['bFIb-syj']['title']);
    }

    public function test_list_omits_title_key_when_template_has_no_title(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        $list = $query->list();

        $byId = array_column($list, null, 'id');
        self::assertArrayNotHasKey('title', $byId['Fp2ntvJd']);
        self::assertSame('About', $byId['Fp2ntvJd']['name']);
    }

    // -------------------------------------------------------------
    // F-02 / F-08 — pages_list metadata enrichment.
    // -------------------------------------------------------------

    public function test_list_attaches_elements_count_recursively(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'name' => 'Big',
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'section', 'children' => [
                                ['type' => 'row', 'children' => [
                                    ['type' => 'column', 'children' => [
                                        ['type' => 'headline'],
                                        ['type' => 'image'],
                                    ]],
                                ]],
                            ]],
                            ['type' => 'image'],
                        ],
                    ],
                ],
                'empty' => [
                    'name' => 'Empty',
                    'layout' => ['type' => 'layout', 'children' => []],
                ],
            ],
        ];
        $query = new PageQuery(new LayoutReader());
        $byId = array_column($query->list(), null, 'id');
        // tpl: section + row + column + headline + image + image = 6
        self::assertSame(6, $byId['tpl']['elements_count']);
        self::assertSame(0, $byId['empty']['elements_count']);
    }

    public function test_list_emits_default_type_when_template_blob_has_none(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        foreach ($query->list() as $entry) {
            self::assertArrayHasKey('type', $entry);
            self::assertSame('template', $entry['type']);
        }
    }

    public function test_list_surfaces_label_alias_of_name(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        $byId = array_column($query->list(), null, 'id');
        self::assertSame('Home', $byId['bFIb-syj']['label']);
        self::assertSame('About', $byId['Fp2ntvJd']['label']);
    }

    public function test_list_surfaces_modified_at_from_iso_string(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'name' => 'Home',
                    'modified' => '2026-05-22T10:00:00Z',
                    'layout' => ['type' => 'layout', 'children' => []],
                ],
            ],
        ];
        $query = new PageQuery(new LayoutReader());
        $byId = array_column($query->list(), null, 'id');
        self::assertSame('2026-05-22T10:00:00Z', $byId['tpl']['modified_at']);
    }

    public function test_list_surfaces_modified_at_from_unix_timestamp(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'name' => 'Home',
                    'modified' => 1700000000, // 2023-11-14T22:13:20+00:00
                    'layout' => ['type' => 'layout', 'children' => []],
                ],
            ],
        ];
        $query = new PageQuery(new LayoutReader());
        $byId = array_column($query->list(), null, 'id');
        self::assertSame('2023-11-14T22:13:20+00:00', $byId['tpl']['modified_at']);
    }

    public function test_list_attaches_etag_to_each_entry(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        $list = $query->list();

        foreach ($list as $entry) {
            self::assertArrayHasKey('etag', $entry);
            // F-07 (Maria-Audit 2026-05-22): ETag is `<sha256>-r<revision>`.
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}-r\d+$/', $entry['etag']);
        }
    }

    public function test_list_returns_empty_when_no_state(): void
    {
        $query = new PageQuery(new LayoutReader());
        self::assertSame([], $query->list());
    }

    public function test_layout_returns_full_template_tree(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        $tpl = $query->layout('bFIb-syj');
        self::assertNotNull($tpl);
        self::assertSame('Home', $tpl['name']);
    }

    public function test_layout_returns_null_for_unknown_template(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        self::assertNull($query->layout('nope'));
    }

    public function test_schema_returns_flat_path_list(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'section', 'props' => [], 'children' => [
                                ['type' => 'row', 'props' => [], 'children' => [
                                    ['type' => 'column', 'props' => [], 'children' => [
                                        ['type' => 'headline', 'props' => ['content' => 'Hi'], 'children' => []],
                                    ]],
                                ]],
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        $query = new PageQuery(new LayoutReader());
        $schema = $query->schema('tpl');

        self::assertNotNull($schema);
        // Each entry has 'path' + 'type'.
        foreach ($schema as $entry) {
            self::assertArrayHasKey('path', $entry);
            self::assertArrayHasKey('type', $entry);
        }
        $types = array_column($schema, 'type');
        self::assertContains('section', $types);
        self::assertContains('row', $types);
        self::assertContains('column', $types);
        self::assertContains('headline', $types);
    }

    public function test_schema_paths_are_json_pointers(): void
    {
        $GLOBALS['ytb_test_options']['yootheme'] = [
            'templates' => [
                'tpl' => [
                    'layout' => [
                        'type' => 'layout',
                        'children' => [
                            ['type' => 'section', 'props' => [], 'children' => []],
                        ],
                    ],
                ],
            ],
        ];

        $query = new PageQuery(new LayoutReader());
        $schema = $query->schema('tpl');

        self::assertNotNull($schema);
        $paths = array_column($schema, 'path');
        // First node ought to live at /templates/tpl/layout/children/0
        self::assertContains('/templates/tpl/layout/children/0', $paths);
    }

    public function test_schema_returns_null_for_unknown_template(): void
    {
        $this->seedTwoTemplates();
        $query = new PageQuery(new LayoutReader());
        self::assertNull($query->schema('nope'));
    }

    public function test_etag_proxies_layout_reader(): void
    {
        $this->seedTwoTemplates();
        $reader = new LayoutReader();
        $query = new PageQuery($reader);
        self::assertSame($reader->etag(), $query->etag());
    }
}

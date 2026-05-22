<?php
/**
 * BindingSerializer — single source-of-truth structured binding-read.
 *
 * D1 / T1 — F-01-Rest. The serializer is the ONE function that all
 * `has_binding` / `binding-detail` paths funnel through. It parses the
 * complete `source` blob from a layout-node into a canonical record:
 *
 *   {
 *     source_name:      string,                     // = query.name
 *     query_field?:     string,                     // = query.field.name
 *     field_mappings:   [ {element_prop, source_field, filters?}, ... ],
 *     directives?:      list<{name, arguments?}>,   // = query.field.directives
 *     query_arguments?: array,                      // = query.field.arguments
 *     raw_source:       array,                      // verbatim source blob
 *   }
 *
 * Returns null when no binding indicators are present (no `source` prop,
 * empty `query.name`, no `props.<el>.name`).
 *
 * The serializer accepts the source from EITHER of the four shapes the
 * live YT4 layouts have been observed to use:
 *   1. `$node['source']`                       — top-level source object.
 *   2. `$node['props']['source']`              — F-13 canonical.
 *   3. `$node['source_extended']`              — pre-bind cached shape.
 *   4. bare string `$node['props']['source']`  — legacy pre-F-13 user data.
 *
 * @package WootsUp\BuilderMcp\Tests
 */

declare(strict_types=1);

namespace WootsUp\BuilderMcp\Tests\Unit\SourceBinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WootsUp\BuilderMcp\SourceBinding\BindingSerializer;

#[CoversClass(BindingSerializer::class)]
final class BindingSerializerTest extends TestCase
{
    public function test_returns_null_when_no_source_present(): void
    {
        self::assertNull(BindingSerializer::serialize(['type' => 'headline']));
        self::assertNull(BindingSerializer::serialize(['type' => 'headline', 'props' => []]));
        self::assertNull(BindingSerializer::serialize([
            'type' => 'headline',
            'props' => ['content' => 'Hi'],
        ]));
    }

    public function test_legacy_bare_string_binding(): void
    {
        $result = BindingSerializer::serialize([
            'type' => 'grid',
            'props' => ['source' => 'apiMapperFlow123'],
        ]);
        self::assertNotNull($result);
        self::assertSame('apiMapperFlow123', $result['source_name']);
        self::assertSame([], $result['field_mappings']);
    }

    public function test_structured_bare_query_name(): void
    {
        $result = BindingSerializer::serialize([
            'type' => 'headline',
            'props' => [
                'source' => ['query' => ['name' => 'posts.singlePost']],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('posts.singlePost', $result['source_name']);
        self::assertSame([], $result['field_mappings']);
    }

    public function test_structured_with_field_mappings_live_yt4_shape(): void
    {
        // Live reproduction from
        // /templates/I99YS8Ii/layout/children/20/children/0/children/0/children/0
        $result = BindingSerializer::serialize([
            'type' => 'headline',
            'props' => [
                'source' => [
                    'query' => ['name' => 'posts.singlePost'],
                    'props' => [
                        'content' => ['name' => 'metaString'],
                        'title' => ['name' => 'title'],
                        'date' => ['name' => 'date'],
                        'featuredImage.url' => ['name' => 'featuredImage.url'],
                    ],
                ],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('posts.singlePost', $result['source_name']);
        // field_mappings is a LIST of {element_prop, source_field, filters?}
        // so callers can iterate without losing insertion order.
        self::assertCount(4, $result['field_mappings']);
        $byProp = [];
        foreach ($result['field_mappings'] as $mapping) {
            $byProp[$mapping['element_prop']] = $mapping['source_field'];
        }
        self::assertSame('metaString', $byProp['content']);
        self::assertSame('title', $byProp['title']);
        self::assertSame('date', $byProp['date']);
        self::assertSame('featuredImage.url', $byProp['featuredImage.url']);
    }

    public function test_structured_with_query_field_arguments_and_directives(): void
    {
        $result = BindingSerializer::serialize([
            'type' => 'headline',
            'props' => [
                'source' => [
                    'query' => [
                        'name' => 'posts.posts',
                        'field' => [
                            'name' => 'posts',
                            'arguments' => ['limit' => 5, 'orderby' => 'date'],
                            'directives' => [
                                ['name' => 'include', 'arguments' => ['if' => true]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('posts.posts', $result['source_name']);
        self::assertSame('posts', $result['query_field']);
        self::assertSame(['limit' => 5, 'orderby' => 'date'], $result['query_arguments']);
        self::assertCount(1, $result['directives']);
        self::assertSame('include', $result['directives'][0]['name']);
        self::assertSame(['if' => true], $result['directives'][0]['arguments']);
    }

    public function test_structured_with_filters_on_field_mapping(): void
    {
        $result = BindingSerializer::serialize([
            'type' => 'image',
            'props' => [
                'source' => [
                    'query' => ['name' => 'posts.singlePost'],
                    'props' => [
                        'image' => [
                            'name' => 'featuredImage.url',
                            'filters' => ['width' => 800, 'height' => 600],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('featuredImage.url', $result['field_mappings'][0]['source_field']);
        self::assertSame(
            ['width' => 800, 'height' => 600],
            $result['field_mappings'][0]['filters'],
        );
    }

    public function test_reads_from_top_level_source_field(): void
    {
        // Some YT4 layouts (pre-bind cached state) carry the source at
        // the top level, not under props.source.
        $result = BindingSerializer::serialize([
            'type' => 'grid',
            'source' => [
                'query' => ['name' => 'posts.posts'],
                'props' => [
                    'image' => ['name' => 'featuredImage.url'],
                ],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('posts.posts', $result['source_name']);
        self::assertCount(1, $result['field_mappings']);
    }

    public function test_reads_from_source_extended_field(): void
    {
        // `source_extended` is the YT4 internal cached/expanded form.
        $result = BindingSerializer::serialize([
            'type' => 'grid',
            'source_extended' => [
                'query' => ['name' => 'posts.posts'],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('posts.posts', $result['source_name']);
    }

    public function test_props_takes_precedence_over_top_level(): void
    {
        // When both props.source and top-level source exist, props.source
        // is canonical (it is what the writer puts there).
        $result = BindingSerializer::serialize([
            'type' => 'grid',
            'source' => ['query' => ['name' => 'OLD']],
            'props' => [
                'source' => ['query' => ['name' => 'NEW']],
            ],
        ]);
        self::assertNotNull($result);
        self::assertSame('NEW', $result['source_name']);
    }

    public function test_returns_null_when_structured_source_has_no_query_name_and_no_props(): void
    {
        // Degenerate write — no query name, no field-mappings. Surface as
        // null rather than lying.
        self::assertNull(BindingSerializer::serialize([
            'type' => 'headline',
            'props' => ['source' => ['query' => []]],
        ]));
    }

    public function test_field_mappings_present_implies_binding_even_without_query_name(): void
    {
        // A node that has props.<el>.name mappings but no query.name still
        // counts as bound (the field-bindings reference the parent
        // iteration source via the `${builder.source}` token).
        $result = BindingSerializer::serialize([
            'type' => 'headline',
            'props' => [
                'source' => [
                    'props' => [
                        'content' => [
                            'name' => '${builder.source}',
                            'inherit' => true,
                        ],
                    ],
                ],
            ],
        ]);
        self::assertNotNull($result);
        self::assertCount(1, $result['field_mappings']);
    }

    public function test_raw_source_passthrough(): void
    {
        $blob = [
            'query' => ['name' => 'posts.posts'],
            'props' => ['title' => ['name' => 'title']],
        ];
        $result = BindingSerializer::serialize([
            'type' => 'headline',
            'props' => ['source' => $blob],
        ]);
        self::assertNotNull($result);
        self::assertSame($blob, $result['raw_source']);
    }
}

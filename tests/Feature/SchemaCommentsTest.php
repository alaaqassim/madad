<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every column of the business schema explains itself, in Arabic, inside the
 * database.
 *
 * The comments are the first thing anybody sees when they open the schema in
 * phpMyAdmin, and they are the only documentation guaranteed to arrive with a
 * dump. This test exists so a column added later cannot arrive undocumented -
 * the failure names the column, so the fix is obvious.
 */
class SchemaCommentsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function businessTables(): array
    {
        return [
            'the settings row' => ['competition_settings'],
            'the question bank' => ['competition_questions'],
            'the contestant row' => ['competition_users'],
            'the login accounts' => ['users'],
        ];
    }

    /** @dataProvider businessTables */
    public function test_every_column_carries_an_arabic_comment(string $table): void
    {
        $columns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");

        $this->assertNotEmpty($columns, "{$table} has no columns at all");

        foreach ($columns as $column) {
            $comment = trim((string) $column->Comment);

            $this->assertNotSame(
                '',
                $comment,
                "{$table}.{$column->Field} has no comment - add it to the Arabic comment migration",
            );

            // A comment in English would be no comment at all for the people
            // who read this schema. \x{0600}-\x{06FF} is the Arabic block.
            $this->assertMatchesRegularExpression(
                '/\p{Arabic}/u',
                $comment,
                "{$table}.{$column->Field} is commented, but not in Arabic",
            );
        }
    }

    /** @dataProvider businessTables */
    public function test_the_table_itself_says_what_it_holds(string $table): void
    {
        $comment = DB::selectOne(
            'SELECT TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $this->assertMatchesRegularExpression(
            '/\p{Arabic}/u',
            trim((string) ($comment->c ?? '')),
            "{$table} has no Arabic table comment",
        );
    }
}

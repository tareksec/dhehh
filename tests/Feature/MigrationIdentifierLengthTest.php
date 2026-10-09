<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests run on SQLite, which accepts index names of any length; production
 * runs MySQL, which refuses any over 64 characters and stops the deploy's
 * migrations half-way (v2.63.0: a 68-character auto-named unique index on
 * agreement_acceptances left every later table missing and the admin panel
 * answering 500). Give a long index an explicit short name.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_index_name_fits_mysqls_64_character_limit(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (strlen($table) > 64) {
                $tooLong[] = "table {$table}";
            }

            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > 64) {
                    $tooLong[] = "{$table}: {$index['name']} (".strlen($index['name']).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'MySQL rejects identifiers over 64 characters.');
    }
}

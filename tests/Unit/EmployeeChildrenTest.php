<?php

namespace Tests\Unit;

use App\Support\Hr\EmployeeChildren;
use PHPUnit\Framework\TestCase;

class EmployeeChildrenTest extends TestCase
{
    public function test_the_sheet_note_is_read_as_a_list(): void
    {
        $list = EmployeeChildren::parse("Mayrah(F, 3ans)\nMalyah(F, 3ans)");

        $this->assertSame([
            ['name' => 'Mayrah', 'sex' => 'F', 'age' => 3],
            ['name' => 'Malyah', 'sex' => 'F', 'age' => 3],
        ], $list);
    }

    public function test_an_unreadable_note_is_not_guessed(): void
    {
        $this->assertNull(EmployeeChildren::parse('trois enfants, dont un à Tana'));
        $this->assertNull(EmployeeChildren::parse(' Lucianah(F9ans,Annayah(F3ans '));
    }

    public function test_empty_rows_are_dropped_and_the_label_reads_well(): void
    {
        $rows = EmployeeChildren::normalize([['name' => ' ', 'sex' => '', 'age' => ''], ['name' => 'Tiavina', 'sex' => 'g', 'age' => '15']]);

        $this->assertCount(1, $rows);
        $this->assertSame('Tiavina (G, 15 ans)', EmployeeChildren::label($rows));
    }
}

<?php

namespace Tests\Unit;

use App\Models\AddressEntry;
use App\Models\Employee;
use App\Services\Administration\EmployeePatientIdentityMapper;
use Tests\TestCase;

class EmployeePatientIdentityMapperTest extends TestCase
{
    public function test_it_maps_only_the_employee_identity_fields_owned_by_hr(): void
    {
        $employee = new Employee([
            'employee_number' => 'EMP-RH-UNIT',
            'civility' => 'MRS',
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'sex' => 'F',
            'birth_date' => '1990-01-15',
            'identity_document_type' => 'CIN',
            'identity_document_number' => 'RH-UNIT-001',
            'marital_status' => 'MARRIED',
            'children_count' => 2,
            'profession' => 'Personnel administratif',
            'phone' => '0320000000',
            'email' => 'soa.rabe@clinic.test',
            'address' => 'Ancienne adresse libre',
            'address_entry_id' => 42,
            'active' => true,
        ]);
        $employee->setRelation('addressEntry', new AddressEntry(['label' => 'Adresse référencée']));

        $mapped = (new EmployeePatientIdentityMapper)->map($employee);

        $this->assertSame([
            'civility' => 'MRS',
            'first_name' => 'Soa',
            'last_name' => 'Rabe',
            'birth_date' => '1990-01-15',
            'sex' => 'F',
            'identity_document_type' => 'CIN',
            'identity_document_number' => 'RH-UNIT-001',
            'marital_status' => 'MARRIED',
            'children_count' => 2,
            'profession' => 'Personnel administratif',
            'phone' => '0320000000',
            'email' => 'soa.rabe@clinic.test',
            'address' => 'Adresse référencée',
            'address_entry_id' => 42,
        ], $mapped);
        $this->assertArrayNotHasKey('employee_number', $mapped);
        $this->assertArrayNotHasKey('active', $mapped);
    }
}

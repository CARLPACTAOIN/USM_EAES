<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use Illuminate\Database\Seeder;

class UsmInstitutionalDataSeeder extends Seeder
{
    public function run(): void
    {
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        $colleges = [
            'CA' => ['College of Agriculture', ['BSA' => 'BS Agriculture', 'BSFISH' => 'BS Fisheries']],
            'CASS' => ['College of Arts and Social Sciences', ['ABPSY' => 'AB Psychology', 'ABEL' => 'AB English Language', 'BSCRIM' => 'BS Criminology', 'ABPOL' => 'AB Political Science', 'ABPHIL' => 'AB Philosophy / Pre-Law']],
            'CED' => ['College of Education', ['BSE-EN' => 'Bachelor of Secondary Education major in English', 'BSE-FI' => 'Bachelor of Secondary Education major in Filipino', 'BSE-SS' => 'Bachelor of Secondary Education major in Social Studies', 'BSE-SC' => 'Bachelor of Secondary Education major in Science', 'BSE-MA' => 'Bachelor of Secondary Education major in Mathematics', 'BEED' => 'Bachelor of Elementary Education']],
            'CEIT' => ['College of Engineering and Information Technology', ['BSCE' => 'BS Civil Engineering', 'BSABE' => 'BS Agricultural and Biosystems Engineering', 'BSCOE' => 'BS Computer Engineering', 'BSECE' => 'BS Electronics Engineering', 'BSIS' => 'BS Information Systems', 'BSCS' => 'BS Computer Science', 'BLIS' => 'Bachelor of Library and Information Science']],
            'CHEFS' => ['College of Human Ecology and Food Sciences', ['BSFT' => 'BS Food Technology', 'BSND' => 'BS Nutrition and Dietetics', 'BSHM' => 'BS Hospitality Management', 'BSTM' => 'BS Tourism Management']],
            'CHS' => ['College of Health Sciences', ['BSN' => 'BS Nursing']],
            'CBDEM' => ['College of Business, Development Economics and Management', ['BSACCT' => 'BS Accountancy', 'BSMAC' => 'BS Management Accounting', 'BSBA' => 'BS Business Administration', 'BSAB' => 'BS Agribusiness', 'BSAGEC' => 'BS Agricultural Economics', 'BSDM' => 'BS Development Management']],
            'IMEAS' => ['Institute of Middle East and Asian Studies', ['BSIR' => 'BS International Relations']],
            'CSM' => ['College of Science and Mathematics', ['BSBIO' => 'BS Biology', 'BSCHEM' => 'BS Chemistry', 'BSDC' => 'BS Development Communication']],
            'CVM' => ['College of Veterinary Medicine', ['DVM' => 'Doctor of Veterinary Medicine', 'BSVT' => 'BS Veterinary Technology']],
            'IPEAR' => ['Institute of Physical Education and Recreation', ['BPE' => 'Bachelor of Physical Education', 'BSESS' => 'BS Exercise and Sports Sciences']],
            'CFCST' => ['College of Fisheries, Computing Sciences and Technology', []],
        ];

        foreach ($colleges as $code => [$name, $programs]) {
            $college = College::updateOrCreate(
                ['code' => $code],
                ['university_id' => $university->id, 'name' => $name]
            );

            foreach ($programs as $programCode => $programName) {
                Program::updateOrCreate(
                    ['college_id' => $college->id, 'code' => $programCode],
                    ['name' => $programName]
                );
            }

            Organization::firstOrCreate(
                ['type' => 'lsg', 'college_id' => $college->id, 'acronym' => "{$code} LSG"],
                ['name' => "{$code} Local Student Government", 'status' => 'active']
            );
        }

        Organization::firstOrCreate(
            ['type' => 'usg', 'college_id' => null, 'acronym' => 'USG'],
            ['name' => 'University Student Government', 'status' => 'active']
        );

        Organization::firstOrCreate(
            ['type' => 'aro', 'college_id' => null, 'acronym' => 'ARO'],
            ['name' => 'Admission and Records Office', 'status' => 'active']
        );
    }
}

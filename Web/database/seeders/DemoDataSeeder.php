<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\College;
use App\Models\Evaluation;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Models\RawScan;
use App\Services\AdminAssignmentService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(UsmInstitutionalDataSeeder::class);

        $faker = Faker::create('en_PH');
        $assignmentService = app(AdminAssignmentService::class);
        $academicYear = now()->year . '-' . (now()->year + 1);

        // 1. Core University
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        // Raw organizations and colleges list provided by the user
        $orgsData = [
            [
                "college_code" => "CA",
                "college_name" => "College of Agriculture",
                "org_name" => "Agronomy Society",
                "org_abbreviation" => "AGS",
                "programs" => ["BS Agriculture"]
            ],
            [
                "college_code" => "CA",
                "college_name" => "College of Agriculture",
                "org_name" => "USM Fisheries Society",
                "org_abbreviation" => "FISO",
                "programs" => ["BS Fisheries"]
            ],
            [
                "college_code" => "CA",
                "college_name" => "College of Agriculture",
                "org_name" => "USM Plant Pathology Society",
                "org_abbreviation" => "USM-PPS",
                "programs" => ["BS Agriculture"]
            ],
            [
                "college_code" => "CA",
                "college_name" => "College of Agriculture",
                "org_name" => "USM Horticulture Society",
                "org_abbreviation" => "USM-HS",
                "programs" => ["BS Agriculture"]
            ],
            [
                "college_code" => "CA",
                "college_name" => "College of Agriculture",
                "org_name" => "Animal Science Society",
                "org_abbreviation" => "ASS",
                "programs" => ["BS Agriculture"]
            ],
            [
                "college_code" => "CASS",
                "college_name" => "College of Arts and Social Sciences",
                "org_name" => "Association of Psychology Students",
                "org_abbreviation" => "APS",
                "programs" => ["AB Psychology"]
            ],
            [
                "college_code" => "CASS",
                "college_name" => "College of Arts and Social Sciences",
                "org_name" => "AB English Society",
                "org_abbreviation" => "AEBS",
                "programs" => ["AB English Language"]
            ],
            [
                "college_code" => "CASS",
                "college_name" => "College of Arts and Social Sciences",
                "org_name" => "Criminology Society",
                "org_abbreviation" => "CRS",
                "programs" => ["BS Criminology"]
            ],
            [
                "college_code" => "CASS",
                "college_name" => "College of Arts and Social Sciences",
                "org_name" => "Society of Social Scientists",
                "org_abbreviation" => "SSS",
                "programs" => ["AB Political Science", "AB Philosophy / Pre-Law"]
            ],
            [
                "college_code" => "CED",
                "college_name" => "College of Education",
                "org_name" => "Future Secondary Mentors Society",
                "org_abbreviation" => "FSMS",
                "programs" => [
                    "Bachelor of Secondary Education major in English",
                    "Bachelor of Secondary Education major in Filipino",
                    "Bachelor of Secondary Education major in Social Studies",
                    "Bachelor of Secondary Education major in Science",
                    "Bachelor of Secondary Education major in Mathematics"
                ]
            ],
            [
                "college_code" => "CED",
                "college_name" => "College of Education",
                "org_name" => "Future Elementary Educators Society",
                "org_abbreviation" => "FEES",
                "programs" => ["Bachelor of Elementary Education"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "Philippine Institute of Civil Engineers - USM Student Chapter",
                "org_abbreviation" => "PICE-USM SC",
                "programs" => ["BS Civil Engineering"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "Philippine Society of Agricultural and Biosystems Engineers Pre-Professional Group",
                "org_abbreviation" => "PSABE-PPG",
                "programs" => ["BS Agricultural and Biosystems Engineering"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "Institute of Computer Engineers of the Philippines",
                "org_abbreviation" => "ICPEP",
                "programs" => ["BS Computer Engineering"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "Junior Institute of Electronics Engineers of the Philippines",
                "org_abbreviation" => "JIECEP",
                "programs" => ["BS Electronics Engineering"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "Philippine Society of Information Technology Students",
                "org_abbreviation" => "PSITS",
                "programs" => ["BS Information Systems", "BS Computer Science"]
            ],
            [
                "college_code" => "CEIT",
                "college_name" => "College of Engineering and Information Technology",
                "org_name" => "National Builders Association",
                "org_abbreviation" => "NABA",
                "programs" => ["BS Civil Engineering"]
            ],
            [
                "college_code" => "CHEFS",
                "college_name" => "College of Human Ecology and Food Sciences",
                "org_name" => "Food Technology Students Society",
                "org_abbreviation" => "FTSS",
                "programs" => ["BS Food Technology"]
            ],
            [
                "college_code" => "CHEFS",
                "college_name" => "College of Human Ecology and Food Sciences",
                "org_name" => "Nutrition and Dietetics Student Society",
                "org_abbreviation" => "NDSS",
                "programs" => ["BS Nutrition and Dietetics"]
            ],
            [
                "college_code" => "CHEFS",
                "college_name" => "College of Human Ecology and Food Sciences",
                "org_name" => "Society of Hotel and Restaurant Progress",
                "org_abbreviation" => "SHARP",
                "programs" => ["BS Hospitality Management"]
            ],
            [
                "college_code" => "CHEFS",
                "college_name" => "College of Human Ecology and Food Sciences",
                "org_name" => "Union of Filipino Tourism and Travel Society",
                "org_abbreviation" => "UFTTS",
                "programs" => ["BS Tourism Management"]
            ],
            [
                "college_code" => "CHS",
                "college_name" => "College of Health Sciences",
                "org_name" => "Nursing Student Association",
                "org_abbreviation" => "NSA",
                "programs" => ["BS Nursing"]
            ],
            [
                "college_code" => "CBDEM",
                "college_name" => "College of Business, Development Economics and Management",
                "org_name" => "Junior Philippine Institute of Accountants",
                "org_abbreviation" => "JPIA",
                "programs" => ["BS Accountancy", "BS Management Accounting"]
            ],
            [
                "college_code" => "CBDEM",
                "college_name" => "College of Business, Development Economics and Management",
                "org_name" => "Junior Marketing Executives Society",
                "org_abbreviation" => "JMES",
                "programs" => ["BS Business Administration"]
            ],
            [
                "college_code" => "CBDEM",
                "college_name" => "College of Business, Development Economics and Management",
                "org_name" => "Agribusiness Society",
                "org_abbreviation" => "ABS",
                "programs" => ["BS Agribusiness"]
            ],
            [
                "college_code" => "CBDEM",
                "college_name" => "College of Business, Development Economics and Management",
                "org_name" => "Agricultural Economics Society",
                "org_abbreviation" => "AECOS",
                "programs" => ["BS Agricultural Economics"]
            ],
            [
                "college_code" => "CBDEM",
                "college_name" => "College of Business, Development Economics and Management",
                "org_name" => "Development Management Society",
                "org_abbreviation" => "DMS",
                "programs" => ["BS Development Management"]
            ],
            [
                "college_code" => "IMEAS",
                "college_name" => "Institute of Middle East and Asian Studies",
                "org_name" => "La Liga Diplomatica",
                "org_abbreviation" => "LLD",
                "programs" => ["BS International Relations"]
            ],
            [
                "college_code" => "CSM",
                "college_name" => "College of Science and Mathematics",
                "org_name" => "USM Biology Club",
                "org_abbreviation" => "USMBC",
                "programs" => ["BS Biology"]
            ],
            [
                "college_code" => "CSM",
                "college_name" => "College of Science and Mathematics",
                "org_name" => "USM Chemistry Society",
                "org_abbreviation" => "CHEMSOC",
                "programs" => ["BS Chemistry"]
            ],
            [
                "college_code" => "CSM",
                "college_name" => "College of Science and Mathematics",
                "org_name" => "Development Communication Society",
                "org_abbreviation" => "DCS",
                "programs" => ["BS Development Communication"]
            ],
            [
                "college_code" => "CVM",
                "college_name" => "College of Veterinary Medicine",
                "org_name" => "Association of Veterinary Medicine Students",
                "org_abbreviation" => "AVMS",
                "programs" => ["Doctor of Veterinary Medicine", "BS Veterinary Technology"]
            ],
            [
                "college_code" => "CVM",
                "college_name" => "College of Veterinary Medicine",
                "org_name" => "Society for the Advancement of Veterinary Education and Research",
                "org_abbreviation" => "SAVER",
                "programs" => ["Doctor of Veterinary Medicine", "BS Veterinary Technology"]
            ],
            [
                "college_code" => "IPEAR",
                "college_name" => "Institute of Physical Education and Recreation",
                "org_name" => "Men Sana En Corpone Sano Society",
                "org_abbreviation" => "MESECOS",
                "programs" => ["Bachelor of Physical Education", "BS Exercise and Sports Sciences"]
            ]
        ];

        $programCodeMap = [
            "BS Agriculture" => "BSA",
            "BS Fisheries" => "BSFISH",
            "BS Civil Engineering" => "BSCE",
            "BS Agricultural and Biosystems Engineering" => "BSABE",
            "BS Computer Engineering" => "BSCOE",
            "BS Electronics Engineering" => "BSECE",
            "BS Information Systems" => "BSIS",
            "BS Computer Science" => "BSCS",
            "BS Food Technology" => "BSFT",
            "BS Nutrition and Dietetics" => "BSND",
            "BS Hospitality Management" => "BSHM",
            "BS Tourism Management" => "BSTM",
            "BS Nursing" => "BSN",
            "BS Accountancy" => "BSACCT",
            "BS Management Accounting" => "BSMAC",
            "BS Business Administration" => "BSBA",
            "BS Agribusiness" => "BSAB",
            "BS Agricultural Economics" => "BSAGEC",
            "BS Development Management" => "BSDM",
            "BS International Relations" => "BSIR",
            "BS Biology" => "BSBIO",
            "BS Chemistry" => "BSCHEM",
            "BS Development Communication" => "BSDC",
            "Doctor of Veterinary Medicine" => "DVM",
            "BS Veterinary Technology" => "BSVT",
            "Bachelor of Physical Education" => "BPE",
            "BS Exercise and Sports Sciences" => "BSESS",
            "AB Psychology" => "ABPSY",
            "AB English Language" => "ABEL",
            "BS Criminology" => "BSCRIM",
            "AB Political Science" => "ABPOL",
            "AB Philosophy / Pre-Law" => "ABPHIL",
            "Bachelor of Secondary Education major in English" => "BSE-EN",
            "Bachelor of Secondary Education major in Filipino" => "BSE-FI",
            "Bachelor of Secondary Education major in Social Studies" => "BSE-SS",
            "Bachelor of Secondary Education major in Science" => "BSE-SC",
            "Bachelor of Secondary Education major in Mathematics" => "BSE-MA",
            "Bachelor of Elementary Education" => "BEED",
        ];

        $collegesMap = [];
        $programsMap = [];
        $orgsMap = [];

        $osaAdmin = $this->upsertUser('osa.demo@usm.edu.ph', 'Demo OSA Admin', null, null, null);
        $this->ensureAdminAssignment($assignmentService, $osaAdmin, 'Super Admin (OSA)', null, null, $academicYear, $osaAdmin);

        // 2. Loop through and create Colleges, Programs, and Organizations
        foreach ($orgsData as $data) {
            $collegeCode = $data['college_code'];
            $collegeName = $data['college_name'];

            if (!isset($collegesMap[$collegeCode])) {
                $college = College::firstOrCreate(
                    ['code' => $collegeCode],
                    [
                        'university_id' => $university->id,
                        'name' => $collegeName
                    ]
                );
                $collegesMap[$collegeCode] = $college;
            } else {
                $college = $collegesMap[$collegeCode];
            }

            // Create Program records
            $localProgramIds = [];
            foreach ($data['programs'] as $pName) {
                $pCode = $programCodeMap[$pName] ?? substr(strtoupper(preg_replace('/[^A-Z]/', '', $pName)), 0, 6);
                
                if (!isset($programsMap[$pCode])) {
                    $program = Program::firstOrCreate(
                        ['code' => $pCode],
                        [
                            'college_id' => $college->id,
                            'name' => $pName
                        ]
                    );
                    $programsMap[$pCode] = $program;
                } else {
                    $program = $programsMap[$pCode];
                }
                $localProgramIds[] = $program->id;
            }

            // Create Organization record
            $orgAcronym = $data['org_abbreviation'];
            $orgName = $data['org_name'];

            $org = Organization::firstOrCreate(
                ['acronym' => $orgAcronym],
                [
                    'college_id' => $college->id,
                    'name' => $orgName,
                    'type' => 'society'
                ]
            );
            $orgsMap[$orgAcronym] = $org;

            // Link organization to programs
            $org->programs()->syncWithoutDetaching($localProgramIds);

            // Create Society Admin
            $adminEmail = strtolower($orgAcronym) . '.admin@usm.edu.ph';
            $adminUser = $this->upsertUser(
                $adminEmail,
                'Admin of ' . $orgAcronym,
                $org,
                $college,
                count($localProgramIds) > 0 ? Program::find($localProgramIds[0]) : null
            );
            $this->ensureAdminAssignment($assignmentService, $adminUser, 'Society Admin', $org, $college, $academicYear, $osaAdmin);
        }

        // 3. Create fixed Demo Admin accounts required by DeploymentHardeningTest
        $ceitCollege = $collegesMap['CEIT'] ?? null;
        $bsisProgram = $programsMap['BSIS'] ?? null;
        $psitsOrg = $orgsMap['PSITS'] ?? null;

        $societyDemoAdmin = $this->upsertUser('society.demo@usm.edu.ph', 'Demo Society Admin', $psitsOrg, $ceitCollege, $bsisProgram);
        $this->ensureAdminAssignment($assignmentService, $societyDemoAdmin, 'Society Admin', $psitsOrg, $ceitCollege, $academicYear, $osaAdmin, false);

        $studentDemo = $this->upsertUser('student.demo@usm.edu.ph', 'Demo Student', $psitsOrg, $ceitCollege, $bsisProgram, [
            'student_id_number' => '2026-DEMO-001',
            'qr_code_value' => 'EAES-DEMO-STUDENT-QR',
        ]);
        $studentDemo->syncRoles('Student');

        // 4. Generate ~100 Mock Students distributed across all programs
        $allPrograms = Program::all();
        $studentCount = 100;
        $seededStudents = [$studentDemo];

        for ($i = 1; $i <= $studentCount; $i++) {
            $program = $allPrograms->random();
            $college = College::find($program->college_id);
            
            // Find organization matching this program (if any)
            $org = $program->organizations()->first() ?? $orgsMap['PSITS'];

            $idNum = '23-' . sprintf('%05d', 72000 + $i);
            $gender = $faker->randomElement(['male', 'female']);
            $firstName = ($gender === 'male') ? $faker->firstNameMale : $faker->firstNameFemale;
            $lastName = $faker->lastName;
            $studentName = $firstName . ' ' . $lastName;
            $email = strtolower($firstName . '.' . $lastName . $i) . '@usm.edu.ph';

            $mockStudent = $this->upsertUser(
                $email,
                $studentName,
                $org,
                $college,
                $program,
                [
                    'student_id_number' => $idNum,
                    'qr_code_value' => $idNum, // identical to student ID
                ]
            );
            $mockStudent->syncRoles('Student');
            $seededStudents[] = $mockStudent;
        }

        // 5. Generate Mock Events and Event Days
        // We will target four major societies to have events (completed and upcoming):
        $featuredOrgs = [
            'PSITS' => 'CEIT',
            'APS' => 'CASS',
            'FSMS' => 'CED',
            'JPIA' => 'CBDEM'
        ];

        $sentimentComments = [
            'positive' => [
                "The speakers were very clear and engaging. Great event!",
                "Maganda ang topic at marami akong natutunan sa event na ito.",
                "Overall a great experience. Looking forward to the next summit!",
                "Super organized ng event na ito, kudos to the officers!",
                "Very educational and well-prepared. Worth my time."
            ],
            'neutral' => [
                "Okay naman ang event pero sana mas malaki ang venue next time.",
                "It was fine, but some topics were quite repetitive.",
                "Average event. The speaker was good but the sound system had issues.",
                "Okay lang naman, medyo late lang nagsimula.",
                "The event was informative but the aircon was not cool enough."
            ],
            'negative' => [
                "Masyadong mainit sa venue at hindi narinig nang maayos ang speaker.",
                "The schedule was not followed. We waited for 2 hours before starting.",
                "Disorganized flow. The registration took too long and the lines were bad.",
                "Walang kwenta yung event, sayang sa binayad at oras.",
                "Very disappointing. The speakers were boring and unprepared."
            ]
        ];

        foreach ($featuredOrgs as $orgAcronym => $collegeCode) {
            $org = $orgsMap[$orgAcronym] ?? null;
            $college = $collegesMap[$collegeCode] ?? null;
            if (!$org || !$college) continue;

            // Define programs under this org
            $orgPrograms = $org->programs->pluck('id')->toArray();
            
            // Filter seeded students belonging to this organization/programs
            $orgStudents = collect($seededStudents)->filter(function ($student) use ($orgPrograms) {
                return in_array($student->program_id, $orgPrograms);
            });

            // If we don't have enough students, just use a random subset of all students
            if ($orgStudents->count() < 10) {
                $orgStudents = collect($seededStudents)->random(min(15, count($seededStudents)));
            }

            // --- A. Completed Event ---
            $completedEvent = Event::firstOrCreate(
                ['title' => $orgAcronym . ' Annual Assembly 2026', 'organization_id' => $org->id],
                [
                    'proposal_category' => 'activity',
                    'status' => 'completed',
                    'start_date' => Carbon::today()->subDays(4),
                    'end_date' => Carbon::today()->subDays(4),
                    'location_type' => 'on-campus',
                    'location_details' => $collegeCode . ' Multi-Purpose Hall',
                    'target_demographics' => [
                        'implementing_office' => $orgAcronym,
                        'target_participants' => $orgAcronym . ' constituents',
                    ],
                    'budget_allocations' => [
                        'source_of_fund' => 'Trust fund',
                        'budget_cost' => 3000,
                    ],
                    'hardcopy_submitted' => true,
                    'hardcopy_submitted_at' => now()->subDays(5),
                    'head_organization_signed' => true,
                    'adviser_signed' => true,
                    'evaluation_open' => true,
                ]
            );

            $eventDay = EventDay::firstOrCreate(
                ['event_id' => $completedEvent->id, 'day_number' => 1],
                [
                    'date' => Carbon::today()->subDays(4),
                    'start_time' => '08:00:00',
                    'end_time' => '12:00:00',
                ]
            );

            // Generate scans, attendance and evaluations for 80% of org students
            foreach ($orgStudents->take(15) as $student) {
                $timeInOffset = $faker->numberBetween(-15, 45); // Some early, some on-time, some late
                $timeOutOffset = $faker->numberBetween(-10, 15);

                $timeIn = Carbon::today()->subDays(4)->setTime(8, 0)->addMinutes($timeInOffset);
                $timeOut = Carbon::today()->subDays(4)->setTime(12, 0)->addMinutes($timeOutOffset);

                // Determine statuses
                if ($timeInOffset <= 15) {
                    $societyStatus = 'present_on_time';
                    $compStatus = 'present_on_time';
                } elseif ($timeInOffset <= 30) {
                    $societyStatus = 'late';
                    $compStatus = 'present_on_time';
                } else {
                    $societyStatus = 'late_cutoff';
                    $compStatus = 'late_cutoff';
                }

                AttendanceRecord::firstOrCreate(
                    ['event_id' => $completedEvent->id, 'event_day_id' => $eventDay->id, 'student_id' => $student->id],
                    [
                        'time_in' => $timeIn,
                        'time_out' => $timeOut,
                        'society_status' => $societyStatus,
                        'competition_status' => $compStatus,
                        'valid' => true,
                    ]
                );

                // Raw Scans
                RawScan::firstOrCreate(
                    ['dedup_key' => $eventDay->id . '-' . $student->id . '-time_in'],
                    [
                        'event_id' => $completedEvent->id,
                        'event_day_id' => $eventDay->id,
                        'student_id' => $student->id,
                        'qr_code_value' => $student->qr_code_value,
                        'scan_type' => 'time_in',
                        'scanned_at' => $timeIn,
                        'device_id' => 'device-demo-001',
                    ]
                );

                RawScan::firstOrCreate(
                    ['dedup_key' => $eventDay->id . '-' . $student->id . '-time_out'],
                    [
                        'event_id' => $completedEvent->id,
                        'event_day_id' => $eventDay->id,
                        'student_id' => $student->id,
                        'qr_code_value' => $student->qr_code_value,
                        'scan_type' => 'time_out',
                        'scanned_at' => $timeOut,
                        'device_id' => 'device-demo-001',
                    ]
                );

                // Evaluation
                $sentiment = $faker->randomElement(['positive', 'positive', 'neutral', 'negative']); // weight positive
                $comment = $faker->randomElement($sentimentComments[$sentiment]);
                $score = ($sentiment === 'positive') ? $faker->randomFloat(2, 0.7, 0.99) : 
                         (($sentiment === 'neutral') ? $faker->randomFloat(2, 0.4, 0.69) : $faker->randomFloat(2, 0.1, 0.39));

                Evaluation::firstOrCreate(
                    ['event_id' => $completedEvent->id, 'student_id' => $student->id],
                    [
                        'section_scores' => [
                            'attainment_of_objectives' => $faker->numberBetween(3, 5),
                            'speaker_mastery' => $faker->numberBetween(3, 5),
                            'venue_comfort' => $faker->numberBetween(2, 5),
                            'program_flow' => $faker->numberBetween(3, 5),
                            'time_management' => $faker->numberBetween(3, 5),
                        ],
                        'open_comment' => $comment,
                        'sentiment' => $sentiment,
                        'sentiment_score' => $score,
                        'submitted_at' => Carbon::today()->subDays(3),
                    ]
                );
            }

            // --- B. Upcoming/Approved Event ---
            $approvedEvent = Event::firstOrCreate(
                ['title' => $orgAcronym . ' Development Seminar 2026', 'organization_id' => $org->id],
                [
                    'proposal_category' => 'activity',
                    'status' => 'approved',
                    'start_date' => Carbon::today()->addDays(5),
                    'end_date' => Carbon::today()->addDays(5),
                    'location_type' => 'on-campus',
                    'location_details' => 'University Gymnasium',
                    'target_demographics' => [
                        'implementing_office' => $orgAcronym,
                        'target_participants' => 'All interested students',
                    ],
                    'budget_allocations' => [
                        'source_of_fund' => 'Sponsorships',
                        'budget_cost' => 5000,
                    ],
                    'hardcopy_submitted' => true,
                    'hardcopy_submitted_at' => now()->subDays(1),
                    'head_organization_signed' => true,
                    'adviser_signed' => true,
                    'evaluation_open' => false,
                ]
            );

            EventDay::firstOrCreate(
                ['event_id' => $approvedEvent->id, 'day_number' => 1],
                [
                    'date' => Carbon::today()->addDays(5),
                    'start_time' => '09:00:00',
                    'end_time' => '15:00:00',
                ]
            );
        }
    }

    private function upsertUser(
        string $email,
        string $name,
        ?Organization $organization,
        ?College $college,
        ?Program $program,
        array $extra = []
    ): User {
        return User::updateOrCreate(
            ['email' => $email],
            array_merge([
                'name' => $name,
                'password' => Hash::make('password123'),
                'google_sub' => 'demo-' . str_replace(['@', '.'], '-', $email),
                'organization_id' => $organization?->id,
                'college_id' => $college?->id,
                'program_id' => $program?->id,
                'program_code' => $program?->code,
            ], $extra)
        );
    }

    private function ensureAdminAssignment(
        AdminAssignmentService $service,
        User $user,
        string $roleName,
        ?Organization $organization,
        ?College $college,
        string $academicYear,
        User $approvedBy,
        bool $isPrimaryAdmin = true
    ): void {
        if ($user->adminAssignments()
            ->where('role_name', $roleName)
            ->where('status', \App\Models\AdminAssignment::STATUS_ACTIVE)
            ->where('academic_year', $academicYear)
            ->exists()) {
            $service->syncUserProjection($user);
            return;
        }

        $service->createAssignment(
            user: $user,
            roleName: $roleName,
            organization: $organization,
            college: $college,
            academicYear: $academicYear,
            termStart: now()->toDateString(),
            termEnd: now()->addYear()->toDateString(),
            positionTitle: $roleName,
            approvedBy: $approvedBy,
            isPrimaryAdmin: $isPrimaryAdmin
        );
    }
}

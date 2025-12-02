<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // This migration updates client phone numbers, companies, and reps from the shoot history CSV
        // The data mapping is based on email matching
        
        $clientData = [
            'pics@listwithelizabeth.com' => [
                'phone' => '(202) 329-5432',
                'company' => '',
                'created_by' => 'Michael Sharp'
            ],
            'operations@radiusrealtynj.com' => [
                'phone' => '(908) 347-5119',
                'company' => 'Radius Realty Group LLC',
                'created_by' => 'Bill Hang'
            ],
            'johnpages@kw.com' => [
                'phone' => '(813) 992-5005',
                'company' => 'Keller Williams Tampa Properties',
                'created_by' => 'Bill Hang'
            ],
            'lisaqualben@gmail.com' => [
                'phone' => '(917) 710-1833',
                'company' => 'Weichert New Vernon',
                'created_by' => 'Bill Hang'
            ],
            'listings@justvybe.com' => [
                'phone' => '(410) 693-4242',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'mail.anshshah@gmail.com' => [
                'phone' => '(443) 678-9436',
                'company' => '',
                'created_by' => 'Super Admin'
            ],
            'Calidadhomesllc@gmail.com' => [
                'phone' => '(443) 244-7805',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'jan@headen4home.com' => [
                'phone' => '(410) 960-0020',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'dateamhomes@gmail.com' => [
                'phone' => '',
                'company' => '',
                'created_by' => ''
            ],
            'Dawn.medley@exprealty.com' => [
                'phone' => '(301) 704-8588',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'caterina.s.watson@gmail.com' => [
                'phone' => '(301) 758-9480',
                'company' => 'Weichert, Realtors',
                'created_by' => 'Bill Hang'
            ],
            '2025@myhouseangels.com' => [
                'phone' => '(240) 398-7875',
                'company' => '',
                'created_by' => 'Super Admin'
            ],
            'Shari@confidanteliving.com' => [
                'phone' => '(410) 903-4404',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'kate@c21nm.com' => [
                'phone' => '',
                'company' => 'Century 21 New Mellinium',
                'created_by' => 'Super Admin'
            ],
            'David@wagnerhomegroup.com' => [
                'phone' => '(301) 221-7342',
                'company' => 'Wagner Home Group of Re/Max Realty Centre',
                'created_by' => 'Michael Bereson'
            ],
            'Ba20850@gmail.com' => [
                'phone' => '(518) 821-1666',
                'company' => '',
                'created_by' => 'Super Admin'
            ],
            'David.Cadmore@randrealty.com' => [
                'phone' => '(407) 456-3165',
                'company' => 'Howard Hanna Rand Realty',
                'created_by' => 'Bill Hang'
            ],
            'Caitlin@cerensbergerbuilder.com' => [
                'phone' => '(443) 864-3843',
                'company' => 'CE Rensberger & Family Builder',
                'created_by' => 'Michael Bereson'
            ],
            'ajk@maxxum.org' => [
                'phone' => '(301) 310-1280',
                'company' => 'Maxxum Real Estate',
                'created_by' => 'Super Admin'
            ],
            'Lauren.shapiro@lnf.com' => [
                'phone' => '(410) 404-2044',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'cellickson@ttrsir.com' => [
                'phone' => '(703) 862-2135',
                'company' => 'Sotheby\'s International Realty',
                'created_by' => 'Bill Hang'
            ],
            'kathy@dixonkluge.com' => [
                'phone' => '(410) 707-7152',
                'company' => 'Blue Crab Real Estate',
                'created_by' => 'Michael Bereson'
            ],
            'Nancy@nancymiranda.com' => [
                'phone' => '(202) 276-1212',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'yvonnetlee@hotmail.com' => [
                'phone' => '(301) 613-6070',
                'company' => 'Keller Williams Capital Properties',
                'created_by' => 'Michael Bereson'
            ],
            'contact@reprophotos.com' => [
                'phone' => '(202) 868-1663',
                'company' => 'R/E Pro Photos House Account',
                'created_by' => 'Super Admin'
            ],
            'Dlgupta99@gmail.com' => [
                'phone' => '(443) 745-4401',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'jennifer@agmsolutions.com' => [
                'phone' => '(610) 337-8484',
                'company' => 'AG Marketing Solutions',
                'created_by' => 'Bill Hang'
            ],
            'tricia.himawan@gmail.com' => [
                'phone' => '(973) 650-7838',
                'company' => 'Weichert, Realtors',
                'created_by' => 'Bill Hang'
            ],
            'Kathy.ahrens@kw.com' => [
                'phone' => '(301) 910-1691',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            'Pabloes@psinternationalteam.com' => [
                'phone' => '(443) 813-7504',
                'company' => '',
                'created_by' => 'Michael Bereson'
            ],
            // Add more mappings as needed...
        ];

        foreach ($clientData as $email => $data) {
            $user = DB::table('users')->where('email', $email)->first();
            
            if ($user) {
                DB::table('users')
                    ->where('email', $email)
                    ->update([
                        'phonenumber' => $data['phone'] ?: $user->phonenumber,
                        'company_name' => $data['company'] ?: $user->company_name,
                        'created_by_name' => $data['created_by'],
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not reversible as we don't store the original values
    }
};

